import { q } from '@/lib/db';
import { requireTeacherProfile, myCourses } from '@/lib/teacher';
import { fmtDateTime } from '@/lib/format';
import { PageHead, Stat, Badge } from '@/components/panel';

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
  return (
    <>
      <PageHead title={`Hello, ${user.name.split(' ')[0]}`} sub="Your courses, students and upcoming live classes." />
      <div className="pn-stats">
        <Stat icon="play_circle" color="primary" value={courses.length} label="My courses" sub={`${courses.filter(c => c.status === 'published').length} published`} />
        <Stat icon="groups" color="accent" value={students} label="Active students" />
        <Stat icon="video_library" color="success" value={courses.reduce((n, c) => n + c.lessons, 0)} label="Lessons" />
        <Stat icon="videocam" color="warning" value={upcoming.length} label="Upcoming live classes" />
      </div>
      <div className="pn-two">
        <div className="pn-card">
          <h2>My courses</h2>
          {courses.length === 0 ? <p className="pn-muted">No courses assigned to you yet. An admin assigns courses to teachers.</p> : (
            <table className="data-table"><tbody>
              {courses.map(c => (
                <tr key={c.id}>
                  <td><a className="pn-link" href={`/teacher/courses/${c.id}`}>{c.title_bn}</a><div className="pn-muted">{c.cat_name_en} · {c.type} · {c.lessons} lessons</div></td>
                  <td>{c.students} students</td>
                  <td><Badge s={c.status} /></td>
                </tr>
              ))}
            </tbody></table>
          )}
        </div>
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
      </div>
    </>
  );
}
