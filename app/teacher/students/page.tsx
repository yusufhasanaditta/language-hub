import { q } from '@/lib/db';
import { requireTeacherProfile } from '@/lib/teacher';
import { fmtDate } from '@/lib/format';
import { PageHead } from '@/components/panel';

export const metadata = { title: 'Students' };

export default async function TeacherStudents() {
  const { tp } = await requireTeacherProfile();
  const rows = await q`SELECT u.name, u.email, u.phone, c.title_bn, e.progress_pct, e.enrolled_at
    FROM enrollments e JOIN courses c ON c.id = e.course_id JOIN users u ON u.id = e.user_id
    WHERE c.id IN (SELECT course_id FROM course_teachers WHERE teacher_id = ${tp.id}) AND e.status = 'active' ORDER BY e.enrolled_at DESC LIMIT 500`;
  return (
    <>
      <PageHead title="Students" sub="Everyone enrolled in your courses." />
      <div className="data-table-wrap">
        <table className="data-table">
          <thead><tr><th>Student</th><th>Contact</th><th>Course</th><th>Progress</th><th>Joined</th></tr></thead>
          <tbody>
            {rows.length === 0 ? <tr><td colSpan={5} className="pn-empty">No students yet</td></tr> : rows.map((r, i) => (
              <tr key={i}><td>{r.name}</td><td className="pn-muted">{r.email}{r.phone ? ` · ${r.phone}` : ''}</td><td>{r.title_bn}</td><td>{r.progress_pct}%</td><td className="pn-muted">{fmtDate(r.enrolled_at)}</td></tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
