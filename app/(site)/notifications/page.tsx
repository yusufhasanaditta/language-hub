import type { Metadata } from 'next';
import { q } from '@/lib/db';
import { requireUser } from '@/lib/auth';
import { T } from '@/lib/i18n';
import { timeAgo } from '@/lib/format';
import { ListHead, Empty } from '@/components/ui';

export const metadata: Metadata = { title: 'নোটিফিকেশন' };

export default async function NotificationsPage() {
  const user = await requireUser();
  const items = await q`SELECT * FROM notifications WHERE user_id = ${user.id} ORDER BY created_at DESC LIMIT 100`;
  return (
    <>
      <ListHead back={['/dashboard', 'প্রোফাইল', 'Profile']} title={<T bn="নোটিফিকেশন" en="Notifications" />} />
      <section className="container lh-narrow lh-list-body">
        {items.length === 0 ? <Empty icon="notifications_none" bn="কোনো নোটিফিকেশন নেই" en="No notifications" /> : (
          <>
            <form method="post" action="/do/notifications-read" style={{ marginBottom: 12, textAlign: 'right' }}>
              <button type="submit" className="btn btn-outline btn-sm"><T bn="সব পড়া হয়েছে" en="Mark all read" /></button>
            </form>
            <div className="lh-c-card" style={{ padding: 0 }}>
              <ul className="lh-notif-list">
                {items.map(n => (
                  <li key={n.id} className={n.read_at ? '' : 'is-unread'}>
                    <a href={n.action_url || '#'}>
                      <b>{n.title}</b>
                      {n.body && <><br /><span>{n.body}</span></>}
                      <br /><small>{timeAgo(n.created_at)}</small>
                    </a>
                  </li>
                ))}
              </ul>
            </div>
          </>
        )}
      </section>
    </>
  );
}
