import { requireTeacherProfile, myCourses } from '@/lib/teacher';
import { PageHead, PanelCourseTicket } from '@/components/panel';

export const metadata = { title: 'My courses' };

export default async function TeacherCourses() {
  const { tp } = await requireTeacherProfile();
  const courses = await myCourses(tp.id);
  return (
    <>
      <PageHead eyebrow="Your flights" title="My courses" sub="Add classes, schedule live sessions and see your students. Prices, seats and publishing are managed by the admin." />
      {courses.length === 0 ? <div className="pn-card pn-empty">No courses assigned to you yet</div> : (
        <div className="pn-tickets">
          {courses.map(c => <PanelCourseTicket key={c.id} c={c} href={`/teacher/courses/${c.id}`} />)}
        </div>
      )}
    </>
  );
}
