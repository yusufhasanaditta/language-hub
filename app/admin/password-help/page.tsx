import { q, one } from '@/lib/db';
import { requireAdmin } from '@/lib/auth';
import { fmtDateTime, avatarUrl } from '@/lib/format';
import { readShownReset, RESET_HOURS } from '@/lib/reset';
import { PageHead, Badge, ActionButton } from '@/components/panel';
import { Icon } from '@/components/ui';

export const metadata = { title: 'Password help' };

/** wa.me link with the message filled in (to the learner's number when we have one). */
function whatsapp(phone: string | null, text: string) {
  const digits = (phone ?? '').replace(/\D/g, '');
  const to = digits.length === 11 && digits.startsWith('01') ? `88${digits}` : digits.length >= 12 ? digits : '';
  return `https://wa.me/${to}?text=${encodeURIComponent(text)}`;
}

export default async function PasswordHelpPage() {
  await requireAdmin();
  const [requests, made] = await Promise.all([
    q`SELECT r.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone, u.avatar, u.is_active
      FROM password_requests r LEFT JOIN users u ON u.id = r.user_id
      WHERE r.status = 'open' OR r.created_at > now() - interval '30 days'
      ORDER BY (r.status = 'open') DESC, r.created_at DESC LIMIT 200`,
    readShownReset(),
  ]);
  const link = made?.url ?? null;
  const madeFor = made ? await one`SELECT name, phone FROM users WHERE id = ${made.user_id}` : null;
  const open = requests.filter(r => r.status === 'open').length;
  const msg = link ? `আসসালামু আলাইকুম ${madeFor!.name}, Language Hub-এ নতুন পাসওয়ার্ড সেট করতে এই লিংকে যান (${RESET_HOURS} ঘণ্টা কাজ করবে, একবারই ব্যবহার করা যাবে):\n${link}` : '';

  return (
    <>
      <PageHead eyebrow="Account help" title="Password help"
        sub={<>Learners who forgot their password ask here. Passwords are stored encrypted, so <b>no one can see them</b> — instead, create a one-time link (valid {RESET_HOURS} h) and send it, or set a temporary password.</>} />

      {link && (
        <div className="pn-card pn-reset-made" id="link">
          <h2><Icon name="link" /> Reset link for {madeFor!.name}</h2>
          <p className="pn-muted">Copy it now — it disappears from this page in a few minutes. It works once and expires in {RESET_HOURS} hours.</p>
          <div className="pn-copy">
            <input className="form-control" readOnly value={link} id="resetLink" aria-label="Reset link" />
            <button type="button" className="btn btn-primary btn-sm" data-copy="#resetLink"><Icon name="content_copy" /> <span>Copy</span></button>
          </div>
          <div className="pn-actions" style={{ marginTop: 12 }}>
            <a className="btn btn-sm pn-wa" href={whatsapp(madeFor!.phone, msg)} target="_blank" rel="noopener noreferrer"><Icon name="chat" /> Send on WhatsApp</a>
          </div>
        </div>
      )}

      <div className="pn-card">
        <h2><Icon name="lock_reset" /> Requests <span className="pn-count">{open} open</span></h2>
        {requests.length === 0 ? <p className="pn-empty-note"><Icon name="verified_user" /> No requests yet. When a learner taps “Forgot password?” on the login page, it appears here.</p> : (
          <ul className="pn-help-list">
            {requests.map(r => (
              <li key={r.id} id={`r${r.id}`} className={`pn-help ${r.status !== 'open' ? 'is-closed' : ''}`}>
                <div className="pn-help-who">
                  <img src={avatarUrl(r.avatar, r.user_name ?? r.name ?? '?')} alt="" width={44} height={44} />
                  <div>
                    <b>{r.name || r.user_name || 'Unknown'}</b> <Badge s={r.status} />
                    <small>Typed: <code>{r.contact}</code>{r.note ? <> · Note: {r.note}</> : null} · {fmtDateTime(r.created_at)}</small>
                    {r.user_id ? (
                      <small className="pn-help-match"><Icon name="check_circle" /> Matches account <a className="pn-link" href={`/admin/users/${r.user_id}`}>{r.user_name}</a> ({r.user_email ?? r.user_phone ?? 'no email'}){!r.is_active && ' — disabled'}</small>
                    ) : (
                      <small className="pn-help-nomatch"><Icon name="help" /> No account with this email/phone. <a className="pn-link" href={`/admin/users?q=${encodeURIComponent(r.name || r.contact)}`}>Search users</a></small>
                    )}
                  </div>
                </div>
                {r.status === 'open' ? (
                  <div className="pn-help-actions">
                    {r.user_id && <ActionButton action="/do/admin/reset-link" fields={{ user_id: r.user_id }} label={<><Icon name="link" /> Create reset link</>} cls="btn btn-primary btn-xs" />}
                    {r.user_id && (
                      <details className="pn-temp">
                        <summary>Set temporary password</summary>
                        <form method="post" action="/do/admin/temp-password" className="pn-inline">
                          <input type="hidden" name="user_id" value={r.user_id} />
                          <input name="password" className="form-control" minLength={8} required placeholder="min. 8 characters" aria-label="Temporary password" />
                          <button className="btn btn-outline btn-xs" type="submit">Set</button>
                        </form>
                      </details>
                    )}
                    <ActionButton action="/do/admin/help-status" fields={{ id: r.id, status: 'done' }} label="Mark done" />
                    <ActionButton action="/do/admin/help-status" fields={{ id: r.id, status: 'dismissed' }} label="Dismiss" cls="btn btn-outline btn-xs pn-danger" />
                  </div>
                ) : (
                  <ActionButton action="/do/admin/help-status" fields={{ id: r.id, status: 'open' }} label="Reopen" />
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
      <script dangerouslySetInnerHTML={{ __html:
        `document.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('[data-copy]');if(!b)return;var i=document.querySelector(b.getAttribute('data-copy'));if(!i)return;i.select();(navigator.clipboard?navigator.clipboard.writeText(i.value):Promise.reject()).catch(function(){document.execCommand('copy')}).finally(function(){var s=b.querySelector('span');if(s){s.textContent='Copied!';setTimeout(function(){s.textContent='Copy'},1800)}})});` }} />
    </>
  );
}
