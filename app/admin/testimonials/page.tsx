import { q } from '@/lib/db';
import type { Row } from '@/lib/db';
import { requireAdmin } from '@/lib/auth';
import { avatarUrl } from '@/lib/format';
import { PageHead, Badge, ActionButton } from '@/components/panel';
import { PhotoInput } from '@/components/PhotoInput';

export const metadata = { title: 'Testimonials' };

const KINDS: Record<string, string> = { student: 'Student', guardian: 'Guardian / parent', teacher: 'Teacher' };

function TestimonialForm({ t, courses }: { t?: Row; courses: Row[] }) {
  return (
    <form method="post" action="/do/admin/testimonial-save" className="pn-grid" encType="multipart/form-data">
      {t && <input type="hidden" name="id" value={t.id} />}
      <div><label>Name</label><input name="name" className="form-control" required maxLength={100} defaultValue={t?.name ?? ''} placeholder="Nusrat Jahan" /></div>
      <div><label>Who is speaking</label>
        <select name="kind" className="form-control" defaultValue={t?.kind ?? 'student'}>
          {Object.entries(KINDS).map(([k, n]) => <option key={k} value={k}>{n}</option>)}
        </select></div>
      <div><label>Line under the name (optional)</label><input name="subtitle" className="form-control" maxLength={150} defaultValue={t?.batch ?? ''} placeholder="HSK 1 batch · Dhaka  /  Parent of Ayaan" /></div>
      <div><label>Course (optional — also shows on that course page)</label>
        <select name="course_id" className="form-control" defaultValue={t?.course_id ?? ''}>
          <option value="">— none —</option>
          {courses.map(c => <option key={c.id} value={c.id}>{c.title_bn}</option>)}
        </select></div>
      <div className="pn-full"><label>Their words</label><textarea name="text" className="form-control" required maxLength={1200} rows={4} defaultValue={t?.text ?? ''}></textarea></div>
      <div><label>Rating</label>
        <select name="rating" className="form-control" defaultValue={t?.rating ?? 5}>
          {[5, 4, 3, 2, 1].map(n => <option key={n} value={n}>{'★'.repeat(n)} ({n})</option>)}
        </select></div>
      <div><label>Order (higher shows first)</label><input name="sort_order" type="number" min={0} max={999} className="form-control" defaultValue={t?.sort_order ?? 0} /></div>
      <div className="pn-full"><label>Photo (optional)</label><PhotoInput current={t?.photo ?? null} name={t?.name ?? 'LH'} /></div>
      <label className="pn-check pn-full"><input type="checkbox" name="is_approved" value="1" defaultChecked={t ? t.is_approved : true} /> Published (shown on the website)</label>
      <div className="pn-full"><button type="submit" className="btn btn-primary">{t ? 'Save testimonial' : 'Add testimonial'}</button></div>
    </form>
  );
}

export default async function TestimonialsAdmin() {
  await requireAdmin();
  const [items, courses] = await Promise.all([
    q`SELECT t.*, c.title_bn AS course_title FROM testimonials t LEFT JOIN courses c ON c.id = t.course_id ORDER BY t.sort_order DESC, t.created_at DESC`,
    q`SELECT id, title_bn FROM courses WHERE status <> 'archived' ORDER BY title_bn`,
  ]);
  return (
    <>
      <PageHead title="Testimonials" sub="Words from students, guardians and teachers. Published ones show on the home page (and on the course page when a course is chosen)." />
      <div className="pn-card">
        <h2>Add a testimonial</h2>
        <TestimonialForm courses={courses} />
      </div>
      {items.length === 0 ? <div className="pn-card pn-empty">No testimonials yet.</div> : (
        <div className="pn-quotes">
          {items.map(t => (
            <article key={t.id} className={`pn-card pn-quote ${t.is_approved ? '' : 'is-hidden'}`}>
              <header>
                <img src={avatarUrl(t.photo, t.name)} alt="" width={48} height={48} />
                <div>
                  <b>{t.name}</b>
                  <small>{KINDS[t.kind] ?? t.kind}{t.batch ? ` · ${t.batch}` : ''}</small>
                </div>
                <Badge s={t.is_approved ? 'published' : 'draft'} />
              </header>
              <p className="pn-quote-stars" aria-label={`${t.rating}/5`}>{'★'.repeat(t.rating)}<span>{'★'.repeat(5 - t.rating)}</span></p>
              <blockquote>{t.text}</blockquote>
              {t.course_title && <p className="pn-muted">Course: {t.course_title}</p>}
              <div className="pn-actions" style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                <ActionButton action="/do/admin/testimonial-toggle" fields={{ id: t.id }} label={t.is_approved ? 'Unpublish' : 'Publish'} />
                <ActionButton action="/do/admin/testimonial-delete" fields={{ id: t.id }} label="Delete" cls="btn btn-outline btn-xs pn-danger" confirm="Delete this testimonial?" />
              </div>
              <details className="pn-quote-edit">
                <summary>Edit</summary>
                <TestimonialForm t={t} courses={courses} />
              </details>
            </article>
          ))}
        </div>
      )}
    </>
  );
}
