import { q } from '@/lib/db';
import { requireTeacherProfile } from '@/lib/teacher';
import { fmtDateTime } from '@/lib/format';
import { PageHead, Badge } from '@/components/panel';

export const metadata = { title: 'Live classes' };

export default async function TeacherLive() {
  const { tp } = await requireTeacherProfile();
  const sessions = await q`SELECT ls.*, c.title_bn FROM live_sessions ls JOIN courses c ON c.id = ls.course_id
    WHERE c.id IN (SELECT course_id FROM course_teachers WHERE teacher_id = ${tp.id}) ORDER BY (ls.scheduled_at >= now() - interval '3 hours') DESC, ls.scheduled_at LIMIT 200`;
  return (
    <>
      <PageHead title="Live classes" sub="All live classes across your courses. To add one, open the course." />
      <div className="data-table-wrap">
        <table className="data-table">
          <thead><tr><th>Class</th><th>Course</th><th>When</th><th>Status</th><th>Links</th></tr></thead>
          <tbody>
            {sessions.length === 0 ? <tr><td colSpan={5} className="pn-empty">No live classes yet</td></tr> : sessions.map(s => (
              <tr key={s.id}>
                <td><b>{s.title}</b></td>
                <td><a className="pn-link" href={`/teacher/courses/${s.course_id}#live`}>{s.title_bn}</a></td>
                <td className="pn-muted">{fmtDateTime(s.scheduled_at)} · {s.duration_minutes} min</td>
                <td><Badge s={s.status} /></td>
                <td>{s.meeting_url ? <a className="pn-link" href={s.meeting_url} target="_blank" rel="noopener">Meeting ↗</a> : <span className="pn-muted">no link</span>}{s.recording_url ? ' · recorded' : ''}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
