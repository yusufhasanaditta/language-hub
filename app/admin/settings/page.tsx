import { requireAdmin } from '@/lib/auth';
import { getSettings, EDITABLE_SETTINGS } from '@/lib/settings';
import { smtpConfigured } from '@/lib/mail';
import { sslczConfigured } from '@/lib/sslcommerz';
import { PageHead } from '@/components/panel';

export const metadata = { title: 'Settings' };

export default async function SettingsPage() {
  await requireAdmin();
  const s = await getSettings();
  return (
    <>
      <PageHead title="Settings" sub="Site details shown to visitors." />
      <div className="pn-card">
        <form method="post" action="/do/admin/settings" className="pn-grid">
          {EDITABLE_SETTINGS.map(([key, label, type]) => (
            <div key={key} className={type === 'textarea' ? 'pn-full' : ''}>
              <label htmlFor={key}>{label}</label>
              {type === 'textarea'
                ? <textarea id={key} name={key} className="form-control" maxLength={2000} defaultValue={s[key] ?? ''}></textarea>
                : <input id={key} name={key} type={type} className="form-control" maxLength={500} defaultValue={s[key] ?? ''} />}
            </div>
          ))}
          <div className="pn-full"><button type="submit" className="btn btn-primary">Save settings</button></div>
        </form>
      </div>
      <div className="pn-card">
        <h2>Server configuration</h2>
        <p className="pn-muted">These are set as environment variables in Vercel (Project → Settings → Environment Variables), not here, so secrets never sit in the database.</p>
        <ul>
          <li>Email login codes (SMTP_HOST, SMTP_USER, SMTP_PASS): <b>{smtpConfigured() ? 'configured' : 'not set — students log in with a password'}</b></li>
          <li>SSLCommerz online payments (SSLCZ_STORE_ID, SSLCZ_STORE_PASS, SSLCZ_LIVE): <b>{sslczConfigured() ? `configured (${process.env.SSLCZ_LIVE === '1' ? 'live' : 'sandbox'})` : 'not set — checkout uses manual bKash/Nagad'}</b></li>
        </ul>
      </div>
    </>
  );
}
