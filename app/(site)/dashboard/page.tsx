import type { Metadata } from 'next';
import { q, val, one } from '@/lib/db';
import { requireUser, activeSessions, MAX_DEVICES } from '@/lib/auth';
import { T, N } from '@/lib/i18n';
import { avatarUrl, fmtDateTime } from '@/lib/format';
import { Icon, Empty } from '@/components/ui';
import { MyCourseRow, LiveCard } from '@/components/student';

export const metadata: Metadata = { title: 'আমার প্রোফাইল' };

export default async function DashboardPage() {
  const user = await requireUser();
  const [courses, devices, unread, nextLive] = await Promise.all([
    q`SELECT c.id, c.title_bn, c.title_en, c.type, e.progress_pct, cat.slug AS cat_slug, cat.name_bn AS cat_name, cat.name_en AS cat_name_en
      FROM enrollments e JOIN courses c ON c.id = e.course_id JOIN categories cat ON cat.id = c.category_id
      WHERE e.user_id = ${user.id} AND e.status = 'active' ORDER BY e.enrolled_at DESC`,
    activeSessions(user.id),
    val<number>`SELECT count(*)::int FROM notifications WHERE user_id = ${user.id} AND read_at IS NULL`,
    one`SELECT ls.*, c.title_bn AS course_title, c.title_en AS course_title_en
        FROM live_sessions ls JOIN courses c ON c.id = ls.course_id
        JOIN enrollments e ON e.course_id = c.id AND e.user_id = ${user.id} AND e.status = 'active'
        WHERE ls.status IN ('scheduled', 'live') AND ls.scheduled_at + make_interval(mins => ls.duration_minutes) >= now()
        ORDER BY ls.scheduled_at LIMIT 1`,
  ]);
  const first = user.name.trim().split(' ')[0] || user.name;
  const quick: [string, string, React.ReactNode][] = [
    ['/my-courses', 'play_circle', <T key="a" bn="আমার কোর্স" en="My courses" />],
    ['/orders', 'receipt_long', <T key="b" bn="পেমেন্ট ও ইনভয়েস" en="Payments & invoices" />],
    ['/notifications', 'notifications', <><T bn="নোটিফিকেশন" en="Notifications" />{unread ? <> <span className="lh-fchip-n"><N v={unread} /></span></> : null}</>],
    ['/profile', 'manage_accounts', <T key="d" bn="প্রোফাইল এডিট" en="Edit profile" />],
    ['/contact', 'support_agent', <T key="e" bn="সাহায্য" en="Help" />],
  ];

  return (
    <>
      <section className="lh-dash-head">
        <div className="lh-glow" aria-hidden="true"></div>
        <div className="container lh-dash-me" data-reveal>
          <img src={avatarUrl(user.avatar, user.name)} alt="" width={64} height={64} />
          <div>
            <p className="lh-eyebrow"><T bn="স্বাগতম" en="Welcome back" /></p>
            <h1>{first}</h1>
            <p className="lh-muted">{user.email ?? user.phone}</p>
          </div>
          <a href="/profile" className="lh-toggle" aria-label="Edit profile"><Icon name="edit" /></a>
        </div>
      </section>

      <div className="container lh-dash">
        {nextLive && (
          <>
            <LiveCard s={nextLive} courseTitle={<T bn={nextLive.course_title} en={nextLive.course_title_en || nextLive.course_title} />} />
            <a href={`/learn?course=${nextLive.course_id}`} className="lh-link"><T bn="ক্লাসরুমে যান" en="Open classroom" /> <Icon name="arrow_forward" /></a>
          </>
        )}

        <section>
          <div className="lh-section-head lh-section-head-row" data-reveal>
            <div><h2><T bn="আমার কোর্স" en="My courses" /></h2></div>
            {courses.length > 0 && <a href="/my-courses" className="lh-link"><T bn="সব" en="All" /> <Icon name="arrow_forward" /></a>}
          </div>
          {courses.length ? (
            <div className="lh-mycourses" data-reveal-group>
              {courses.map(c => <MyCourseRow key={c.id} c={c} meta={<><T bn={c.cat_name} en={c.cat_name_en} /> · {c.type === 'live' ? <T bn="লাইভ" en="Live" /> : <T bn="রেকর্ডেড" en="Recorded" />}</>} />)}
            </div>
          ) : (
            <Empty icon="school" bn="এখনো কোনো কোর্সে ভর্তি হননি" en="No courses yet">
              <p><T bn="একটি ফ্রি ক্লাস দিয়ে শুরু করুন।" en="Start with a free class." /></p>
              <a href="/courses" className="btn btn-primary"><T bn="কোর্স দেখুন" en="Browse courses" /></a>
            </Empty>
          )}
        </section>

        <nav className="lh-quick" aria-label="Account" data-reveal-group>
          {quick.map(([href, icon, label]) => <a key={href} href={href}><Icon name={icon} /><span>{label}</span></a>)}
        </nav>

        <section className="lh-c-card" id="devices" data-reveal>
          <h2><T bn="লগইন করা ডিভাইস" en="Signed-in devices" /> <small><N v={devices.length} />/<N v={MAX_DEVICES} /></small></h2>
          <div className="lh-devices">
            {devices.map(d => {
              const isThis = d.id === user.session_id;
              return (
                <form key={d.id} method="post" action="/do/device-signout" className="lh-device">
                  <input type="hidden" name="session_id" value={d.id} />
                  <Icon name="smartphone" />
                  <span className="lh-device-name">{d.device_name || 'Device'}<small>{isThis ? <T bn="এই ডিভাইস" en="This device" /> : fmtDateTime(d.last_seen_at)}</small></span>
                  {!isThis && <button type="submit" className="lh-btn-quiet"><T bn="লগআউট" en="Sign out" /></button>}
                </form>
              );
            })}
          </div>
          <a href="/logout" className="lh-link lh-logout"><Icon name="logout" /><T bn="এই ডিভাইস থেকে লগআউট" en="Log out of this device" /></a>
        </section>
      </div>
    </>
  );
}
