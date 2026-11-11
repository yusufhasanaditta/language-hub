// Login / sign-up steps. Each POST ends with a redirect back to /login (next step) or to `next`.
import type { NextRequest } from 'next/server';
import { one, q } from '@/lib/db';
import { startSession, setState, getState, clearState, ROLE } from '@/lib/auth';
import { formAction, fail, flash, rateLimit, str, int, isEmail, safePath, sameOrigin, seeOther } from '@/lib/http';
import {
  CODE_COOKIE, NEW_COOKIE, PENDING_COOKIE, CODE_TTL_MIN,
  type CodeState, type NewState, type PendingState,
  issueCode, checkCode, hashPassword, verifyPassword,
} from '@/lib/login';
import { sendMail, smtpConfigured } from '@/lib/mail';
import { setting } from '@/lib/settings';

const ALL_STATES = [CODE_COOKIE, NEW_COOKIE, PENDING_COOKIE];
const nextOf = (f: FormData) => safePath(f.get('next'), '/dashboard');
const loginWith = (f: FormData) => `/login?next=${encodeURIComponent(nextOf(f))}`;

/** Sign in, or park the user on the "choose a device to sign out" step. */
async function finish(userId: number, f: FormData) {
  const r = await startSession(userId);
  if (r === 'limit') {
    await clearState(CODE_COOKIE, NEW_COOKIE);
    await setState(PENDING_COOKIE, { uid: userId } satisfies PendingState, 300);
    return loginWith(f);
  }
  await clearState(...ALL_STATES);
  return nextOf(f);
}

