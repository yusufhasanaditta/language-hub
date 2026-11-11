import { q } from '@/lib/db';
import { requireAdmin } from '@/lib/auth';
import { fmtDateTime } from '@/lib/format';
import { PageHead, ActionButton } from '@/components/panel';

export const metadata = { title: 'Contact messages' };

export default async function MessagesPage() {
  await requireAdmin();
  const rows = await q`SELECT * FROM contact_messages ORDER BY is_read, created_at DESC LIMIT 300`;
  return (
    <>
      <PageHead title="Contact messages" sub="Sent from the Contact page." />
      {rows.length === 0 ? <div className="pn-card pn-empty">No messages yet</div> : rows.map(m => (
        <div key={m.id} className="pn-card" style={m.is_read ? { opacity: 0.75 } : { borderColor: 'var(--c-primary)' }}>
          <div className="pn-head" style={{ marginBottom: 8 }}>
            <div>
              <h3 style={{ margin: 0 }}>{m.subject || '(no subject)'}</h3>
              <p className="pn-muted" style={{ margin: 0 }}>{m.name} · <a className="pn-link" href={`mailto:${m.email}`}>{m.email}</a>{m.phone ? ` · ${m.phone}` : ''} · {fmtDateTime(m.created_at)}</p>
            </div>
            <div className="pn-actions">
              {!m.is_read && <ActionButton action="/do/admin/message-read" fields={{ id: m.id }} label="Mark read" />}
              <ActionButton action="/do/admin/message-delete" fields={{ id: m.id }} label="Delete" cls="btn btn-outline btn-xs pn-danger" confirm="Delete this message?" />
            </div>
          </div>
          <p style={{ whiteSpace: 'pre-line', margin: 0 }}>{m.message}</p>
        </div>
      ))}
    </>
  );
}
