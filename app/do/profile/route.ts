import { one, q } from '@/lib/db';
import { requireUser } from '@/lib/auth';
import { formAction, fail, flash, str } from '@/lib/http';
import { safeUrl } from '@/lib/format';

export const POST = formAction(async f => {
  const user = await requireUser();
  const name = str(f, 'name', 100);
  const phone = str(f, 'phone', 15).replace(/[^\d+]/g, '') || null;
  if (name.length < 2) fail('আপনার নাম লিখুন।', 'Please enter your name.');
  if (phone && !/^(\+?88)?01[3-9]\d{8}$/.test(phone)) fail('সঠিক মোবাইল নম্বর দিন (01XXXXXXXXX)।', 'Enter a valid mobile number (01XXXXXXXXX).');
  if (phone && await one`SELECT 1 FROM users WHERE phone = ${phone} AND id <> ${user.id}`) fail('এই নম্বরটি অন্য অ্যাকাউন্টে আছে।', 'This number belongs to another account.');
  await q`UPDATE users SET name = ${name}, phone = ${phone}, address = ${str(f, 'address', 500) || null},
          avatar = ${safeUrl(str(f, 'avatar', 500))}, bio = ${str(f, 'bio', 1000) || null}, updated_at = now()
          WHERE id = ${user.id}`;
  await flash('success', 'সেভ হয়েছে।', 'Saved.');
  return '/profile';
});
