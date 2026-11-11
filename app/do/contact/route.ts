import { q } from '@/lib/db';
import { formAction, flash, fail, rateLimit, str, isEmail } from '@/lib/http';

export const POST = formAction(async f => {
  if (str(f, 'website')) return '/contact'; // honeypot: silently drop bots
  await rateLimit('contact', 5, 600);
  const name = str(f, 'name', 150), email = str(f, 'email', 190).toLowerCase(), message = str(f, 'message', 5000);
  if (name.length < 2) fail('আপনার নাম লিখুন।', 'Please enter your name.');
  if (!isEmail(email)) fail('সঠিক ইমেইল দিন।', 'Enter a valid email address.');
  if (message.length < 5) fail('বার্তাটি লিখুন।', 'Please write your message.');
  await q`INSERT INTO contact_messages (name, email, phone, subject, message)
          VALUES (${name}, ${email}, ${str(f, 'phone', 50) || null}, ${str(f, 'subject', 200) || null}, ${message})`;
  await flash('success', 'ধন্যবাদ! আপনার বার্তা পেয়েছি।', 'Thank you! We received your message.');
  return '/contact';
});
