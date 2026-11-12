// "Forgot password" request → stored for the admins (Admin → Password help) and announced to them.
// The reply never says whether an account exists for the given email/phone.
import { one, q } from '@/lib/db';
import { formAction, flash, fail, rateLimit, str, isEmail } from '@/lib/http';

export const POST = formAction(async f => {
  if (str(f, 'website')) return '/forgot?sent=1'; // honeypot: silently drop bots
  await rateLimit('forgot', 5, 900);
  const contact = str(f, 'contact', 190).toLowerCase();
  const email = isEmail(contact);
  const digits = email ? '' : contact.replace(/\D/g, ''); // phone match only for phone input
  if (!email && digits.length < 10) fail('আপনার ইমেইল বা মোবাইল নম্বর সঠিকভাবে লিখুন।', 'Enter your email or mobile number.');
  const name = str(f, 'name', 150), note = str(f, 'note', 500);
  const user = email
    ? await one<{ id: number }>`SELECT id FROM users WHERE lower(email) = ${contact} ORDER BY id LIMIT 1`
    : await one<{ id: number }>`SELECT id FROM users
        WHERE right(regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g'), 10) = right(${digits}, 10) ORDER BY id LIMIT 1`;
  const req = await one<{ id: number }>`INSERT INTO password_requests (user_id, contact, name, note)
    VALUES (${user?.id ?? null}, ${contact}, ${name || null}, ${note || null}) RETURNING id`;
  // Tell every admin in one statement.
  await q`INSERT INTO notifications (user_id, kind, title, body, action_url)
          SELECT id, 'system', ${`Password help: ${name || contact}`}, ${contact}, ${`/admin/password-help#r${req!.id}`}
          FROM users WHERE role_id IN (5, 6) AND is_active`;
  await flash('success', 'অনুরোধ পাঠানো হয়েছে।', 'Request sent.');
  return '/forgot?sent=1';
});
