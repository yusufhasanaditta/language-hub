import type { Metadata } from 'next';
import { q } from '@/lib/db';
import { requireUser } from '@/lib/auth';
import { T, N } from '@/lib/i18n';
import { formatPrice, fmtDate } from '@/lib/format';
import { ListHead, Empty } from '@/components/ui';

export const metadata: Metadata = { title: 'পেমেন্ট ও ইনভয়েস' };

const STATUS: Record<string, [string, string]> = {
  paid: ['পরিশোধিত', 'Paid'], pending: ['যাচাই চলছে', 'Being checked'], failed: ['ব্যর্থ', 'Failed'],
  cancelled: ['বাতিল', 'Cancelled'], refunded: ['রিফান্ড', 'Refunded'],
};

export default async function OrdersPage({ searchParams }: { searchParams: Promise<{ pay?: string }> }) {
  const user = await requireUser();
  const pay = (await searchParams).pay;
  const orders = await q`
    SELECT o.*, (SELECT string_agg(title, ', ') FROM order_items WHERE order_id = o.id) AS items
    FROM orders o WHERE o.user_id = ${user.id} AND NOT (o.status = 'pending' AND o.pay_method = 'sslcommerz')
    ORDER BY o.created_at DESC`;
  return (
    <>
      <ListHead back={['/dashboard', 'প্রোফাইল', 'Profile']} title={<T bn="পেমেন্ট ও ইনভয়েস" en="Payments & invoices" />} />
      <section className="container lh-list-body">
        {pay === 'failed' && <p className="lh-auth-error" role="alert"><T bn="পেমেন্ট সম্পন্ন হয়নি। আবার চেষ্টা করুন।" en="The payment did not go through. Please try again." /></p>}
        {orders.length === 0 ? (
          <Empty icon="receipt_long" bn="এখনো কোনো পেমেন্ট নেই" en="No payments yet">
            <a href="/courses" className="btn btn-primary"><T bn="কোর্স দেখুন" en="Browse courses" /></a>
          </Empty>
        ) : (
          <div className="lh-table-card" data-reveal>
            <table>
              <thead><tr><th><T bn="কোর্স" en="Course" /></th><th><T bn="তারিখ" en="Date" /></th><th><T bn="মোট" en="Total" /></th><th><T bn="অবস্থা" en="Status" /></th><th></th></tr></thead>
              <tbody>
                {orders.map(o => {
                  const s = STATUS[o.status] ?? [o.status, o.status];
                  return (
                    <tr key={o.id}>
                      <td>{o.items}{o.pay_ref && <><br /><small className="pn-muted">TrxID {o.pay_ref}</small></>}</td>
                      <td>{fmtDate(o.paid_at ?? o.created_at)}</td>
                      <td>৳<N v={formatPrice(o.total)} /></td>
                      <td><span className={`status-badge status-${o.status}`}><T bn={s[0]} en={s[1]} /></span></td>
                      <td>{(o.status === 'paid' || o.status === 'refunded') && <a className="lh-link" href={`/invoice/${o.id}`}><T bn="ইনভয়েস" en="Invoice" /></a>}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </>
  );
}
