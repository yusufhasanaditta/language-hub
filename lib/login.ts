// Shared state for the multi-step login screen (kept in short-lived signed cookies).
import { createHmac, randomInt } from 'node:crypto';
import bcrypt from 'bcryptjs';
import { one, q } from './db';
import { sign } from './auth';

export const CODE_COOKIE = 'lh_login_code';   // { email, dev? }  — code sent, waiting for it
export const NEW_COOKIE = 'lh_login_new';     // { email }        — email verified, new account needs a name
export const PENDING_COOKIE = 'lh_login_dev'; // { uid }          — device limit reached
export const NEXT_COOKIE = 'lh_login_next';   // where to go after login

export type CodeState = { email: string; dev?: string; sent: number };
export type NewState = { email: string };
export type PendingState = { uid: number };

export const CODE_TTL_MIN = 10;
export const MAX_CODE_TRIES = 5;

const codeHash = (email: string, code: string) =>
  createHmac('sha256', process.env.SESSION_SECRET || 'dev').update(`${email}:${code}`).digest('hex');

/** Creates a 6-digit code for this email (replacing older ones) and returns it. */
export async function issueCode(email: string) {
  const code = String(randomInt(0, 1_000_000)).padStart(6, '0');
  await q`DELETE FROM email_codes WHERE email = ${email} OR expires_at < now()`;
  await q`INSERT INTO email_codes (email, code_hash, expires_at)
          VALUES (${email}, ${codeHash(email, code)}, now() + make_interval(mins => ${CODE_TTL_MIN}))`;
  return code;
}

export async function checkCode(email: string, code: string): Promise<'ok' | 'wrong' | 'expired' | 'locked'> {
  const row = await one<{ id: number; code_hash: string; tries: number; expired: boolean }>`
    SELECT id, code_hash, tries, expires_at < now() AS expired FROM email_codes
    WHERE email = ${email} ORDER BY id DESC LIMIT 1`;
  if (!row || row.expired) return 'expired';
  if (row.tries >= MAX_CODE_TRIES) return 'locked';
  if (!/^\d{6}$/.test(code) || codeHash(email, code) !== row.code_hash) {
    await q`UPDATE email_codes SET tries = tries + 1 WHERE id = ${row.id}`;
    return row.tries + 1 >= MAX_CODE_TRIES ? 'locked' : 'wrong';
  }
  await q`DELETE FROM email_codes WHERE email = ${email}`;
  return 'ok';
}

export const hashPassword = (p: string) => bcrypt.hash(p, 12);

/** Compares against a stored hash; PHP's $2y$ bcrypt hashes are accepted too. */
export async function verifyPassword(p: string, hash: string | null) {
  if (!hash) {
    await bcrypt.hash(p, 12); // same timing whether or not the account exists
    return false;
  }
  return bcrypt.compare(p, hash.replace(/^\$2y\$/, '$2b$'));
}

export const signNext = (next: string) => sign({ next }, 900);
