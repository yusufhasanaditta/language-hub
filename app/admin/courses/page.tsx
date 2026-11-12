import { raw } from '@/lib/db';
import { requireAdmin } from '@/lib/auth';
import { COURSE_SELECT } from '@/lib/courses';
import { PageHead, PanelCourseTicket } from '@/components/panel';
import { Icon } from '@/components/ui';

export const metadata = { title: 'Courses' };

export default async function AdminCourses({ searchParams }: { searchParams: Promise<{ status?: string }> }) {
  await requireAdmin();
  const status = (await searchParams).status;
  const valid = ['draft', 'published', 'archived'].includes(status ?? '') ? status! : '';
  const courses = await raw(`${COURSE_SELECT} ${valid ? 'WHERE c.status = $1' : ''} ORDER BY c.status = 'published' DESC, c.created_at DESC`, valid ? [valid] : []);
  return (
    <>
      <PageHead eyebrow="Flights" title="Courses" sub="Every course is a ticket: price, teacher, seats (default 20) and curriculum live inside.">
        <a href="/admin/courses/new" className="btn btn-primary btn-sm"><Icon name="add" /> New course</a>
      </PageHead>
      <div className="pn-toolbar">
        {[['', 'All'], ['published', 'Published'], ['draft', 'Draft'], ['archived', 'Archived']].map(([k, l]) => (
          <a key={k} href={k ? `/admin/courses?status=${k}` : '/admin/courses'} className={`btn btn-sm ${valid === k ? 'btn-primary' : 'btn-outline'}`}>{l}</a>
        ))}
      </div>
      {courses.length === 0 ? <div className="pn-card pn-empty">No courses here yet</div> : (
        <div className="pn-tickets">
          {courses.map(c => <PanelCourseTicket key={c.id} c={c} href={`/admin/courses/${c.id}`} showPrice />)}
        </div>
      )}
    </>
  );
}
