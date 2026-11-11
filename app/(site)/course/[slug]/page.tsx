import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { q, one } from '@/lib/db';
import { courseBy, curriculum, teacherUrl } from '@/lib/courses';
import { currentUser } from '@/lib/auth';
import { T, N, DateBn } from '@/lib/i18n';
import { lang, langStyle } from '@/lib/langs';
import { priceOf, isFreeCourse, formatPrice, avatarUrl, lines } from '@/lib/format';
import { Countdown, Icon, Price } from '@/components/ui';

type Props = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const c = await courseBy('slug', decodeURIComponent((await params).slug));
  return c ? { title: c.title_bn, description: c.short_desc ?? undefined } : { title: 'Course not found' };
}

const LEVEL: Record<string, [string, string]> = {
  beginner: ['শুরু থেকে', 'Beginner'], intermediate: ['মাঝারি', 'Intermediate'], advanced: ['অ্যাডভান্সড', 'Advanced'],
};

export default async function CoursePage({ params }: Props) {
  const c = await courseBy('slug', decodeURIComponent((await params).slug));
  if (!c) notFound();
  const user = await currentUser();
  const [modules, reviews, enrolled] = await Promise.all([
    curriculum(c.id),
    q`SELECT r.rating, r.body, u.name, u.avatar FROM reviews r JOIN users u ON u.id = r.user_id
      WHERE r.course_id = ${c.id} AND r.is_approved ORDER BY r.created_at DESC LIMIT 6`,
    user ? one`SELECT id FROM enrollments WHERE user_id = ${user.id} AND course_id = ${c.id} AND status = 'active'` : null,
  ]);

  const L = lang(c.cat_slug);
  const [now, was] = priceOf(c);
  const free = isFreeCourse(c);
  const live = c.type === 'live';
  const seatsLeft = live && c.max_students > 0 ? Math.max(0, c.max_students - c.student_count) : null;
  const full = seatsLeft === 0;
  const lessonCount = modules.reduce((n, m) => n + m.lessons.length, 0) || c.lesson_count;
  const learn = lines(c.what_you_learn);
  const reqs = lines(c.requirements);
  const level = LEVEL[c.level] ?? LEVEL.beginner;
  const startsLater = !!c.batch_date && new Date(`${c.batch_date}T00:00:00+06:00`).getTime() > Date.now();

  const cta = enrolled ? (
    <a href={`/learn?course=${c.id}`} className="btn btn-primary lh-btn-block"><Icon name="play_circle" /><T bn="ক্লাসরুমে যান" en="Go to classroom" /></a>
  ) : full ? (
    <button type="button" className="btn btn-outline lh-btn-block" disabled><T bn="সব সিট পূর্ণ" en="Batch is full" /></button>
  ) : free ? (
    <form method="post" action="/do/enroll">
      <input type="hidden" name="course_id" value={c.id} />
      <button type="submit" className="btn btn-primary lh-btn-block"><T bn="ফ্রি ভর্তি হোন" en="Enrol free" /></button>
    </form>
  ) : (
    <a href={`/checkout?course=${c.id}`} className="btn btn-accent lh-btn-block">
      {live ? <T bn="সিট বুক করুন" en="Book a seat" /> : <T bn="এখনই ভর্তি হোন" en="Enrol now" />}
    </a>
  );
  const price = <Price now={now} was={was} free={free} />;
  const video = c.intro_video_url;

  return (
    <>
      <section className="lh-c-hero" style={langStyle(c.cat_slug)}>
        <span className="lh-c-glyph" aria-hidden="true">{L.glyph}</span>
        <div className="container lh-c-hero-in" data-reveal>
          <a href={`/category/${c.cat_slug}`} className="lh-c-back"><Icon name="arrow_back" /><T bn={c.cat_name} en={c.cat_name_en} /></a>
          <div className="lh-c-chips">
            {live ? <span className="lh-chip lh-chip-live"><i></i><T bn="লাইভ ব্যাচ" en="Live batch" /></span>
              : <span className="lh-chip"><T bn="রেকর্ডেড" en="Recorded" /></span>}
            <span className="lh-chip"><T bn={level[0]} en={level[1]} /></span>
            {L.exam && <span className="lh-chip">{L.exam}</span>}
          </div>
          <h1 className="lh-c-title"><T bn={c.title_bn} en={c.title_en || c.title_bn} /></h1>
          {c.short_desc && <p className="lh-c-desc">{c.short_desc}</p>}
          <div className="lh-c-meta">
            {c.rating_count > 0 && <span><Icon name="star" className="lh-star" /><b><N v={Number(c.rating_avg).toFixed(1)} /></b>&nbsp;(<N v={c.rating_count} />)</span>}
            <span><Icon name="group" /><N v={c.student_count} /> <T bn="শিক্ষার্থী" en="learners" /></span>
            <span><Icon name="play_lesson" /><N v={lessonCount} /> <T bn="ক্লাস" en="lessons" /></span>
          </div>
        </div>
      </section>

      <div className="container lh-c-layout">
        <div className="lh-c-main">
          <div className="lh-c-media" style={langStyle(c.cat_slug)} data-reveal>
            {c.thumbnail && /^https?:\/\//.test(c.thumbnail) ? <img src={c.thumbnail} alt="" />
              : <><span className="lh-ticket-glyph" aria-hidden="true">{L.glyph}</span><span className="lh-c-media-hello" aria-hidden="true">{L.hello}</span></>}
            {video && <button type="button" className="lh-play" data-video={video} aria-label="Play trailer"><Icon name="play_arrow" /></button>}
          </div>

          {live && c.batch_date && (
            <section className="lh-c-card" data-reveal>
              <h2><T bn="ব্যাচের তথ্য" en="Batch details" /></h2>
              <dl className="lh-pass-grid lh-c-grid">
                <div><dt><T bn="শুরু" en="Starts" /></dt><dd><DateBn date={c.batch_date} /></dd></div>
                <div><dt><T bn="ক্লাস" en="Classes" /></dt><dd><N v={lessonCount} /></dd></div>
                <div><dt><T bn="সিট বাকি" en="Seats left" /></dt>
                  <dd className={seatsLeft !== null && seatsLeft <= 5 ? 'lh-hot' : ''}>{seatsLeft === null ? '—' : <><N v={seatsLeft} />/<N v={c.max_students} /></>}</dd></div>
              </dl>
              {startsLater && <Countdown to={`${c.batch_date}T00:00:00+06:00`} />}
            </section>
          )}

          {learn.length > 0 && (
            <section className="lh-c-card" data-reveal>
              <h2><T bn="যা শিখবেন" en="What you will learn" /></h2>
              <ul className="lh-ticks">{learn.map((l, i) => <li key={i}>{l}</li>)}</ul>
            </section>
          )}

          {modules.length > 0 && (
            <section className="lh-c-card" data-reveal>
              <h2><T bn="কোর্স কারিকুলাম" en="Curriculum" /> <small><N v={modules.length} /> <T bn="অধ্যায়" en="modules" /> · <N v={lessonCount} /> <T bn="ক্লাস" en="lessons" /></small></h2>
              <div className="lh-faq lh-curr">
                {modules.map((m, i) => (
                  <details key={m.id} open={i === 0}>
                    <summary><span><span className="lh-curr-n"><N v={i + 1} /></span>{m.title}</span><Icon name="add" /></summary>
                    <ol className="lh-curr-list lh-faq-a">
                      {m.lessons.map(ls => (
                        <li key={ls.id}>
                          <Icon name={ls.kind === 'pdf' ? 'description' : 'play_circle'} />
                          <span className="lh-curr-t">{ls.title}</span>
                          {ls.is_free ? <span className="lh-chip lh-chip-free"><T bn="ফ্রি" en="Free" /></span>
                            : !enrolled ? <span className="material-icons-round lh-lock" aria-label="Locked">lock</span> : null}
                          {ls.duration > 0 && <small><N v={ls.duration} />m</small>}
                        </li>
                      ))}
                    </ol>
                  </details>
                ))}
              </div>
            </section>
          )}

          {c.teacher_name && (
            <section className="lh-c-card" data-reveal>
              <h2><T bn="শিক্ষক" en="Teacher" /></h2>
              <div className="lh-teachers">
                <a className="lh-teacher" href={teacherUrl(c)}>
                  <img src={avatarUrl(c.teacher_avatar, c.teacher_name)} alt="" width={52} height={52} loading="lazy" />
                  <span><b>{c.teacher_name}</b><small>{c.designation ?? ''}</small></span>
                </a>
              </div>
            </section>
          )}

          {c.description_bn && (
            <section className="lh-c-card" data-reveal>
              <h2><T bn="কোর্স সম্পর্কে" en="About this course" /></h2>
              <div className="lh-prose" style={{ whiteSpace: 'pre-line' }}>{c.description_bn.replace(/<[^>]*>/g, '')}</div>
            </section>
          )}

          {reqs.length > 0 && (
            <section className="lh-c-card" data-reveal>
              <h2><T bn="যা লাগবে" en="Requirements" /></h2>
              <ul className="lh-ticks lh-ticks-muted">{reqs.map((r, i) => <li key={i}>{r}</li>)}</ul>
            </section>
          )}

          {reviews.length > 0 && (
            <section className="lh-c-card" data-reveal>
              <h2><T bn="শিক্ষার্থীদের মতামত" en="What learners say" /></h2>
              <div className="lh-reviews">
                {reviews.map((rv, i) => {
                  const stars = Math.max(0, Math.min(5, rv.rating));
                  return (
                    <figure className="lh-review" key={i}>
                      <div className="lh-review-stars" aria-label={`${stars}/5`}>{'★'.repeat(stars)}<span>{'★'.repeat(5 - stars)}</span></div>
                      <blockquote>{rv.body}</blockquote>
                      <figcaption><img src={avatarUrl(rv.avatar, rv.name)} alt="" width={28} height={28} loading="lazy" />{rv.name}</figcaption>
                    </figure>
                  );
                })}
              </div>
            </section>
          )}

          {enrolled && <ReviewForm courseId={c.id} />}
        </div>

        <aside className="lh-c-side">
          <div className="lh-c-buy" data-reveal="right">
            <div className="lh-c-buy-price">{price}</div>
            {was && <p className="lh-c-save"><T bn="সাশ্রয়" en="You save" /> ৳<N v={formatPrice(was - now)} /></p>}
            {cta}
            <ul className="lh-c-perks">
              <li><Icon name="translate" /><T bn="সব ক্লাস বাংলায়" en="All classes in Bangla" /></li>
              {live ? (
                <>
                  <li><Icon name="groups" /><T bn="ব্যাচে সর্বোচ্চ ১৫ জন" en="Max 15 per batch" /></li>
                  <li><Icon name="videocam" /><T bn="প্রতিটি ক্লাসের রেকর্ডিং" en="Every class recorded" /></li>
                </>
              ) : <li><Icon name="all_inclusive" /><T bn="যখন খুশি দেখুন" en="Watch any time" /></li>}
              <li><Icon name="devices" /><T bn="২টি ডিভাইসে চলবে" en="Works on 2 devices" /></li>
            </ul>
          </div>
        </aside>
      </div>

      <div className="lh-buybar">
        <div className="lh-buybar-price">{price}</div>
        <div className="lh-buybar-cta">{cta}</div>
      </div>
    </>
  );
}

function ReviewForm({ courseId }: { courseId: number }) {
  return (
    <section className="lh-c-card" data-reveal>
      <h2><T bn="আপনার মতামত দিন" en="Rate this course" /></h2>
      <form method="post" action="/do/review" className="lh-form">
        <input type="hidden" name="course_id" value={courseId} />
        <label htmlFor="rating"><T bn="রেটিং" en="Rating" /></label>
        <select id="rating" name="rating" className="lh-input" defaultValue="5" required>
          {[5, 4, 3, 2, 1].map(n => <option key={n} value={n}>{'★'.repeat(n)} ({n})</option>)}
        </select>
        <label htmlFor="body"><T bn="মন্তব্য" en="Comment" /></label>
        <textarea id="body" name="body" className="lh-input" rows={3} maxLength={1000}></textarea>
        <button type="submit" className="btn btn-primary"><T bn="জমা দিন" en="Submit" /></button>
      </form>
    </section>
  );
}
