import { notFound } from 'next/navigation';
import { one, q } from '@/lib/db';
import { isAdmin } from '@/lib/auth';
import { requireTeacherProfile } from '@/lib/teacher';
import { curriculum } from '@/lib/courses';
import { fmtDate } from '@/lib/format';
import { PageHead, Badge } from '@/components/panel';
import { CurriculumEditor, LiveEditor } from '@/components/CurriculumEditor';

export const metadata = { title: 'Course' };

export default async function TeacherCourse({ params }: { params: Promise<{ id: string }> }) {
  const { user, tp } = await requireTeacherProfile();
  const id = Number((await params).id) || 0;
  const c = await one`SELECT * FROM courses WHERE id = ${id}`;
  // Teachers see only their own courses (admins may look at any).
  if (!c || (c.teacher_id !== tp.id && !isAdmin(user))) notFound();
  const [modules, sessions, students] = await Promise.all([
    curriculum(c.id, true),
    q`SELECT * FROM live_sessions WHERE course_id = ${c.id} ORDER BY scheduled_at DESC`,
    q`SELECT u.name, u.email, u.phone, e.progress_pct, e.enrolled_at FROM enrollments e JOIN users u ON u.id = e.user_id
      WHERE e.course_id = ${c.id} AND e.status = 'active' ORDER BY e.enrolled_at DESC`,
  ]);
  return (
    <>
      <PageHead title={c.title_bn} sub={<><Badge s={c.status} /> · {c.type} · <a className="pn-link" href={`/learn?course=${c.id}`}>Preview classroom ↗</a></>}>
        <a href="/teacher/courses" className="btn btn-outline btn-sm">← My courses</a>
      </PageHead>

      <div className="pn-card">
        <h2>Course page text</h2>
        <form method="post" action="/do/teacher/course-text" className="pn-grid">
          <input type="hidden" name="course_id" value={c.id} />
          <div className="pn-full"><label>Short description</label><input name="short_desc" className="form-control" maxLength={300} defaultValue={c.short_desc ?? ''} /></div>
          <div className="pn-full"><label>About this course</label><textarea name="description_bn" className="form-control" maxLength={20000} defaultValue={c.description_bn ?? ''}></textarea></div>
          <div><label>What you will learn (one per line)</label><textarea name="what_you_learn" className="form-control" maxLength={5000} defaultValue={c.what_you_learn ?? ''}></textarea></div>
          <div><label>Requirements (one per line)</label><textarea name="requirements" className="form-control" maxLength={5000} defaultValue={c.requirements ?? ''}></textarea></div>
          <div><label>Intro video URL</label><input name="intro_video_url" type="url" className="form-control" maxLength={500} defaultValue={c.intro_video_url ?? ''} /></div>
          <div><label>Batch group link</label><input name="fb_group_link" type="url" className="form-control" maxLength={500} defaultValue={c.fb_group_link ?? ''} /></div>
          <div className="pn-full"><button className="btn btn-primary" type="submit">Save</button></div>
        </form>
      </div>

      <CurriculumEditor courseId={c.id} modules={modules} base="/do/course" />
      <LiveEditor courseId={c.id} sessions={sessions} base="/do/course" />

      <div className="pn-card" id="students">
        <h2>Students ({students.length})</h2>
        <table className="data-table">
          <thead><tr><th>Name</th><th>Contact</th><th>Progress</th><th>Joined</th></tr></thead>
          <tbody>
            {students.length === 0 ? <tr><td colSpan={4} className="pn-empty">No students yet</td></tr> : students.map((s, i) => (
              <tr key={i}><td>{s.name}</td><td className="pn-muted">{s.email}{s.phone ? ` · ${s.phone}` : ''}</td><td>{s.progress_pct}%</td><td className="pn-muted">{fmtDate(s.enrolled_at)}</td></tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
