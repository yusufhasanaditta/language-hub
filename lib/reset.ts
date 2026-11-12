// One-time password reset links, created by an admin for a learner who forgot their password.
// Only a SHA-256 of the token is stored; the link itself is shown to the admin once.
import { createHash, randomBytes } from 'node:crypto';
import { cookies, headers } from 'next/headers';
import { one, q } from './db';

export const RESET_HOURS = 24;
const sha = (t: string) => createHash('sha256').update(t).digest('hex');

/** New reset token for a user; any older unused links for that user stop working. */
export async function createReset(userId: number, adminId: number) {
  const token = randomBytes(24).toString('base64url');
  await q`UPDATE password_resets SET used_at = now() WHERE user_id = ${userId} AND used_at IS NULL`;
  await q`INSERT INTO password_resets (user_id, token_hash, created_by, expires_at)
          VALUES (${userId}, ${sha(token)}, ${adminId}, now() + make_interval(hours => ${RESET_HOURS}))`;
  return token;
}

/** The still-valid reset for a token (null if unknown, used or expired). */
export function findReset(token: string) {
  if (!/^[A-Za-z0-9_-]{20,64}$/.test(token)) return Promise.resolve(null);
  return one<{ id: number; user_id: number; name: string; email: string | null; expires_at: string }>`
    SELECT r.id, r.user_id, u.name, u.email, r.expires_at FROM password_resets r JOIN users u ON u.id = r.user_id
    WHERE r.token_hash = ${sha(token)} AND r.used_at IS NULL AND r.expires_at > now() AND u.is_active`;
}

/** Marks the link used (atomically, so it works once). Returns the user id, or null. */
export async function useReset(token: string) {
  const r = await one<{ user_id: number }>`UPDATE password_resets SET used_at = now()
    WHERE token_hash = ${sha(token)} AND used_at IS NULL AND expires_at > now() RETURNING user_id`;
  return r?.user_id ?? null;
}

/**
 * https://site/reset?token=… — from SITE_URL when set (recommended on any host), otherwise
 * from the request's own Host header (validated) rather than client-controllable forwarding headers.
 */
export async function resetUrl(token: string) {
  const fixed = process.env.SITE_URL?.trim().replace(/\/+$/, '');
  if (fixed && /^https?:\/\/[a-z0-9.-]+(:\d+)?$/i.test(fixed)) return `${fixed}/reset?token=${token}`;
  const h = await headers();
  const host = (h.get('host') ?? '').trim();
  const safeHost = /^[a-z0-9.-]+(:\d{1,5})?$/i.test(host) ? host : 'localhost:3000';
  const local = /^(localhost|127\.0\.0\.1)(:|$)/.test(safeHost);
  const fwd = (h.get('x-forwarded-proto') ?? '').split(',')[0].trim();
  const proto = local ? 'http' : fwd === 'http' || fwd === 'https' ? fwd : 'https';
  return `${proto}://${safeHost}/reset?token=${token}`;
}

const MADE_COOKIE = 'lh_reset_made';
const MADE_PATH = '/admin/password-help';

/** Hands a freshly made link to the admin page for a few minutes (httpOnly, that page only). */
export async function showResetOnce(token: string, userId: number) {
  (await cookies()).set(MADE_COOKIE, `${userId}.${token}`, {
    httpOnly: true, sameSite: 'strict', secure: process.env.NODE_ENV === 'production', path: MADE_PATH, maxAge: 300,
  });
}

/** The link handed over by showResetOnce, if it is still valid. */
export async function readShownReset() {
  const v = (await cookies()).get(MADE_COOKIE)?.value ?? '';
  const m = v.match(/^(\d+)\.([A-Za-z0-9_-]{20,64})$/);
  if (!m) return null;
  const reset = await findReset(m[2]);
  return reset && reset.user_id === Number(m[1]) ? { ...reset, url: await resetUrl(m[2]) } : null;
}
