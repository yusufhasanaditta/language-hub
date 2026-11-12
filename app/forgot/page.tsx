import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { currentUser } from '@/lib/auth';
import { setting } from '@/lib/settings';
import { T } from '@/lib/i18n';
import { AuthShell } from '@/components/AuthShell';
import { Icon } from '@/components/ui';

export const metadata: Metadata = { title: 'পাসওয়ার্ড ভুলে গেছেন', robots: { index: false } };
export const dynamic = 'force-dynamic';

export default async function ForgotPage({ searchParams }: { searchParams: Promise<{ sent?: string }> }) {
  if (await currentUser()) redirect('/profile#password');
  const sent = (await searchParams).sent === '1';
  const phone = await setting('contact_phone');
  return (
    <AuthShell>
      <h1 className="lh-auth-title"><T bn="পাসওয়ার্ড ভুলে গেছেন?" en="Forgot your password?" /></h1>
      {sent ? (
        <div className="lh-forgot-sent" role="status">
          <span className="lh-forgot-ico"><Icon name="mark_email_read" /></span>
          <p><T bn="আপনার অনুরোধ আমাদের কাছে পৌঁছেছে। অ্যাডমিন আপনার ইমেইল/মোবাইল যাচাই করে একটি নতুন পাসওয়ার্ড সেট করার লিংক পাঠাবেন (সাধারণত কয়েক ঘণ্টার মধ্যে)।"
            en="We received your request. An admin will check your email/phone and send you a link to set a new password (usually within a few hours)." /></p>
          {phone && <p><T bn="জরুরি হলে কল করুন:" en="In a hurry? Call us:" /> <a href={`tel:${phone.replace(/[^0-9+]/g, '')}`} className="lh-link">{phone}</a></p>}
          <a href="/login" className="btn btn-outline lh-btn-block"><Icon name="arrow_back" /><T bn="লগইনে ফিরুন" en="Back to log in" /></a>
        </div>
      ) : (
        <>
          <p className="lh-auth-fine" style={{ marginTop: 0 }}>
            <T bn="চিন্তা নেই! আপনার তথ্য দিন — অ্যাডমিন যাচাই করে আপনাকে নতুন পাসওয়ার্ড সেট করার একটি লিংক পাঠাবেন।"
              en="No problem! Tell us who you are — an admin will check it and send you a link to set a new password." />
          </p>
          <form method="post" action="/do/forgot" className="lh-form">
            <label htmlFor="contact"><T bn="অ্যাকাউন্টের ইমেইল বা মোবাইল নম্বর" en="Your account email or mobile number" /></label>
            <input id="contact" name="contact" className="lh-input" required maxLength={190} autoComplete="username" placeholder="name@gmail.com / 01XXXXXXXXX" autoFocus />
            <label htmlFor="name"><T bn="আপনার নাম" en="Your name" /></label>
            <input id="name" name="name" className="lh-input" required maxLength={150} autoComplete="name" />
            <label htmlFor="note"><T bn="কোথায় যোগাযোগ করব? (WhatsApp নম্বর ইত্যাদি, ঐচ্ছিক)" en="How should we reach you? (WhatsApp number etc., optional)" /></label>
            <input id="note" name="note" className="lh-input" maxLength={500} />
            <input type="text" name="website" tabIndex={-1} autoComplete="off" className="sr-only" aria-hidden="true" />
            <button type="submit" className="btn btn-primary lh-btn-block" data-busy><T bn="অনুরোধ পাঠান" en="Send request" /> <Icon name="send" /></button>
          </form>
          <p className="lh-auth-fine"><a href="/login"><T bn="← লগইনে ফিরুন" en="← Back to log in" /></a></p>
        </>
      )}
    </AuthShell>
  );
}
