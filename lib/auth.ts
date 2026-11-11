// Sessions: the browser holds a random token in an httpOnly cookie; the database
// stores only its SHA-256 hash in user_sessions (one row per signed-in device).
import { cookies, headers } from 'next/headers';
import { redirect } from 'next/navigation';
import { cache } from 'react';
import { createHash, createHmac, randomBytes, timingSafeEqual } from 'node:crypto';
import { one, q } from './db';

export const ROLE = { STUDENT: 1, TEACHER: 2, TA: 3, MODERATOR: 4, OPS: 5, ADMIN: 6 } as const;
export const ROLE_NAMES: Record<number, string> = { 1: 'student', 2: 'teacher', 3: 'ta', 4: 'moderator', 5: 'ops', 6: 'admin' };
export const MAX_DEVICES = 2;

const SESSION_COOKIE = 'lh_session';
const SESSION_DAYS = 30;

export type User = {
  id: number; name: string; email: string | null; phone: string | null; username: string | null;
  role_id: number; avatar: string | null; bio: string | null; address: string | null;
  has_password: boolean; session_id: number;
};

const sha256 = (s: string) => createHash('sha256').update(s).digest('hex');

/** The signed-in user for this request (null for guests). Cached per request. */
export const currentUser = cache(async (): Promise<User | null> => {
  const token = (await cookies()).get(SESSION_COOKIE)?.value;
  if (!token || token.length > 100) return null;
  const u = await one<User & { last_seen_at: string }>`
    SELECT u.id, u.name, u.email, u.phone, u.username, u.role_id, u.avatar, u.bio, u.address,
           (u.password_hash IS NOT NULL) AS has_password, s.id AS session_id, s.last_seen_at
    FROM user_sessions s JOIN users u ON u.id = s.user_id
    WHERE s.token_hash = ${sha256(token)} AND u.is_active
      AND s.last_seen_at > now() - make_interval(days => ${SESSION_DAYS})`;
  if (!u) return null;
  if (Date.now() - new Date(u.last_seen_at).getTime() > 5 * 60_000) {
    await q`UPDATE user_sessions SET last_seen_at = now() WHERE id = ${u.session_id}`;
  }
  return u;
});

export const isAdmin = (u: { role_id: number } | null) => !!u && (u.role_id === ROLE.ADMIN || u.role_id === ROLE.OPS);
export const isTeacher = (u: { role_id: number } | null) => !!u && (u.role_id === ROLE.TEACHER || u.role_id === ROLE.ADMIN);

/** Current path + query (set by proxy.ts), for ?next= redirects. */
export async function currentUrl() {
  return (await headers()).get('x-url') ?? '/';
}

export async function requireUser(): Promise<User> {
  const u = await currentUser();
  if (!u) redirect('/login?next=' + encodeURIComponent(await currentUrl()));
  return u;
}

export async function requireAdmin(): Promise<User> {
  const u = await requireUser();
  if (!isAdmin(u)) redirect('/denied');
  return u;
}

export async function requireTeacher(): Promise<User> {
  const u = await requireUser();
  if (!isTeacher(u)) redirect('/denied');
  return u;
}

// ── Devices ──────────────────────────────────────────────────────────────────

export async function clientIp() {
  const h = await headers();
  return (h.get('x-forwarded-for')?.split(',')[0] ?? h.get('x-real-ip') ?? '0.0.0.0').trim().slice(0, 45);
}

export async function deviceName() {
  const ua = (await headers()).get('user-agent') ?? '';
  const os = /iPhone/i.test(ua) ? 'iPhone' : /iPad/i.test(ua) ? 'iPad' : /Android/i.test(ua) ? 'Android'
    : /Windows/i.test(ua) ? 'Windows' : /Mac OS X/i.test(ua) ? 'Mac' : /Linux/i.test(ua) ? 'Linux' : 'Other';
  const br = /Edg\//i.test(ua) ? 'Edge' : /Chrome/i.test(ua) ? 'Chrome' : /Firefox/i.test(ua) ? 'Firefox'
    : /Safari/i.test(ua) ? 'Safari' : 'Browser';
  return `${br} · ${os}`;
}

export async function activeSessions(userId: number) {
  await q`DELETE FROM user_sessions WHERE user_id = ${userId}
          AND last_seen_at < now() - make_interval(days => ${SESSION_DAYS})`;
  return q<{ id: number; device_name: string; ip: string; created_at: string; last_seen_at: string }>`
    SELECT id, device_name, ip, created_at, last_seen_at FROM user_sessions
    WHERE user_id = ${userId} ORDER BY last_seen_at DESC`;
}

/**
 * Sign the user in on this device. Returns 'limit' when the account is already
 * on MAX_DEVICES other devices (the caller then asks which one to sign out).
 */
export async function startSession(userId: number): Promise<'ok' | 'limit'> {
  const device = await deviceName();
  // Re-logging in on the same browser replaces its old row instead of using a slot.
  await q`DELETE FROM user_sessions WHERE user_id = ${userId} AND device_name = ${device}`;
  if ((await activeSessions(userId)).length >= MAX_DEVICES) return 'limit';

  const token = randomBytes(32).toString('base64url');
  await q`INSERT INTO user_sessions (user_id, token_hash, device_name, ip)
          VALUES (${userId}, ${sha256(token)}, ${device}, ${await clientIp()})`;
  await q`UPDATE users SET last_login = now(), is_verified = true WHERE id = ${userId}`;
  (await cookies()).set(SESSION_COOKIE, token, {
    httpOnly: true, sameSite: 'lax', path: '/', maxAge: SESSION_DAYS * 86400,
    secure: process.env.NODE_ENV === 'production',
  });
  return 'ok';
}

export async function endSession() {
  const jar = await cookies();
  const token = jar.get(SESSION_COOKIE)?.value;
  if (token) await q`DELETE FROM user_sessions WHERE token_hash = ${sha256(token)}`;
  jar.delete(SESSION_COOKIE);
}

// ── Signed short-lived state (login steps) ──────────────────────────────────

function secret() {
  const s = process.env.SESSION_SECRET;
  if (s && s.length >= 16) return s;
  if (process.env.NODE_ENV === 'production') throw new Error('SESSION_SECRET is not set.');
  return sha256('dev:' + (process.env.DATABASE_URL ?? 'local'));
}

export function sign(data: object, ttlSeconds: number) {
  const body = Buffer.from(JSON.stringify({ ...data, exp: Date.now() + ttlSeconds * 1000 })).toString('base64url');
  return body + '.' + createHmac('sha256', secret()).update(body).digest('base64url');
}

export function unsign<T>(value: string | undefined): T | null {
  if (!value) return null;
  const [body, mac] = value.split('.');
  if (!body || !mac) return null;
  const expect = createHmac('sha256', secret()).update(body).digest();
  const got = Buffer.from(mac, 'base64url');
  if (got.length !== expect.length || !timingSafeEqual(got, expect)) return null;
  const data = JSON.parse(Buffer.from(body, 'base64url').toString()) as T & { exp: number };
  return data.exp > Date.now() ? data : null;
}

export async function setState(name: string, data: object, ttlSeconds: number) {
  (await cookies()).set(name, sign(data, ttlSeconds), {
    httpOnly: true, sameSite: 'lax', path: '/', maxAge: ttlSeconds,
    secure: process.env.NODE_ENV === 'production',
  });
}

export async function getState<T>(name: string) {
  return unsign<T>((await cookies()).get(name)?.value);
}

export async function clearState(...names: string[]) {
  const jar = await cookies();
  for (const n of names) jar.delete(n);
}
