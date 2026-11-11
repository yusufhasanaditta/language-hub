import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { currentUser, getState, activeSessions } from '@/lib/auth';
import { CODE_COOKIE, NEW_COOKIE, PENDING_COOKIE, type CodeState, type NewState, type PendingState } from '@/lib/login';
import { smtpConfigured } from '@/lib/mail';
import { setting } from '@/lib/settings';
import { safePath } from '@/lib/http';
import { fmtDateTime } from '@/lib/format';
import { T } from '@/lib/i18n';
import { AuthShell } from '@/components/AuthShell';
import { Icon } from '@/components/ui';

export const metadata: Metadata = { title: 'Log in', robots: { index: false } };
export const dynamic = 'force-dynamic';

type Props = { searchParams: Promise<{ next?: string; mode?: string }> };

export default async function LoginPage({ searchParams }: Props) {
  const sp = await searchParams;
  const next = safePath(sp.next, '/dashboard');
  if (await currentUser()) redirect(next);

  const [codeState, newState, pending] = await Promise.all([
    getState<CodeState>(CODE_COOKIE), getState<NewState>(NEW_COOKIE), getState<PendingState>(PENDING_COOKIE),
  ]);
  const devices = pending ? await activeSessions(pending.uid) : [];
  const emailCodes = smtpConfigured() || process.env.NODE_ENV !== 'production';
  const signupOpen = (await setting('registration_open', '1')) !== '0';
  const mode = sp.mode === 'code' && emailCodes ? 'code' : sp.mode === 'signup' && signupOpen ? 'signup' : 'password';
  const step = devices.length ? 'devices' : newState ? 'name' : codeState ? 'code' : mode;
  const hidden = <input type="hidden" name="next" value={next} />;
  const qs = (m: string) => `/login?mode=${m}${sp.next ? `&next=${encodeURIComponent(next)}` : ''}`;

  return (
    <AuthShell>
      <div className="lh-step" data-step={step}>
        {(step === 'password' || step === 'code' && !codeState || step === 'signup') && (
          <>
            <Icon name="waving_hand" className="lh-auth-ico" />
            <h1 className="lh-auth-title">{step === 'signup' ? <T bn="অ্যাকাউন্ট খুলুন" en="Create your account" /> : <T bn="শুরু করুন" en="Let’s get started" />}</h1>
            <nav className="lh-auth-tabs" aria-label="Login method">
              <a href={qs('password')} className={mode === 'password' ? 'is-on' : ''}><T bn="পাসওয়ার্ড" en="Password" /></a>
              {emailCodes
                ? <a href={qs('code')} className={mode === 'code' ? 'is-on' : ''}><T bn="ইমেইল কোড" en="Email code" /></a>
                : signupOpen && <a href={qs('signup')} className={mode === 'signup' ? 'is-on' : ''}><T bn="নতুন অ্যাকাউন্ট" en="Sign up" /></a>}
            </nav>
          </>
        )}

        {step === 'password' && (
          <>
            <form method="post" action="/do/login/password" className="lh-form">
              {hidden}
              <label htmlFor="email"><T bn="ইমেইল" en="Email" /></label>
              <input type="email" id="email" name="email" className="lh-input" autoComplete="username" placeholder="name@gmail.com" required autoFocus />
              <label htmlFor="password"><T bn="পাসওয়ার্ড" en="Password" /></label>
              <input type="password" id="password" name="password" className="lh-input" autoComplete="current-password" required />
              <button type="submit" className="btn btn-primary lh-btn-block" data-busy><T bn="লগইন" en="Log in" /> <Icon name="arrow_forward" /></button>
            </form>
            {emailCodes
              ? <p className="lh-auth-fine"><T bn="পাসওয়ার্ড নেই বা ভুলে গেছেন?" en="No password, or forgot it?" /> <a href={qs('code')}><T bn="ইমেইল কোড দিয়ে লগইন" en="Log in with an email code" /></a></p>
              : signupOpen && <p className="lh-auth-fine"><T bn="নতুন?" en="New here?" /> <a href={qs('signup')}><T bn="অ্যাকাউন্ট খুলুন" en="Create an account" /></a></p>}
          </>
        )}

        {step === 'signup' && (
          <form method="post" action="/do/login/signup" className="lh-form">
            {hidden}
            <label htmlFor="name"><T bn="পুরো নাম" en="Full name" /></label>
            <input id="name" name="name" className="lh-input" autoComplete="name" maxLength={100} required autoFocus />
            <label htmlFor="email"><T bn="ইমেইল" en="Email" /></label>
            <input type="email" id="email" name="email" className="lh-input" autoComplete="email" required />
            <label htmlFor="password"><T bn="পাসওয়ার্ড (কমপক্ষে ৮ অক্ষর)" en="Password (at least 8 characters)" /></label>
            <input type="password" id="password" name="password" className="lh-input" autoComplete="new-password" minLength={8} required />
            <button type="submit" className="btn btn-primary lh-btn-block" data-busy><T bn="অ্যাকাউন্ট খুলুন" en="Create account" /></button>
            <p className="lh-auth-fine"><T bn="চালিয়ে গেলে আপনি আমাদের" en="By continuing you agree to our" /> <a href="/terms"><T bn="শর্তাবলিতে" en="Terms" /></a> <T bn="সম্মত হচ্ছেন।" en="." /></p>
          </form>
        )}

        {step === 'code' && !codeState && (
          <form method="post" action="/do/login/send" className="lh-form">
            {hidden}
            <p className="lh-auth-sub"><T bn="ইমেইল দিন — আমরা একটি কোড পাঠাব। নতুন হলে অ্যাকাউন্ট নিজে থেকেই তৈরি হবে।" en="Enter your email and we’ll send you a code. New here? Your account is created automatically." /></p>
            <label htmlFor="email"><T bn="ইমেইল" en="Email" /></label>
            <input type="email" id="email" name="email" className="lh-input" inputMode="email" autoComplete="email" placeholder="name@gmail.com" required autoFocus />
            <button type="submit" className="btn btn-primary lh-btn-block" data-busy><T bn="কোড পাঠান" en="Send code" /> <Icon name="arrow_forward" /></button>
          </form>
        )}

        {step === 'code' && codeState && (
          <>
            <Icon name="mark_email_unread" className="lh-auth-ico" />
            <h1 className="lh-auth-title"><T bn="কোডটি দিন" en="Enter the code" /></h1>
            <p className="lh-auth-sub"><T bn="কোড পাঠানো হয়েছে:" en="We sent a code to" /> <strong>{codeState.email}</strong></p>
            {codeState.dev && <p className="lh-auth-dev"><T bn="লোকাল টেস্ট (ইমেইল সেটআপ হয়নি) — কোড:" en="Local test (email not set up) — code:" /> <b>{codeState.dev}</b></p>}
            <form method="post" action="/do/login/verify" className="lh-form" id="codeForm">
              {hidden}
              <label htmlFor="code" className="sr-only"><T bn="৬ সংখ্যার কোড" en="6-digit code" /></label>
              <div className="lh-code">
                <input type="text" id="code" name="code" inputMode="numeric" autoComplete="one-time-code" pattern="\d{6}" maxLength={6} required autoFocus />
                <div className="lh-code-boxes" aria-hidden="true">{[0, 1, 2, 3, 4, 5].map(i => <span key={i}></span>)}</div>
              </div>
              <button type="submit" className="btn btn-primary lh-btn-block" data-busy><T bn="যাচাই করুন" en="Verify" /></button>
            </form>
            <div className="lh-auth-row">
              <a href="/do/login/restart"><T bn="ইমেইল বদলান" en="Change email" /></a>
              <form method="post" action="/do/login/send">
                {hidden}<input type="hidden" name="resend" value="1" />
                <button type="submit" className="lh-linkbtn" id="resendBtn" data-wait="30"><T bn="আবার পাঠান" en="Resend code" /></button>
              </form>
            </div>
            <p className="lh-auth-fine"><T bn="ইমেইল না পেলে Spam/Promotions ফোল্ডার দেখুন।" en="Can’t find it? Check your Spam or Promotions folder." /></p>
          </>
        )}

        {step === 'name' && (
          <>
            <Icon name="badge" className="lh-auth-ico" />
            <h1 className="lh-auth-title"><T bn="আপনার নাম কী?" en="What’s your name?" /></h1>
            <p className="lh-auth-sub"><T bn="সার্টিফিকেটে এই নামটিই থাকবে।" en="This name will appear on your certificates." /></p>
            <form method="post" action="/do/login/name" className="lh-form">
              {hidden}
              <label htmlFor="name"><T bn="পুরো নাম" en="Full name" /></label>
              <input type="text" id="name" name="name" className="lh-input" autoComplete="name" maxLength={100} required autoFocus />
              <button type="submit" className="btn btn-primary lh-btn-block" data-busy><T bn="শুরু করুন" en="Start learning" /></button>
            </form>
          </>
        )}

        {step === 'devices' && (
          <>
            <Icon name="devices" className="lh-auth-ico" />
            <h1 className="lh-auth-title"><T bn="ডিভাইস লিমিট" en="Device limit reached" /></h1>
            <p className="lh-auth-sub"><T bn="একটি অ্যাকাউন্ট সর্বোচ্চ ২টি ডিভাইসে চালু থাকে। একটি থেকে লগআউট করুন।" en="An account can be signed in on 2 devices. Sign out of one to continue." /></p>
            <div className="lh-devices">
              {devices.map(d => (
                <form key={d.id} method="post" action="/do/login/kill" className="lh-device">
                  {hidden}
                  <input type="hidden" name="session_id" value={d.id} />
                  <Icon name="smartphone" />
                  <span className="lh-device-name">{d.device_name ?? 'Device'}<small>{fmtDateTime(d.last_seen_at)}</small></span>
                  <button type="submit" className="lh-btn-quiet"><T bn="লগআউট" en="Sign out" /></button>
                </form>
              ))}
            </div>
            <p className="lh-auth-alt"><a href="/do/login/restart"><T bn="অন্য অ্যাকাউন্টে লগইন" en="Use a different account" /></a></p>
          </>
        )}
      </div>
    </AuthShell>
  );
}
