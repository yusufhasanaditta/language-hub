import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { one, q } from '@/lib/db';
import { requireUser, isAdmin } from '@/lib/auth';
import { formatPrice, fmtDate } from '@/lib/format';
import { getSettings } from '@/lib/settings';
import { Icon } from '@/components/ui';

export const metadata: Metadata = { title: 'Invoice', robots: { index: false } };

export default async function InvoicePage({ params }: { params: Promise<{ id: string }> }) {
  const user = await requireUser();
  const id = Number((await params).id) || 0;
  const o = await one`SELECT * FROM orders WHERE id = ${id} AND status = 'paid'`;
  // Students see only their own invoices; admins can open any.
  if (!o || (o.user_id !== user.id && !isAdmin(user))) notFound();
  const [items, s] = await Promise.all([q`SELECT title, price FROM order_items WHERE order_id = ${id}`, getSettings()]);

  return (
    <section className="container lh-narrow lh-list-body" style={{ paddingTop: 32 }}>
      <div className="lh-c-card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 16, flexWrap: 'wrap' }}>
          <div>
            <img src="/image/language-hub-logo.png" alt="" width={44} height={44} />
            <h1 style={{ margin: '8px 0 0', fontSize: '1.4rem' }}>Language Hub</h1>
            <p className="lh-muted" style={{ margin: 0 }}>{s.contact_email || ''} {s.contact_phone || ''}</p>
          </div>
          <div style={{ textAlign: 'right' }}>
            <h2 style={{ margin: 0 }}>INVOICE</h2>
            <p style={{ margin: 0 }}><b>{o.invoice_no}</b></p>
            <p className="lh-muted" style={{ margin: 0 }}>{fmtDate(o.paid_at)}</p>
          </div>
        </div>
        <hr style={{ margin: '20px 0', border: 0, borderTop: '1px solid var(--lh-line, #e5e5e5)' }} />
        <p style={{ margin: '0 0 16px' }}><b>Billed to:</b> {o.billing_name}<br />{o.billing_email}{o.billing_phone ? ` · ${o.billing_phone}` : ''}</p>
        <div className="lh-table-card">
          <table>
            <thead><tr><th>Item</th><th style={{ textAlign: 'right' }}>Amount (BDT)</th></tr></thead>
            <tbody>
              {items.map((it, i) => <tr key={i}><td>{it.title}</td><td style={{ textAlign: 'right' }}>{formatPrice(o.subtotal)}</td></tr>)}
              {Number(o.discount) > 0 && <tr><td>Discount{o.coupon_code ? ` (${o.coupon_code})` : ''}</td><td style={{ textAlign: 'right' }}>−{formatPrice(o.discount)}</td></tr>}
              <tr><td><b>Total paid</b></td><td style={{ textAlign: 'right' }}><b>{formatPrice(o.total)}</b></td></tr>
            </tbody>
          </table>
        </div>
        <p className="lh-muted" style={{ marginTop: 16 }}>Payment: {o.pay_method ?? '—'}{o.pay_ref ? ` · Ref ${o.pay_ref}` : ''}</p>
        <button type="button" className="btn btn-outline lh-no-print" data-print><Icon name="print" /> Print / Save PDF</button>
        <script dangerouslySetInnerHTML={{ __html: `document.querySelector('[data-print]').onclick=function(){window.print()}` }} />
      </div>
    </section>
  );
}
