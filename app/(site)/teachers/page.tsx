import type { Metadata } from 'next';
import { q } from '@/lib/db';
import { T } from '@/lib/i18n';
import { ListHead, Empty } from '@/components/ui';
import { TeacherTicket } from '@/components/TeacherTicket';

export const metadata: Metadata = { title: 'শিক্ষকবৃন্দ — Teachers' };

export default async function TeachersPage() {
  const teachers = await q`
    SELECT u.id, u.name, u.username, u.avatar, tp.id AS tp_id, tp.designation, tp.headline,
           (SELECT count(*)::int FROM courses c WHERE c.teacher_id = tp.id AND c.status = 'published') AS courses,
           (SELECT count(DISTINCT e.user_id)::int FROM enrollments e JOIN courses c ON c.id = e.course_id
             WHERE c.teacher_id = tp.id AND e.status = 'active') AS students,
           (SELECT cat.slug FROM courses c JOIN categories cat ON cat.id = c.category_id
             WHERE c.teacher_id = tp.id GROUP BY cat.slug ORDER BY count(*) DESC LIMIT 1) AS cat_slug
    FROM teacher_profiles tp JOIN users u ON u.id = tp.user_id
    WHERE u.is_active AND u.role_id = 2
    ORDER BY tp.featured_order DESC, courses DESC, u.name`;
  return (
    <>
      <ListHead title={<T bn="আমাদের শিক্ষকবৃন্দ" en="Our teachers" />} />
      <section className="container lh-list-body">
        {teachers.length === 0 ? <Empty icon="school" bn="এখনো কোনো শিক্ষক নেই" en="No teachers yet" /> : (
          <div className="lh-crew-grid" data-reveal-group>
            {teachers.map(t => <TeacherTicket key={t.id} t={t} />)}
          </div>
        )}
      </section>
    </>
  );
}
