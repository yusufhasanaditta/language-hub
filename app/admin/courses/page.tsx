import { raw } from '@/lib/db';
import { requireAdmin } from '@/lib/auth';
import { COURSE_SELECT } from '@/lib/courses';
import { PageHead, Badge, Money } from '@/components/panel';
import { Icon } from '@/components/ui';

export const metadata = { title: 'Courses' };

export default async function AdminCourses({ searchParams }: { searchParams: Promise<{ status?: string }> }) {
  await requireAdmin();
  const status = (await searchParams).status;
  const valid = ['draft', 'published', 'archived'].includes(status ?? '') ? status! : '';
  const courses = await raw(`${COURSE_SELECT} ${valid ? 'WHERE c.status = $1' : ''} ORDER BY c.status = 'published' DESC, c.created_at DESC`, valid ? [valid] : []);
  return (
    <>
      <PageHead title="Courses" sub="Create and edit courses, prices, curriculum and live batches.">
        <a href="/admin/courses/new" className="btn btn-primary btn-sm"><Icon name="add" /> New course</a>
      </PageHead>
      <div className="pn-toolbar">
        {[['', 'All'], ['published', 'Published'], ['draft', 'Draft'], ['archived', 'Archived']].map(([k, l]) => (
          <a key={k} href={k ? `/admin/courses?status=${k}` : '/admin/courses'} className={`btn btn-sm ${valid === k ? 'btn-primary' : 'btn-outline'}`}>{l}</a>
        ))}
      </div>
      <div className="data-table-wrap">
        <table className="data-table">
          <thead><tr><th>Course</th><th>Language</th><th>Teacher</th><th>Price</th><th>Students</th><th>Lessons</th><th>Status</th></tr></thead>
          <tbody>
            {courses.length === 0 ? <tr><td colSpan={7} className="pn-empty">No courses</td></tr> : courses.map(c => (
              <tr key={c.id}>
                <td><a className="pn-link" href={`/admin/courses/${c.id}`}>{c.title_bn}</a><div className="pn-muted">{c.type}{c.is_featured ? ' · featured' : ''} · /{c.slug}</div></td>
                <td>{c.cat_name_en}</td>
                <td>{c.teacher_name ?? <span className="pn-danger">none</span>}</td>
                <td>{c.is_free ? 'Free' : <><Money v={c.sale_price ?? c.price} />{c.sale_price != null && <div className="pn-muted"><s>৳{Number(c.price)}</s></div>}</>}</td>
                <td>{c.student_count}{c.max_students > 0 && <span className="pn-muted"> / {c.max_students}</span>}</td>
                <td>{c.lesson_count}</td>
                <td><Badge s={c.status} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
