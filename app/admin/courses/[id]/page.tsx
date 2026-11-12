import { notFound } from 'next/navigation';
import { one, q } from '@/lib/db';
import { requireAdmin } from '@/lib/auth';
import { curriculum } from '@/lib/courses';
import { PageHead, Badge, ActionButton } from '@/components/panel';
import { CurriculumEditor, LiveEditor } from '@/components/CurriculumEditor';
import { TeamEditor, DetailsEditor } from '@/components/CourseExtrasEditor';
import { courseTeam, courseDetails, TEACHER_ROLE_IDS } from '@/lib/team';

export const metadata = { title: 'Edit course' };

export default async function AdminCourseEdit({ params }: { params: Promise<{ id: string }> }) {
  await requireAdmin();
  const idParam = (await params).id;
  const isNew = idParam === 'new';
  const c = isNew ? null : await one`SELECT *, to_char(batch_start, 'YYYY-MM-DD') AS batch_date FROM courses WHERE id = ${Number(idParam) || 0}`;
  if (!isNew && !c) notFound();
  const [cats, teachers, modules, sessions, team, details] = await Promise.all([
    q`SELECT id, name_en FROM categories ORDER BY sort_order, id`,
    // Anyone with the teacher (or admin) role can be put on a course; a teacher profile is made on demand.
    q`SELECT u.id, u.name, u.email, tp.id AS tp_id FROM users u LEFT JOIN teacher_profiles tp ON tp.user_id = u.id
       WHERE u.role_id = ANY(${TEACHER_ROLE_IDS}) AND u.is_active ORDER BY u.role_id, u.name`,
    c ? curriculum(c.id, true) : [],
    c ? q`SELECT * FROM live_sessions WHERE course_id = ${c.id} ORDER BY scheduled_at DESC` : [],
    c ? courseTeam(c.id) : [],
    c ? courseDetails(c.id) : [],
  ]);
  const v = (k: string) => (c?.[k] ?? '') as string;

  return (
    <>
      <PageHead title={isNew ? 'New course' : c!.title_bn} sub={isNew ? 'Save the basics first; then add modules, lessons and live classes.' : <><Badge s={c!.status} /> · <a className="pn-link" href={`/course/${c!.slug}`} target="_blank">View on site ↗</a></>}>
        <a href="/admin/courses" className="btn btn-outline btn-sm">← All courses</a>
      </PageHead>

      <div className="pn-card">
        <h2>Course details</h2>
        <form method="post" action="/do/admin/course-save" className="pn-grid">
          <input type="hidden" name="id" value={c?.id ?? ''} />
          <div><label>Title (Bangla)</label><input name="title_bn" className="form-control" required maxLength={200} defaultValue={v('title_bn')} /></div>
          <div><label>Title (English)</label><input name="title_en" className="form-control" maxLength={200} defaultValue={v('title_en')} /></div>
          <div><label>URL slug</label><input name="slug" className="form-control" maxLength={200} pattern="[a-z0-9-]*" defaultValue={v('slug')} placeholder="made from the English title if empty" /></div>
          <div><label>Language</label>
            <select name="category_id" className="form-control" required defaultValue={c?.category_id ?? ''}>
              <option value="">Choose…</option>{cats.map(x => <option key={x.id} value={x.id}>{x.name_en}</option>)}
            </select></div>
          {isNew ? (
            <div><label>Lead teacher</label>
              <select name="teacher_id" className="form-control" defaultValue="">
                <option value="">— add later —</option>{teachers.filter(t => t.tp_id).map(t => <option key={t.tp_id} value={t.tp_id}>{t.name}</option>)}
              </select>
              <p className="pn-hint">After saving you can add more teachers in “Teaching team”.</p></div>
          ) : (
            <div><label>Teachers</label>
              <a href="#team" className="pn-team-peek">
                {team.length ? team.slice(0, 4).map(t => <span key={t.tp_id} title={t.name}>{t.name.split(' ')[0]}</span>) : <span>None yet</span>}
                <em>Manage team ↓</em>
              </a></div>
          )}
          <div><label>Status</label>
            <select name="status" className="form-control" defaultValue={c?.status ?? 'draft'}>
              {['draft', 'published', 'archived'].map(x => <option key={x} value={x}>{x}</option>)}
            </select></div>
          <div><label>Type</label>
            <select name="type" className="form-control" defaultValue={c?.type ?? 'recorded'}>
              {['recorded', 'live', 'hybrid'].map(x => <option key={x} value={x}>{x}</option>)}
            </select></div>
          <div><label>Level</label>
            <select name="level" className="form-control" defaultValue={c?.level ?? 'beginner'}>
              {['beginner', 'intermediate', 'advanced'].map(x => <option key={x} value={x}>{x}</option>)}
            </select></div>
          <div><label>Regular / previous price (BDT)</label><input name="price" type="number" min={0} step="1" className="form-control" defaultValue={c ? Number(c.price) : 0} /><p className="pn-hint">Shown crossed out, e.g. <s>৳6,500</s>. If there is no offer, this is the price.</p></div>
          <div><label>Offer / current price (BDT, optional)</label><input name="sale_price" type="number" min={0} step="1" className="form-control" defaultValue={c?.sale_price != null ? Number(c.sale_price) : ''} /><p className="pn-hint">What students pay, e.g. ৳4,999. The discount (−23%) shows on the course card, course page and checkout. Leave empty for no offer.</p></div>
          <div><label>Batch start date (live)</label><input name="batch_start" type="date" className="form-control" defaultValue={v('batch_date')} /></div>
          <div><label>Seat limit — max students</label><input name="max_students" type="number" min={0} max={10000} className="form-control" defaultValue={c?.max_students ?? 20} /><p className="pn-hint">Default 20. When the seats are full, enrolment and checkout close. 0 = no limit.</p></div>
          <div><label>Planned number of classes (shown before lessons are added)</label><input name="total_lessons" type="number" min={0} className="form-control" defaultValue={c?.total_lessons ?? 0} /></div>
          <div><label>Thumbnail image URL</label><input name="thumbnail" type="url" className="form-control" maxLength={500} defaultValue={v('thumbnail')} placeholder="https://…" /></div>
          <div><label>Intro / trailer video URL</label><input name="intro_video_url" type="url" className="form-control" maxLength={500} defaultValue={v('intro_video_url')} /></div>
          <div><label>Batch group link (Facebook / WhatsApp)</label><input name="fb_group_link" type="url" className="form-control" maxLength={500} defaultValue={v('fb_group_link')} /></div>
          <div className="pn-full"><label>Short description (one line)</label><input name="short_desc" className="form-control" maxLength={300} defaultValue={v('short_desc')} /></div>
          <div className="pn-full"><label>About this course</label><textarea name="description_bn" className="form-control" maxLength={20000} defaultValue={v('description_bn')}></textarea></div>
          <div><label>What you will learn (one per line)</label><textarea name="what_you_learn" className="form-control" maxLength={5000} defaultValue={v('what_you_learn')}></textarea></div>
          <div><label>Requirements (one per line)</label><textarea name="requirements" className="form-control" maxLength={5000} defaultValue={v('requirements')}></textarea></div>
          <label className="pn-check"><input type="checkbox" name="is_free" value="1" defaultChecked={c?.is_free ?? false} /> Free course</label>
          <label className="pn-check"><input type="checkbox" name="is_featured" value="1" defaultChecked={c?.is_featured ?? false} /> Featured (shown first)</label>
          <div className="pn-full"><button type="submit" className="btn btn-primary">{isNew ? 'Create course' : 'Save course'}</button></div>
        </form>
      </div>

      {c && (
        <>
          <TeamEditor courseId={c.id} team={team} teachers={teachers} />
          <DetailsEditor courseId={c.id} details={details} base="/do/course" />
          <CurriculumEditor courseId={c.id} modules={modules} base="/do/course" />
          <LiveEditor courseId={c.id} sessions={sessions} base="/do/course" />
          <div className="pn-card" style={{ borderColor: 'var(--c-error)' }}>
            <h2>Delete course</h2>
            <p className="pn-muted">Removes the course, its lessons, live classes and enrollments. Paid orders stay in Orders. To hide a course without losing data, set its status to archived instead.</p>
            <ActionButton action="/do/admin/course-delete" fields={{ id: c.id }} label="Delete course permanently" cls="btn btn-danger btn-sm" confirm="Delete this course permanently? This cannot be undone." />
          </div>
        </>
      )}
    </>
  );
}
