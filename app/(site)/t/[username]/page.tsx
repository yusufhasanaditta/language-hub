import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { one } from '@/lib/db';
import { publishedCourses } from '@/lib/courses';
import { T } from '@/lib/i18n';
import { avatarUrl, safeUrl } from '@/lib/format';
import { CourseCard, Icon } from '@/components/ui';

type Props = { params: Promise<{ username: string }> };

async function load(username: string) {
  return one`SELECT u.id, u.name, u.avatar, u.bio, tp.id AS tp_id, tp.designation, tp.headline, tp.bio AS tp_bio,
                    tp.expertise, tp.qualifications, tp.fb_url, tp.yt_url, tp.website
             FROM users u JOIN teacher_profiles tp ON tp.user_id = u.id
             WHERE u.username = ${decodeURIComponent(username)} AND u.is_active`;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const t = await load((await params).username);
  return { title: t ? t.name : 'Teacher' };
}

export default async function TeacherProfilePage({ params }: Props) {
  const t = await load((await params).username);
  if (!t) notFound();
  const courses = await publishedCourses('AND c.id IN (SELECT course_id FROM course_teachers WHERE teacher_id = $1)', [t.tp_id]);
  const links = [['fb_url', 'Facebook'], ['yt_url', 'YouTube'], ['website', 'Website']]
    .map(([k, label]) => [safeUrl(t[k]), label] as const).filter(([u]) => u);
  const bio = t.tp_bio || t.bio;

  return (
    <>
      <section className="lh-dash-head">
        <div className="lh-glow" aria-hidden="true"></div>
        <div className="container lh-dash-me" data-reveal>
          <img src={avatarUrl(t.avatar, t.name)} alt="" width={64} height={64} />
          <div>
            <p className="lh-eyebrow"><T bn="শিক্ষক" en="Teacher" /></p>
            <h1>{t.name}</h1>
            <p className="lh-muted">{t.designation ?? t.headline ?? ''}</p>
          </div>
        </div>
      </section>
      <div className="container lh-dash">
        {(bio || t.expertise || t.qualifications) && (
          <section className="lh-c-card" data-reveal>
            <h2><T bn="পরিচিতি" en="About" /></h2>
            {bio && <p style={{ whiteSpace: 'pre-line' }}>{bio}</p>}
            {t.expertise && <p><b><T bn="দক্ষতা" en="Expertise" />:</b> {t.expertise}</p>}
            {t.qualifications && <p style={{ whiteSpace: 'pre-line' }}><b><T bn="যোগ্যতা" en="Qualifications" />:</b> {t.qualifications}</p>}
            {links.length > 0 && <p>{links.map(([u, label]) => <a key={label} className="lh-link" href={u!} target="_blank" rel="noopener"><Icon name="open_in_new" />{label} </a>)}</p>}
          </section>
        )}
        <section>
          <div className="lh-section-head" data-reveal><h2><T bn="কোর্সসমূহ" en="Courses" /></h2></div>
          {courses.length ? <div className="lh-grid" data-reveal-group>{courses.map(c => <CourseCard key={c.id} c={c} />)}</div>
            : <p className="lh-muted"><T bn="এখনো কোনো কোর্স নেই।" en="No courses yet." /></p>}
        </section>
      </div>
    </>
  );
}
