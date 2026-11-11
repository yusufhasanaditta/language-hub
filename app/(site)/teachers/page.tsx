import type { Metadata } from 'next';
import { q } from '@/lib/db';
import { T, N } from '@/lib/i18n';
import { avatarUrl } from '@/lib/format';
import { teacherUrl } from '@/lib/courses';
import { ListHead, Empty } from '@/components/ui';

export const metadata: Metadata = { title: 'শিক্ষকবৃন্দ — Teachers' };

export default async function TeachersPage() {
  const teachers = await q`
    SELECT u.id, u.name, u.username, u.avatar, tp.designation, tp.headline,
           (SELECT count(*)::int FROM courses c WHERE c.teacher_id = tp.id AND c.status = 'published') AS courses
    FROM teacher_profiles tp JOIN users u ON u.id = tp.user_id
    WHERE u.is_active AND u.role_id = 2
    ORDER BY tp.featured_order DESC, courses DESC, u.name`;
  return (
    <>
      <ListHead title={<T bn="আমাদের শিক্ষকবৃন্দ" en="Our teachers" />} />
      <section className="container lh-list-body">
        {teachers.length === 0 ? <Empty icon="school" bn="এখনো কোনো শিক্ষক নেই" en="No teachers yet" /> : (
          <div className="lh-teachers" data-reveal-group style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 16 }}>
            {teachers.map(t => (
              <a key={t.id} className="lh-teacher lh-c-card" href={teacherUrl(t)}>
                <img src={avatarUrl(t.avatar, t.name)} alt="" width={52} height={52} loading="lazy" />
                <span>
                  <b>{t.name}</b>
                  <small>{t.designation ?? t.headline ?? ''}</small>
                  <small><N v={t.courses} /> <T bn="টি কোর্স" en="courses" /></small>
                </span>
              </a>
            ))}
          </div>
        )}
      </section>
    </>
  );
}
