import { requireTeacherProfile, myCourses } from '@/lib/teacher';
import { PageHead, Badge } from '@/components/panel';

export const metadata = { title: 'My courses' };

export default async function TeacherCourses() {
  const { tp } = await requireTeacherProfile();
  const courses = await myCourses(tp.id);
  return (
    <>
      <PageHead title="My courses" sub="Add lessons, schedule live classes and see your students. Prices and publishing are managed by the admin." />
      <div className="data-table-wrap">
        <table className="data-table">
          <thead><tr><th>Course</th><th>Type</th><th>Lessons</th><th>Students</th><th>Status</th></tr></thead>
          <tbody>
            {courses.length === 0 ? <tr><td colSpan={5} className="pn-empty">No courses assigned to you yet</td></tr> : courses.map(c => (
              <tr key={c.id}>
                <td><a className="pn-link" href={`/teacher/courses/${c.id}`}>{c.title_bn}</a><div className="pn-muted">{c.cat_name_en}</div></td>
                <td>{c.type}</td><td>{c.lessons}</td><td>{c.students}</td><td><Badge s={c.status} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
