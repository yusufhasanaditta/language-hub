import { one, q } from '@/lib/db';
import { requireUser } from '@/lib/auth';
import { formAction, fail, flash } from '@/lib/http';
import { hashPassword, verifyPassword } from '@/lib/login';

export const POST = formAction(async f => {
  const user = await requireUser();
  const pw = String(f.get('password') ?? ''), confirm = String(f.get('confirm') ?? '');
  if (pw.length < 8) fail('পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে।', 'Password must be at least 8 characters.');
  if (pw !== confirm) fail('দুটি পাসওয়ার্ড মেলেনি।', 'The two passwords do not match.');
  const row = await one<{ password_hash: string | null }>`SELECT password_hash FROM users WHERE id = ${user.id}`;
  if (row?.password_hash && !(await verifyPassword(String(f.get('current') ?? ''), row.password_hash))) {
    fail('বর্তমান পাসওয়ার্ড সঠিক নয়।', 'Your current password is wrong.');
  }
  await q`UPDATE users SET password_hash = ${await hashPassword(pw)}, updated_at = now() WHERE id = ${user.id}`;
  // Sign out every other device after a password change.
  await q`DELETE FROM user_sessions WHERE user_id = ${user.id} AND id <> ${user.session_id}`;
  await flash('success', 'পাসওয়ার্ড সেভ হয়েছে। অন্য ডিভাইস থেকে লগআউট করা হয়েছে।', 'Password saved. Other devices were signed out.');
  return '/profile#password';
});
