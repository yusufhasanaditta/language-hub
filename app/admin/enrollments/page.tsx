import { raw, q } from '@/lib/db';
import { requireAdmin } from '@/lib/auth';
import { fmtDate } from '@/lib/format';
import { PageHead, Badge, ActionButton } from '@/components/panel';

export const metadata = { title: 'Enrollments' };

export default async function EnrollmentsPage({ searchParams }: { searchParams: Promise<{ course?: string; q?: string }> }) {
  await requireAdmin();
  const sp = await searchParams;
  const courseId = Number(sp.course) || 0;
  const search = (sp.q ?? '').trim().slice(0, 80);
  const where: string[] = [], args: unknown[] = [];
  if (courseId) { args.push(courseId); where.push(`e.course_id = $${args.length}`); }
  if (search) { args.push(`%${search}%`); where.push(`(u.name ILIKE $${args.length} OR u.email ILIKE $${args.length})`); }
  const [rows, courses] = await Promise.all([
    raw(`SELECT e.*, u.name, u.email, c.title_bn FROM enrollments e JOIN users u ON u.id = e.user_id JOIN courses c ON c.id = e.course_id
         ${where.length ? 'WHERE ' + where.join(' AND ') : ''} ORDER BY e.enrolled_at DESC LIMIT 300`, args),
    q`SELECT id, title_bn FROM courses ORDER BY title_bn`,
  ]);
  return (
    <>
      <PageHead title="Enrollments" sub="Who has access to which course. Give access manually for cash or offline payments." />
      <div className="pn-card">
        <h2>Give a student access</h2>
        <form method="post" action="/do/admin/enroll" className="pn-toolbar">
          <input name="email" type="email" className="form-control" placeholder="Student email" required />
          <select name="course_id" className="form-control" required>
            <option value="">Course…</option>{courses.map(c => <option key={c.id} value={c.id}>{c.title_bn}</option>)}
          </select>
          <button className="btn btn-primary btn-sm" type="submit">Enroll</button>
        </form>
      </div>
      <form method="get" className="pn-toolbar">
        <input name="q" className="form-control" placeholder="Search student…" defaultValue={search} />
        <select name="course" className="form-control" defaultValue={courseId || ''}>
          <option value="">All courses</option>{courses.map(c => <option key={c.id} value={c.id}>{c.title_bn}</option>)}
        </select>
        <button className="btn btn-outline btn-sm" type="submit">Filter</button>
      </form>
      <div className="data-table-wrap">
        <table className="data-table">
          <thead><tr><th>Student</th><th>Course</th><th>Progress</th><th>Enrolled</th><th>Status</th><th></th></tr></thead>
          <tbody>
            {rows.length === 0 ? <tr><td colSpan={6} className="pn-empty">No enrollments</td></tr> : rows.map(e => (
              <tr key={e.id}>
                <td><a className="pn-link" href={`/admin/users/${e.user_id}`}>{e.name}</a><div className="pn-muted">{e.email}</div></td>
                <td>{e.title_bn}</td>
                <td>{e.progress_pct}%</td>
                <td className="pn-muted">{fmtDate(e.enrolled_at)}{e.order_id ? ` · order #${e.order_id}` : ' · manual/free'}</td>
                <td><Badge s={e.status} /></td>
                <td><ActionButton action="/do/admin/enrollment-status" fields={{ id: e.id, status: e.status === 'active' ? 'suspended' : 'active' }} label={e.status === 'active' ? 'Suspend' : 'Activate'} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
