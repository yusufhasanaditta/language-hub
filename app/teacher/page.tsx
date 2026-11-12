import { q } from '@/lib/db';
import { requireTeacherProfile, myCourses } from '@/lib/teacher';
import { fmtDate, fmtDateTime } from '@/lib/format';
import { lang } from '@/lib/langs';
import { PanelHero, Stat, PanelCourseTicket } from '@/components/panel';
import { Icon } from '@/components/ui';

export const metadata = { title: 'Dashboard' };

export default async function TeacherDashboard() {
  const { user, tp } = await requireTeacherProfile();
  const [courses, upcoming] = await Promise.all([
    myCourses(tp.id),
    q`SELECT ls.*, c.title_bn FROM live_sessions ls JOIN courses c ON c.id = ls.course_id
      WHERE c.teacher_id = ${tp.id} AND ls.status IN ('scheduled', 'live') AND ls.scheduled_at + make_interval(mins => ls.duration_minutes) >= now()
      ORDER BY ls.scheduled_at LIMIT 5`,
  ]);
  const students = courses.reduce((n, c) => n + c.students, 0);
  const L = lang(courses[0]?.cat_slug);
  return (
    <>
      <PanelHero eyebrow={`Crew pass · ${fmtDate(new Date())}`} title={`Hello, ${user.name.split(' ')[0]}`}
        sub={upcoming[0] ? `Next live class: ${upcoming[0].title} — ${fmtDateTime(upcoming[0].scheduled_at)}` : 'Your courses, students and live classes in one place.'}
        route={['বাংলা', L.native || 'Language Hub']}>
        <a href="/teacher/live" className="btn btn-accent btn-sm"><Icon name="videocam" /> Live classes</a>
        <a href="/teacher/profile" className="btn btn-outline btn-sm"><Icon name="badge" /> My profile</a>
      </PanelHero>
      <div className="pn-stats">
        <Stat icon="play_circle" color="primary" value={courses.length} label="My courses" sub={`${courses.filter(c => c.status === 'published').length} published`} />
        <Stat icon="groups" color="accent" value={students} label="Active students" />
        <Stat icon="video_library" color="success" value={courses.reduce((n, c) => n + c.lessons, 0)} label="Classes" />
        <Stat icon="videocam" color="warning" value={upcoming.length} label="Upcoming live" />
      </div>

      <div className="pn-head"><div><p className="pn-eyebrow">Your flights</p><h1 style={{ fontSize: '1.35rem' }}>My courses</h1></div></div>
      {courses.length === 0 ? <div className="pn-card pn-empty">No courses assigned to you yet. An admin assigns courses to teachers.</div> : (
        <div className="pn-tickets" style={{ marginBottom: 28 }}>
          {courses.map(c => <PanelCourseTicket key={c.id} c={c} href={`/teacher/courses/${c.id}`} />)}
        </div>
      )}

      <div className="pn-card">
        <h2>Upcoming live classes</h2>
        {upcoming.length === 0 ? <p className="pn-muted">Nothing scheduled. Open a course to schedule a live class.</p> : (
          <table className="data-table"><tbody>
            {upcoming.map(s => (
              <tr key={s.id}>
                <td><b>{s.title}</b><div className="pn-muted">{s.title_bn}</div></td>
                <td className="pn-muted">{fmtDateTime(s.scheduled_at)}</td>
                <td>{s.meeting_url ? <a className="pn-link" href={s.meeting_url} target="_blank" rel="noopener">Start ↗</a> : <a className="pn-link" href={`/teacher/courses/${s.course_id}#live`}>Add link</a>}</td>
              </tr>
            ))}
          </tbody></table>
        )}
      </div>
    </>
  );
}
