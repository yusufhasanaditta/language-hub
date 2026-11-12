import { raw } from '@/lib/db';
import { requireAdmin } from '@/lib/auth';
import { fmtDateTime } from '@/lib/format';
import { PageHead, Badge, Money, ActionButton } from '@/components/panel';

export const metadata = { title: 'Orders' };

const STATUSES = ['pending', 'paid', 'failed', 'refunded', 'cancelled'];

export default async function OrdersPage({ searchParams }: { searchParams: Promise<{ status?: string }> }) {
  await requireAdmin();
  const status = (await searchParams).status ?? '';
  const valid = STATUSES.includes(status) ? status : '';
  const orders = await raw(`
    SELECT o.*, u.name, u.email, (SELECT string_agg(title, ', ') FROM order_items WHERE order_id = o.id) AS items,
      (SELECT bool_or(c.max_students > 0 AND (SELECT count(*) FROM enrollments e WHERE e.course_id = c.id AND e.status = 'active') >= c.max_students)
         FROM order_items i JOIN courses c ON c.id = i.course_id WHERE i.order_id = o.id) AS course_full
    FROM orders o JOIN users u ON u.id = o.user_id
    WHERE NOT (o.status = 'pending' AND o.pay_method = 'sslcommerz' AND o.created_at < now() - interval '1 day')
    ${valid ? 'AND o.status = $1' : ''}
    ORDER BY (o.status = 'pending') DESC, o.created_at DESC LIMIT 300`, valid ? [valid] : []);
  return (
    <>
      <PageHead title="Orders & payments" sub="Approve manual bKash/Nagad payments after you check the TrxID in your merchant app. Approving enrolls the student at once." />
      <div className="pn-toolbar">
        {['', ...STATUSES].map(k => (
          <a key={k} href={k ? `/admin/orders?status=${k}` : '/admin/orders'} className={`btn btn-sm ${valid === k ? 'btn-primary' : 'btn-outline'}`}>{k || 'All'}</a>
        ))}
      </div>
      <div className="data-table-wrap">
        <table className="data-table">
          <thead><tr><th>#</th><th>Student</th><th>Course</th><th>Total</th><th>Payment</th><th>Date</th><th>Status</th><th></th></tr></thead>
          <tbody>
            {orders.length === 0 ? <tr><td colSpan={8} className="pn-empty">No orders</td></tr> : orders.map(o => (
              <tr key={o.id}>
                <td>{o.invoice_no ?? o.id}</td>
                <td><a className="pn-link" href={`/admin/users/${o.user_id}`}>{o.name}</a><div className="pn-muted">{o.email}</div></td>
                <td>{o.items}{o.coupon_code && <div className="pn-muted">coupon {o.coupon_code}</div>}</td>
                <td><Money v={o.total} /></td>
                <td>{o.pay_method ?? '—'}{o.pay_ref && <div className="pn-muted">TrxID <b>{o.pay_ref}</b></div>}{o.billing_phone && <div className="pn-muted">from {o.billing_phone}</div>}</td>
                <td className="pn-muted">{fmtDateTime(o.paid_at ?? o.created_at)}</td>
                <td><Badge s={o.status} /></td>
                <td style={{ whiteSpace: 'nowrap' }}>
                  {o.status === 'pending' && <>
                    {o.course_full && <div className="pn-danger pn-muted" style={{ marginBottom: 4 }}>Course is full — approving goes over the seat limit</div>}
                    <ActionButton action="/do/admin/order-approve" fields={{ id: o.id }} label="Approve" cls="btn btn-primary btn-xs" confirm={`Approve ৳${Number(o.total)} from ${o.name}? They will be enrolled.${o.course_full ? ' The course is already full.' : ''}`} />{' '}
                    <ActionButton action="/do/admin/order-status" fields={{ id: o.id, status: 'failed' }} label="Reject" cls="btn btn-outline btn-xs pn-danger" confirm="Reject this payment?" />
                  </>}
                  {o.status === 'paid' && <>
                    <a className="pn-link" href={`/invoice/${o.id}`}>Invoice</a>{' '}
                    <ActionButton action="/do/admin/order-status" fields={{ id: o.id, status: 'refunded' }} label="Refund" cls="btn btn-outline btn-xs" confirm="Mark as refunded and remove the student's access?" />
                  </>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
