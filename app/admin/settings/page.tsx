import { requireAdmin } from '@/lib/auth';
import { one } from '@/lib/db';
import { getSettings, EDITABLE_SETTINGS } from '@/lib/settings';
import { smtpConfigured } from '@/lib/mail';
import { sslczConfig, SSLCZ_KEYS } from '@/lib/sslcommerz';
import { siteUrl } from '@/lib/http';
import { ROLE } from '@/lib/auth';
import { PageHead, ActionButton } from '@/components/panel';

export const metadata = { title: 'Settings' };

export default async function SettingsPage() {
  const me = await requireAdmin();
  const [s, pay, site, hasPass] = await Promise.all([
    getSettings(), sslczConfig(), siteUrl(),
    one`SELECT 1 FROM settings WHERE setting_key = ${SSLCZ_KEYS.pass} AND setting_value <> ''`,
  ]);
  const mainAdmin = me.role_id === ROLE.ADMIN;
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
      <div className="pn-card" id="payment">
        <h2>Online payment — SSLCommerz</h2>
        <p className="pn-muted">bKash, Nagad, Rocket, Visa/Mastercard and internet banking through one SSLCommerz account.
          When this is on, checkout shows a <b>Pay online</b> button; the manual bKash / Nagad (TrxID) option stays as a backup.</p>
        <p><b>Status:</b> {pay
          ? <>✅ Connected — <b>{pay.live ? 'LIVE (real money)' : 'TEST mode (sandbox, no real money)'}</b>{pay.source === 'env' ? ' · from the server environment (SSLCZ_*), which overrides this form' : ''}</>
          : <>⚪ Off — checkout uses manual bKash / Nagad only</>}</p>
        {mainAdmin ? (
          <form method="post" action="/do/admin/sslcz-save" className="pn-grid" autoComplete="off">
            <div><label htmlFor="store_id">Store ID</label>
              <input id="store_id" name="store_id" className="form-control" maxLength={100} required defaultValue={s[SSLCZ_KEYS.id] ?? ''} placeholder="yourstore0live" /></div>
            <div><label htmlFor="store_pass">Store Password (API key)</label>
              <input id="store_pass" name="store_pass" type="password" className="form-control" maxLength={200} autoComplete="new-password"
                placeholder={hasPass ? '•••••••• saved — leave empty to keep' : 'from the SSLCommerz email'} /></div>
            <div><label htmlFor="mode">Mode</label>
              <select id="mode" name="mode" className="form-control" defaultValue={s[SSLCZ_KEYS.mode] === 'live' ? 'live' : 'test'}>
                <option value="test">Test (sandbox) — for trying it out</option>
                <option value="live">Live — real payments</option>
              </select></div>
            <div className="pn-full pn-actions" style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              <button type="submit" className="btn btn-primary">Save payment settings</button>
              {pay && <button type="submit" formAction="/do/admin/sslcz-test" formNoValidate className="btn btn-outline">Test connection</button>}
            </div>
          </form>
        ) : <p className="pn-muted">Only the main admin can change payment settings.</p>}
        {mainAdmin && pay?.source === 'admin' && <div style={{ marginTop: 8 }}><ActionButton action="/do/admin/sslcz-off" fields={{}} label="Turn off online payment" cls="btn btn-outline btn-xs pn-danger" confirm="Turn off online payment? Checkout will use manual bKash / Nagad only." /></div>}
        <details style={{ marginTop: 12 }}>
          <summary>Where do I get these? / What to set in SSLCommerz</summary>
          <ol>
            <li>Test keys (free, instant): register at <a href="https://developer.sslcommerz.com/registration/" target="_blank" rel="noopener">developer.sslcommerz.com/registration</a> — the Store ID and Password arrive by email. Choose <b>Test</b>.</li>
            <li>Live keys: apply for a merchant account at <a href="https://sslcommerz.com" target="_blank" rel="noopener">sslcommerz.com</a> (trade licence, NID, bank details, your website <b>{site}</b>). Then choose <b>Live</b>.</li>
            <li>In the SSLCommerz merchant panel set the <b>IPN URL</b> to <code>{site}/api/payment/ipn</code> so payments are confirmed even if the buyer closes the browser.</li>
          </ol>
        </details>
      </div>
      <div className="pn-card">
        <h2>Server configuration</h2>
        <p className="pn-muted">These are set as environment variables in Vercel (Project → Settings → Environment Variables), not here, so secrets never sit in the database.</p>
        <ul>
          <li>Email login codes (SMTP_HOST, SMTP_USER, SMTP_PASS): <b>{smtpConfigured() ? 'configured' : 'not set — students log in with a password'}</b></li>
        </ul>
      </div>
    </>
  );
}
