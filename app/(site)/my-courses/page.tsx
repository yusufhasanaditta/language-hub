import type { Metadata } from 'next';
import { q, type Row } from '@/lib/db';
import { requireUser } from '@/lib/auth';
import { T, N } from '@/lib/i18n';
import { Icon, ListHead } from '@/components/ui';
import { MyCourseRow } from '@/components/student';

export const metadata: Metadata = { title: 'আমার কোর্স' };

const TABS = { all: ['সব', 'All'], active: ['চলছে', 'In progress'], done: ['সম্পন্ন', 'Completed'] } as const;

export default async function MyCoursesPage({ searchParams }: { searchParams: Promise<{ tab?: string }> }) {
  const user = await requireUser();
  const tabParam = (await searchParams).tab;
  const tab = tabParam === 'active' || tabParam === 'done' ? tabParam : 'all';
  const all = await q`
    SELECT c.id, c.title_bn, c.title_en, e.progress_pct, cat.slug AS cat_slug, cat.name_bn AS cat_name, cat.name_en AS cat_name_en, u.name AS teacher_name
    FROM enrollments e JOIN courses c ON c.id = e.course_id JOIN categories cat ON cat.id = c.category_id
    LEFT JOIN teacher_profiles tp ON tp.id = c.teacher_id LEFT JOIN users u ON u.id = tp.user_id
    WHERE e.user_id = ${user.id} AND e.status = 'active' ORDER BY e.enrolled_at DESC`;
  const isDone = (c: Row) => Number(c.progress_pct) >= 100;
  const counts = { all: all.length, active: all.filter(c => !isDone(c)).length, done: all.filter(isDone).length };
  const courses = tab === 'active' ? all.filter(c => !isDone(c)) : tab === 'done' ? all.filter(isDone) : all;

  return (
    <>
      <ListHead back={['/dashboard', 'প্রোফাইল', 'Profile']} title={<T bn="আমার কোর্স" en="My courses" />} />
      <div className="lh-filters">
        <div className="lh-chips">
          {(Object.keys(TABS) as (keyof typeof TABS)[]).map(k => (
            <a key={k} href={k === 'all' ? '/my-courses' : `/my-courses?tab=${k}`} className={`lh-fchip ${tab === k ? 'is-on' : ''}`}>
              <T bn={TABS[k][0]} en={TABS[k][1]} /> <span className="lh-fchip-n"><N v={counts[k]} /></span>
            </a>
          ))}
        </div>
      </div>
      <section className="container lh-list-body">
        {courses.length ? (
          <div className="lh-mycourses" data-reveal-group>
            {courses.map(c => <MyCourseRow key={c.id} c={c} meta={<><T bn={c.cat_name} en={c.cat_name_en} />{c.teacher_name ? ` · ${c.teacher_name}` : ''}</>} />)}
          </div>
        ) : (
          <div className="lh-empty" data-reveal>
            <Icon name="school" />
            <h2>{tab === 'all' ? <T bn="এখনো কোনো কোর্সে ভর্তি হননি" en="No courses yet" /> : <T bn="এখানে কিছু নেই" en="Nothing here" />}</h2>
            <a href="/courses" className="btn btn-primary"><T bn="কোর্স দেখুন" en="Browse courses" /></a>
          </div>
        )}
      </section>
    </>
  );
}
