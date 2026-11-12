// Sets a new password from a one-time reset link, then signs the user out everywhere.
import { q } from '@/lib/db';
import { formAction, flash, fail, rateLimit, str } from '@/lib/http';
import { hashPassword } from '@/lib/login';
import { findReset, useReset } from '@/lib/reset';

export const POST = formAction(async f => {
  await rateLimit('reset', 10, 600);
  const token = str(f, 'token', 80);
  const pw = String(f.get('password') ?? ''), confirm = String(f.get('confirm') ?? '');
  if (!(await findReset(token))) fail('লিংকটি আর কাজ করছে না। নতুন লিংকের জন্য অনুরোধ করুন।', 'This link no longer works. Please ask for a new one.');
  if (pw.length < 8) fail('পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে।', 'The password must be at least 8 characters.');
  if (pw !== confirm) fail('দুটি পাসওয়ার্ড মেলেনি।', 'The two passwords do not match.');
  const hash = await hashPassword(pw);
  const userId = await useReset(token); // atomic: a second click with the same link gets null
  if (!userId) fail('লিংকটি আর কাজ করছে না। নতুন লিংকের জন্য অনুরোধ করুন।', 'This link no longer works. Please ask for a new one.');
  await q`UPDATE users SET password_hash = ${hash}, updated_at = now() WHERE id = ${userId}`;
  await q`DELETE FROM user_sessions WHERE user_id = ${userId}`;
  await q`UPDATE password_requests SET status = 'done' WHERE user_id = ${userId} AND status = 'open'`;
  await flash('success', 'নতুন পাসওয়ার্ড সেট হয়েছে। এখন লগইন করুন।', 'Your new password is set. Please log in.');
  return '/login';
});
