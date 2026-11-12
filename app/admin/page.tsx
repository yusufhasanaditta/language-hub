import { q, one } from '@/lib/db';
import { requireAdmin } from '@/lib/auth';
import { avatarUrl, fmtDate, formatPrice, timeAgo } from '@/lib/format';
import { PanelHero, Stat, Badge, Money } from '@/components/panel';
import { Icon } from '@/components/ui';

export const metadata = { title: 'Dashboard' };

export default async function AdminDashboard() {
  const user = await requireAdmin();
  const [s, recentOrders, recentUsers] = await Promise.all([
    one`SELECT
      (SELECT count(*) FROM users WHERE role_id = 1)::int AS students,
      (SELECT count(*) FROM users WHERE role_id = 1 AND created_at >= now() - interval '7 days')::int AS new_week,
      (SELECT count(*) FROM courses WHERE status = 'published')::int AS courses,
      (SELECT count(*) FROM teacher_profiles tp JOIN users u ON u.id = tp.user_id WHERE u.role_id = 2)::int AS teachers,
      (SELECT count(*) FROM enrollments WHERE status = 'active')::int AS enrolls,
      (SELECT count(*) FROM orders WHERE status = 'paid')::int AS paid_orders,
      (SELECT count(*) FROM orders WHERE status = 'pending' AND pay_method = 'manual')::int AS to_verify,
      (SELECT count(*) FROM contact_messages WHERE NOT is_read)::int AS unread_msgs,
      (SELECT COALESCE(sum(total), 0) FROM orders WHERE status = 'paid' AND paid_at >= date_trunc('month', now() AT TIME ZONE 'Asia/Dhaka') AT TIME ZONE 'Asia/Dhaka') AS revenue_month,
      (SELECT COALESCE(sum(total), 0) FROM orders WHERE status = 'paid' AND (paid_at AT TIME ZONE 'Asia/Dhaka')::date = (now() AT TIME ZONE 'Asia/Dhaka')::date) AS revenue_today`,
    q`SELECT o.id, o.total, o.status, o.paid_at, o.created_at, u.name, u.email FROM orders o JOIN users u ON u.id = o.user_id
      WHERE o.status IN ('paid', 'pending') AND NOT (o.status = 'pending' AND o.pay_method = 'sslcommerz')
      ORDER BY COALESCE(o.paid_at, o.created_at) DESC LIMIT 8`,
    q`SELECT id, name, email, avatar, created_at FROM users ORDER BY created_at DESC LIMIT 8`,
  ]);

  return (
    <>
      <PanelHero eyebrow={`Admin pass · ${fmtDate(new Date())}`} title={`Welcome back, ${user.name.split(' ')[0]}`}
        sub={`${s!.courses} courses are boarding · ${s!.enrolls} active enrollments`} route={['বাংলা', '中文 · 日本語 · 한국어 · English']}>
        <a href="/admin/courses/new" className="btn btn-accent btn-sm"><Icon name="add" /> New course</a>
        <a href="/admin/orders?status=pending" className="btn btn-outline btn-sm"><Icon name="receipt" /> Payments</a>
      </PanelHero>

      {(s!.to_verify > 0 || s!.unread_msgs > 0) && (
        <div className="pn-card" style={{ borderColor: 'var(--c-warning)' }}>
          {s!.to_verify > 0 && <p style={{ margin: 0 }}><Icon name="pending_actions" /> <b>{s!.to_verify}</b> manual payment(s) waiting for approval — <a className="pn-link" href="/admin/orders?status=pending">review now</a></p>}
          {s!.unread_msgs > 0 && <p style={{ margin: '6px 0 0' }}><Icon name="mail" /> <b>{s!.unread_msgs}</b> unread contact message(s) — <a className="pn-link" href="/admin/messages">open</a></p>}
        </div>
      )}

      <div className="pn-stats">
        <Stat icon="people" color="primary" value={s!.students} label="Students" sub={`+${s!.new_week} this week`} />
        <Stat icon="play_circle" color="accent" value={s!.courses} label="Published courses" sub={`${s!.enrolls} active enrollments`} />
        <Stat icon="receipt" color="success" value={`৳${formatPrice(s!.revenue_month)}`} label="Revenue this month" sub={`Today ৳${formatPrice(s!.revenue_today)}`} />
        <Stat icon="school" color="warning" value={s!.teachers} label="Teachers" sub={`${s!.paid_orders} paid orders`} />
      </div>

      <div className="pn-two">
        <div>
          <h3 style={{ marginBottom: 'var(--sp-4)' }}>Recent orders</h3>
          <div className="data-table-wrap">
            <table className="data-table">
              <thead><tr><th>Student</th><th>Amount</th><th>Date</th><th>Status</th></tr></thead>
              <tbody>
                {recentOrders.length === 0 ? <tr><td colSpan={4} className="pn-empty">No orders yet</td></tr> : recentOrders.map(o => (
                  <tr key={o.id}>
                    <td><div style={{ fontWeight: 600 }}>{o.name}</div><div className="pn-muted">{o.email}</div></td>
                    <td><Money v={o.total} /></td>
                    <td className="pn-muted">{fmtDate(o.paid_at ?? o.created_at)}</td>
                    <td><Badge s={o.status} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <a href="/admin/orders" className="view-all-link" style={{ marginTop: 12, display: 'flex' }}>View all orders →</a>
        </div>
        <div>
          <h3 style={{ marginBottom: 'var(--sp-4)' }}>New users</h3>
          <div className="data-table-wrap">
            <table className="data-table">
              <thead><tr><th>Name</th><th>Email</th><th>Joined</th></tr></thead>
              <tbody>
                {recentUsers.map(u => (
                  <tr key={u.id}>
                    <td><a href={`/admin/users/${u.id}`} style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'inherit', textDecoration: 'none' }}>
                      <img src={avatarUrl(u.avatar, u.name)} alt="" style={{ width: 28, height: 28, borderRadius: '50%' }} />
                      <span style={{ fontWeight: 600, fontSize: 'var(--text-sm)' }}>{u.name}</span></a></td>
                    <td className="pn-muted">{u.email ?? '—'}</td>
                    <td className="pn-muted">{timeAgo(u.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <a href="/admin/users" className="view-all-link" style={{ marginTop: 12, display: 'flex' }}>All users →</a>
        </div>
      </div>
    </>
  );
}