const handlers: Record<string, (f: FormData) => Promise<string>> = {
  async password(f) {
    await rateLimit('login_pw', 10, 300);
    const email = str(f, 'email', 150).toLowerCase();
    const user = await one<{ id: number; password_hash: string | null; is_active: boolean }>`
      SELECT id, password_hash, is_active FROM users WHERE lower(email) = ${email}`;
    const ok = await verifyPassword(String(f.get('password') ?? ''), user?.password_hash ?? null);
    if (!user || !ok) fail('ইমেইল বা পাসওয়ার্ড সঠিক নয়।', 'Wrong email or password.');
    if (!user!.is_active) fail('এই অ্যাকাউন্টটি বন্ধ আছে। আমাদের সাথে যোগাযোগ করুন।', 'This account is disabled. Please contact us.');
    return finish(user!.id, f);
  },

  async signup(f) {
    if ((await setting('registration_open', '1')) === '0') fail('এখন নতুন অ্যাকাউন্ট খোলা বন্ধ।', 'Sign-ups are closed right now.');
    await rateLimit('signup', 5, 600);
    const name = str(f, 'name', 100), email = str(f, 'email', 150).toLowerCase(), pw = String(f.get('password') ?? '');
    if (name.length < 2) fail('আপনার নাম লিখুন।', 'Please enter your name.');
    if (!isEmail(email)) fail('সঠিক ইমেইল দিন।', 'Enter a valid email address.');
    if (pw.length < 8) fail('পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে।', 'Password must be at least 8 characters.');
    if (await one`SELECT 1 FROM users WHERE lower(email) = ${email}`) {
      fail('এই ইমেইলে আগেই অ্যাকাউন্ট আছে — লগইন করুন।', 'An account with this email already exists — please log in.');
    }
    const u = await one<{ id: number }>`INSERT INTO users (name, email, password_hash, role_id, is_verified)
      VALUES (${name}, ${email}, ${await hashPassword(pw)}, ${ROLE.STUDENT}, false) RETURNING id`;
    await flash('success', `স্বাগতম, ${name}!`, `Welcome, ${name}!`);
    return finish(u!.id, f);
  },

  async send(f) {
    const prev = await getState<CodeState>(CODE_COOKIE);
    const email = f.get('resend') ? prev?.email ?? '' : str(f, 'email', 150).toLowerCase();
    if (!isEmail(email)) fail('সঠিক ইমেইল দিন।', 'Enter a valid email address.');
    if (f.get('resend') && prev && Date.now() - prev.sent < 30_000) fail('একটু অপেক্ষা করে আবার চেষ্টা করুন।', 'Please wait a few seconds before resending.');
    await rateLimit('email_code', 5, 600);
    const devMode = process.env.NODE_ENV !== 'production' && !smtpConfigured();
    if (!smtpConfigured() && !devMode) fail('ইমেইল কোড এখন চালু নেই — পাসওয়ার্ড দিয়ে লগইন করুন।', 'Email codes are not available right now — please log in with your password.');
    const code = await issueCode(email);
    if (!devMode) {
      const sent = await sendMail(email, `${code} — Language Hub login code`,
        `<div style="font-family:Arial,sans-serif;max-width:420px;margin:auto;padding:24px;color:#1A1033">
         <h2 style="margin:0 0 8px;color:#5B21B6">Language Hub</h2><p>আপনার লগইন কোড / Your login code:</p>
         <p style="font-size:32px;letter-spacing:8px;font-weight:700">${code}</p>
         <p style="color:#6E6684;font-size:13px">${CODE_TTL_MIN} মিনিটের মধ্যে ব্যবহার করুন। Valid for ${CODE_TTL_MIN} minutes. Never share this code.</p></div>`);
      if (!sent) fail('কোড পাঠানো যায়নি। কিছুক্ষণ পর আবার চেষ্টা করুন।', 'We could not send the code. Please try again shortly.');
    }
    await setState(CODE_COOKIE, { email, sent: Date.now(), ...(devMode ? { dev: code } : {}) } satisfies CodeState, CODE_TTL_MIN * 60);
    return loginWith(f) + '&mode=code';
  },

  async verify(f) {
    await rateLimit('email_code_verify', 15, 300);
    const st = await getState<CodeState>(CODE_COOKIE);
    if (!st) fail('কোডের মেয়াদ শেষ। নতুন কোড নিন।', 'The code expired. Please request a new one.');
    const res = await checkCode(st!.email, str(f, 'code', 10).replace(/\D/g, ''));
    if (res === 'wrong') fail('কোডটি সঠিক নয়।', 'That code is not right.');
    if (res !== 'ok') {
      await clearState(CODE_COOKIE);
      fail(res === 'locked' ? 'অনেকবার ভুল হয়েছে। নতুন কোড নিন।' : 'কোডের মেয়াদ শেষ। নতুন কোড নিন।',
           res === 'locked' ? 'Too many tries. Please request a new code.' : 'The code expired. Please request a new one.');
    }
    const user = await one<{ id: number; is_active: boolean }>`SELECT id, is_active FROM users WHERE lower(email) = ${st!.email}`;
    await clearState(CODE_COOKIE);
    if (user && !user.is_active) fail('এই অ্যাকাউন্টটি বন্ধ আছে। আমাদের সাথে যোগাযোগ করুন।', 'This account is disabled. Please contact us.');
    if (user) return finish(user.id, f);
    await setState(NEW_COOKIE, { email: st!.email } satisfies NewState, 900);
    return loginWith(f);
  },

  async name(f) {
    const st = await getState<NewState>(NEW_COOKIE);
    if (!st) fail('সময় শেষ হয়ে গেছে। আবার শুরু করুন।', 'This took too long. Please start again.');
    const name = str(f, 'name', 100);
    if (name.length < 2) fail('আপনার নাম লিখুন।', 'Please enter your name.');
    const u = await one<{ id: number }>`
      INSERT INTO users (name, email, role_id, is_verified) VALUES (${name}, ${st!.email}, ${ROLE.STUDENT}, true)
      ON CONFLICT (email) DO UPDATE SET is_verified = true RETURNING id`;
    await flash('success', `স্বাগতম, ${name}!`, `Welcome, ${name}!`);
    return finish(u!.id, f);
  },

  async kill(f) {
    const st = await getState<PendingState>(PENDING_COOKIE);
    if (!st) return '/login';
    await q`DELETE FROM user_sessions WHERE id = ${int(f, 'session_id')} AND user_id = ${st.uid}`;
    const user = await one<{ id: number }>`SELECT id FROM users WHERE id = ${st.uid} AND is_active`;
    if (!user) { await clearState(PENDING_COOKIE); return '/login'; }
    return finish(user.id, f);
  },
};

export const POST = async (req: NextRequest, ctx: { params: Promise<{ action: string }> }) => {
  const h = handlers[(await ctx.params).action];
  if (!h) return new Response('Not found', { status: 404 });
  return formAction(h)(req);
};

/** "Change email" / "Use a different account": forget the in-progress login. */
export const GET = async (req: NextRequest, ctx: { params: Promise<{ action: string }> }) => {
  if ((await ctx.params).action !== 'restart' || !sameOrigin(req)) return seeOther(req, '/login');
  await clearState(...ALL_STATES);
  return seeOther(req, '/login');
};
