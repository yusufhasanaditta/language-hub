import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { q, one } from '@/lib/db';
import { courseBy, curriculum, seatsLeft } from '@/lib/courses';
import { currentUser } from '@/lib/auth';
import { T, N, DateBn, bnNum } from '@/lib/i18n';
import { lang, langStyle } from '@/lib/langs';
import { priceOf, isFreeCourse, formatPrice, avatarUrl, lines, embedUrl } from '@/lib/format';
import { Countdown, Icon, Price } from '@/components/ui';
import { Journey } from '@/components/Journey';
import { TeacherTicket } from '@/components/TeacherTicket';
import { Glance, InfoSections, CourseFaq } from '@/components/CourseDetails';
import { courseTeam, courseDetails, byKind } from '@/lib/team';

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
  const [modules, reviews, enrolled, team, details] = await Promise.all([
    curriculum(c.id),
    q`SELECT r.rating, r.body, u.name, u.avatar FROM reviews r JOIN users u ON u.id = r.user_id
      WHERE r.course_id = ${c.id} AND r.is_approved ORDER BY r.created_at DESC LIMIT 6`,
    user ? one<{ id: number }>`SELECT id FROM enrollments WHERE user_id = ${user.id} AND course_id = ${c.id} AND status = 'active'` : null,
    courseTeam(c.id, { catSlug: c.cat_slug, withStats: true }),
    courseDetails(c.id),
  ]);
  const done = new Set(enrolled
    ? (await q<{ lesson_id: number }>`SELECT lesson_id FROM lesson_progress WHERE enrollment_id = ${enrolled.id} AND completed`).map(r => r.lesson_id)
    : []);

  const L = lang(c.cat_slug);
  const [now, was] = priceOf(c);
  const free = isFreeCourse(c);
  const live = c.type === 'live';
  const seats = seatsLeft(c);
  const full = seats === 0;
  const lessonCount = modules.reduce((n, m) => n + m.lessons.length, 0) || c.lesson_count;
  const learn = lines(c.what_you_learn);
  const reqs = lines(c.requirements);
  const level = LEVEL[c.level] ?? LEVEL.beginner;
  const startsLater = !!c.batch_date && new Date(`${c.batch_date}T00:00:00+06:00`).getTime() > Date.now();

  const cta = enrolled ? (
    <a href={`/learn?course=${c.id}`} className="btn btn-primary lh-btn-block"><Icon name="play_circle" /><T bn="ক্লাসরুমে যান" en="Go to classroom" /></a>
  ) : full ? (
    <button type="button" className="btn btn-outline lh-btn-block" disabled><T bn="সব সিট পূর্ণ" en="Course is full" /></button>
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
  // The lightbox needs an embeddable URL (a plain YouTube link refuses to load in a frame).
  const video = embedUrl(c.intro_video_url);
  const freeVideo = embedUrl(modules.flatMap(m => m.lessons).find(l => l.is_free && l.video_url)?.video_url);

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
          <div className="lh-c-meta" data-countup>
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
            {!video && freeVideo && <button type="button" className="lh-play" data-video={freeVideo} aria-label="Play free class"><Icon name="play_arrow" /></button>}
          </div>

          {live && c.batch_date && (
            <section className="lh-c-card" data-reveal>
              <h2><T bn="ব্যাচের তথ্য" en="Batch details" /></h2>
              <dl className="lh-pass-grid lh-c-grid">
                <div><dt><T bn="শুরু" en="Starts" /></dt><dd><DateBn date={c.batch_date} /></dd></div>
                <div><dt><T bn="ক্লাস" en="Classes" /></dt><dd><N v={lessonCount} /></dd></div>
                <div><dt><T bn="সিট বাকি" en="Seats left" /></dt>
                  <dd className={seats !== null && seats <= 5 ? 'lh-hot' : ''}>{seats === null ? '—' : <><N v={seats} />/<N v={c.max_students} /></>}</dd></div>
              </dl>
              {startsLater && <Countdown to={`${c.batch_date}T00:00:00+06:00`} />}
            </section>
          )}

          <Glance facts={byKind(details, 'fact')} />

          {learn.length > 0 && (
            <section className="lh-c-card" data-reveal>
              <h2><T bn="যা শিখবেন" en="What you will learn" /></h2>
              <ul className="lh-ticks">{learn.map((l, i) => <li key={i}>{l}</li>)}</ul>
            </section>
          )}

          {modules.some(m => m.lessons.length > 0) && (
            <Journey courseId={c.id} modules={modules.filter(m => m.lessons.length > 0)} catSlug={c.cat_slug} enrolled={!!enrolled} done={done} />
          )}

          {team.length > 0 && (
            <section className="lh-c-card" data-reveal>
              <h2>{team.length > 1 ? <T bn="আপনার শিক্ষক দল" en="Your teaching team" /> : <T bn="আপনার শিক্ষক" en="Your teacher" />}</h2>
              <div className="lh-crew-list" data-reveal-group>
                {team.map(t => <TeacherTicket key={t.tp_id} t={t} />)}
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

          <InfoSections sections={byKind(details, 'section')} />
          <CourseFaq faqs={byKind(details, 'faq')} />

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
            {seats !== null && !enrolled && (
              <div className={`lh-seats ${seats <= 5 ? 'is-hot' : ''}`}>
                <div className="lh-seats-row">
                  <span><Icon name="event_seat" />{full ? <T bn="সব সিট পূর্ণ" en="No seats left" />
                    : <T bn={`${bnNum(seats)}টি সিট বাকি`} en={`${seats} seats left`} />}</span>
                  <small><N v={c.student_count} />/<N v={c.max_students} /></small>
                </div>
                <div className="lh-seats-bar" aria-hidden="true"><span style={{ width: `${Math.min(100, (c.student_count / c.max_students) * 100)}%` }}></span></div>
              </div>
            )}
            {cta}
            <ul className="lh-c-perks">
              <li><Icon name="translate" /><T bn="সব ক্লাস বাংলায়" en="All classes in Bangla" /></li>
              {c.max_students > 0 && <li><Icon name="groups" /><T bn={`সর্বোচ্চ ${bnNum(c.max_students)} জন শিক্ষার্থী`} en={`Max ${c.max_students} students`} /></li>}
              {live ? <li><Icon name="videocam" /><T bn="প্রতিটি ক্লাসের রেকর্ডিং" en="Every class recorded" /></li>
                : <li><Icon name="all_inclusive" /><T bn="যখন খুশি দেখুন" en="Watch any time" /></li>}
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
