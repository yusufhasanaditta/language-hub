import type { Metadata } from 'next';
import { T } from '@/lib/i18n';
import { findReset } from '@/lib/reset';
import { AuthShell } from '@/components/AuthShell';
import { Icon } from '@/components/ui';

export const metadata: Metadata = { title: 'নতুন পাসওয়ার্ড', robots: { index: false }, referrer: 'same-origin' };
export const dynamic = 'force-dynamic';

export default async function ResetPage({ searchParams }: { searchParams: Promise<{ token?: string }> }) {
  const token = String((await searchParams).token ?? '');
  const reset = await findReset(token);
  return (
    <AuthShell>
      <h1 className="lh-auth-title"><T bn="নতুন পাসওয়ার্ড সেট করুন" en="Set a new password" /></h1>
      {reset ? (
        <form method="post" action="/do/reset" className="lh-form">
          <p className="lh-auth-fine" style={{ marginTop: 0 }}><T bn={`অ্যাকাউন্ট: ${reset.name}`} en={`Account: ${reset.name}`} />{reset.email ? ` (${reset.email})` : ''}</p>
          <input type="hidden" name="token" value={token} />
          <label htmlFor="password"><T bn="নতুন পাসওয়ার্ড (কমপক্ষে ৮ অক্ষর)" en="New password (at least 8 characters)" /></label>
          <input type="password" id="password" name="password" className="lh-input" autoComplete="new-password" minLength={8} required autoFocus />
          <label htmlFor="confirm"><T bn="আবার লিখুন" en="Repeat new password" /></label>
          <input type="password" id="confirm" name="confirm" className="lh-input" autoComplete="new-password" minLength={8} required />
          <button type="submit" className="btn btn-primary lh-btn-block" data-busy><T bn="পাসওয়ার্ড সেভ করুন" en="Save password" /> <Icon name="lock" /></button>
        </form>
      ) : (
        <div className="lh-forgot-sent" role="alert">
          <span className="lh-forgot-ico is-bad"><Icon name="link_off" /></span>
          <p><T bn="এই লিংকটি আর কাজ করছে না — হয়তো মেয়াদ (২৪ ঘণ্টা) শেষ বা আগেই ব্যবহার হয়েছে।" en="This link no longer works — it may have expired (24 hours) or was already used." /></p>
          <a href="/forgot" className="btn btn-primary lh-btn-block"><T bn="নতুন লিংক চান" en="Ask for a new link" /></a>
        </div>
      )}
    </AuthShell>
  );
}
