import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { q, one, type Row } from '@/lib/db';
import { requireUser } from '@/lib/auth';
import { courseBy, curriculum, courseUrl } from '@/lib/courses';
import { T, N, DateBn, bnNum } from '@/lib/i18n';
import { lang, langStyle } from '@/lib/langs';
import { lessonThumb, youtubeId, embedUrl } from '@/lib/format';
import { Countdown, Icon } from '@/components/ui';
import { LiveCard } from '@/components/student';
import { canWatchCourse } from '@/lib/access';

export const metadata: Metadata = { title: 'ক্লাসরুম', robots: { index: false, follow: false } };

const KIND: Record<string, [string, string, string]> = {
  video: ['smart_display', 'ভিডিও', 'Video'], pdf: ['description', 'নোট', 'Notes'], text: ['article', 'পাঠ', 'Reading'],
  live: ['videocam', 'লাইভ', 'Live'], quiz: ['quiz', 'কুইজ', 'Quiz'],
};
const pad = (n: number) => String(n).padStart(2, '0');

type Props = { searchParams: Promise<{ course?: string; lesson?: string; welcome?: string }> };

export default async function LearnPage({ searchParams }: Props) {
  const sp = await searchParams;
  const user = await requireUser();
  const cid = Number(sp.course) || 0;
  const course = cid ? await courseBy('id', cid, false) : null;
  if (!course) redirect('/my-courses');

  const enrollment = await one<{ id: number }>`SELECT id FROM enrollments WHERE user_id = ${user.id} AND course_id = ${cid} AND status = 'active'`;
  // Enrolled learners, admins, the course's own teacher, or anyone signed in for a published free course.
  if (!(await canWatchCourse(user, course))) redirect(courseUrl(course));

  const [modules, doneRows, liveNext, recordings] = await Promise.all([
    curriculum(cid),
    enrollment ? q<{ lesson_id: number }>`SELECT lesson_id FROM lesson_progress WHERE enrollment_id = ${enrollment.id} AND completed` : [],
    one`SELECT * FROM live_sessions WHERE course_id = ${cid} AND status IN ('scheduled', 'live')
        AND scheduled_at + make_interval(mins => duration_minutes) >= now() ORDER BY scheduled_at LIMIT 1`,
    q`SELECT id, title, recording_url, scheduled_at FROM live_sessions
      WHERE course_id = ${cid} AND recording_url IS NOT NULL AND recording_url <> '' ORDER BY scheduled_at DESC`,
  ]);
  const done = new Set(doneRows.map(r => r.lesson_id));
  const flat = modules.flatMap(m => m.lessons);
  let idx = flat.findIndex(l => l.id === Number(sp.lesson));
  if (idx < 0) idx = flat.findIndex(l => !done.has(l.id));
  if (idx < 0 && flat.length) idx = 0;
  const lesson = idx >= 0 ? flat[idx] : null;
  const prev = idx > 0 ? flat[idx - 1] : null;
  const next = idx >= 0 && idx + 1 < flat.length ? flat[idx + 1] : null;
  const doneCount = flat.filter(l => done.has(l.id)).length;
  const pct = flat.length ? Math.round((doneCount / flat.length) * 100) : 0;
  // The video itself is fetched by the protected player (/api/player) — never written into this page.
  const playable = lesson?.kind === 'video' && !!(youtubeId(String(lesson.video_url ?? '')) || embedUrl(lesson.video_url));
  // Watermark: who is watching (discourages screen recording and sharing).
  const mark = [user.name, user.phone || user.email].filter(Boolean).join(' · ');
  const markCode = `LH-${user.id}`;
  const L = lang(course.cat_slug);
  const lessonHref = (id: number) => `/learn?course=${cid}&lesson=${id}`;
  const minutes = flat.reduce((n, l) => n + (Number(l.duration) || 0), 0);
  const classNo = (id: number) => flat.findIndex(l => l.id === id) + 1;
  // "Continue" jumps to the first class not done yet (other than the one on screen).
  const resume = flat.find(l => !done.has(l.id) && l.id !== lesson?.id) ?? null;
  const [kIcon, kBn, kEn] = KIND[lesson?.kind ?? 'video'] ?? KIND.video;
  const startsLater = !!course.batch_date && new Date(`${course.batch_date}T00:00:00+06:00`).getTime() > Date.now();

  return (
    <>
      <section className="lh-cr-head" style={langStyle(course.cat_slug)}>
        <span className="lh-cr-glyph" aria-hidden="true">{L.glyph}</span>
        <div className="container">
          <a href="/my-courses" className="lh-c-back"><Icon name="arrow_back" /><T bn="আমার কোর্স" en="My courses" /></a>
          <div className="lh-cr-head-in">
            <div className="lh-cr-head-text">
              <p className="lh-cr-eyebrow"><Icon name="school" /><T bn="ক্লাসরুম" en="Classroom" /> · <T bn={course.cat_name} en={course.cat_name_en} /></p>
              <h1 className="lh-cr-title"><T bn={course.title_bn} en={course.title_en || course.title_bn} /></h1>
              {course.teacher_name && <p className="lh-cr-teacher"><Icon name="badge" />{course.teacher_name}</p>}
              <ul className="lh-cr-stats">
                <li><Icon name="play_lesson" /><N v={flat.length} /> <T bn="ক্লাস" en="classes" /></li>
                {minutes > 0 && <li><Icon name="schedule" /><T bn={`${bnNum(Math.round(minutes / 6) / 10)} ঘণ্টা`} en={`${Math.round(minutes / 6) / 10} h`} /></li>}
                <li><Icon name="task_alt" /><N v={doneCount} />/<N v={flat.length} /> <T bn="সম্পন্ন" en="done" /></li>
              </ul>
              {resume && (
                <a className="btn btn-accent lh-cr-resume" href={lessonHref(resume.id)}>
                  <Icon name="play_arrow" />
                  <T bn={`চালিয়ে যান · ক্লাস ${bnNum(pad(classNo(resume.id)))}`} en={`Continue · Class ${pad(classNo(resume.id))}`} />
                </a>
              )}
            </div>
            <div className="lh-cr-ring" style={{ '--p': pct } as React.CSSProperties} role="progressbar"
                 aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} aria-label="Course progress">
              <b data-pct><N v={pct} />%</b><small><T bn="সম্পন্ন" en="complete" /></small>
            </div>
          </div>
        </div>
      </section>

      {sp.welcome && enrollment && (
        <div className="container">
          <div className="lh-welcome" role="status" data-reveal>
            <div className="lh-confetti" aria-hidden="true">{Array.from({ length: 18 }, (_, i) => <i key={i} style={{ '--i': i } as React.CSSProperties}></i>)}</div>
            <Icon name="celebration" />
            <div>
              <h2><T bn="স্বাগতম! আপনি ভর্তি হয়েছেন" en="Welcome! You are enrolled" /></h2>
              <p>{course.type === 'live'
                ? <T bn='পরের লাইভ ক্লাসের সময় নিচে দেখুন। ক্লাসের ১০ মিনিট আগে "ক্লাসে যোগ দিন" বাটন চালু হবে।' en="See your next live class below. The Join button opens 10 minutes before class." />
                : <T bn="প্রথম ক্লাস থেকেই শুরু করুন — নিজের গতিতে।" en="Start with the first lesson — at your own pace." />}</p>
              <p className="lh-welcome-pass"><span className="lh-stamp-sm is-done"><T bn="বোর্ডেড" en="Boarded" /></span><T bn={`বোর্ডিং পাস ইস্যু হয়েছে · ফ্লাইট LH ${String(course.id).padStart(3, "0")}`} en={`Boarding pass issued · Flight LH ${String(course.id).padStart(3, "0")}`} /></p>
            </div>
          </div>
        </div>
      )}

      <div className="container lh-cr">
        <div className="lh-cr-main">
          {liveNext ? <LiveCard s={liveNext} /> : course.type === 'live' && (
            <section className="lh-live" data-reveal>
              <div className="lh-live-top"><span className="lh-chip"><T bn="লাইভ ক্লাস" en="Live classes" /></span></div>
              <h2>{startsLater ? <>
                <T bn="ব্যাচ শুরু" en="Batch starts" /> <DateBn date={course.batch_date} />
              </> : <T bn="পরের ক্লাসের সময় শীঘ্রই জানানো হবে" en="Next class time coming soon" />}</h2>
              {startsLater && <Countdown to={`${course.batch_date}T00:00:00+06:00`} />}
              {course.fb_group_link && <a href={course.fb_group_link} target="_blank" rel="noopener" className="lh-link"><Icon name="groups" /><T bn="ব্যাচের গ্রুপে যোগ দিন" en="Join the batch group" /></a>}
            </section>
          )}

          {lesson ? (
            <section className="lh-player-card" data-reveal>
              <div className="lh-player" style={langStyle(course.cat_slug)}>
                {playable ? (
                  <ProtectedPlayer lessonId={lesson.id} poster={lessonThumb(lesson)} mark={mark} code={markCode} glyph={L.glyph} title={lesson.title} />
                ) : lesson.kind === 'pdf' && lesson.pdf_url ? (
                  <div className="lh-player-empty"><Icon name="description" />
                    <a className="btn btn-primary" href={lesson.pdf_url} target="_blank" rel="noopener"><T bn="PDF খুলুন" en="Open PDF" /></a></div>
                ) : lesson.kind === 'text' && lesson.content ? (
                  <div className="lh-player-text lh-prose" style={{ whiteSpace: 'pre-line' }}>{lesson.content.replace(/<[^>]*>/g, '')}</div>
                ) : (
                  <div className="lh-player-empty"><span className="lh-ticket-glyph" aria-hidden="true">{L.glyph}</span>
                    <p><T bn="এই ক্লাসের ভিডিও শীঘ্রই যোগ হবে।" en="The video for this lesson is coming soon." /></p></div>
                )}
                {!playable && lesson.kind !== 'text' && <span className="lh-watermark" aria-hidden="true" data-watermark>{mark}</span>}
              </div>

              <div className="lh-cr-now">
                <div className="lh-cr-now-text">
                  <p className="lh-cr-now-meta">
                    <span className="lh-cr-chip"><T bn={`ক্লাস ${bnNum(pad(idx + 1))}/${bnNum(pad(flat.length))}`} en={`Class ${pad(idx + 1)}/${pad(flat.length)}`} /></span>
                    <span><Icon name={kIcon} /><T bn={kBn} en={kEn} /></span>
                    {Number(lesson.duration) > 0 && <span><Icon name="schedule" /><N v={lesson.duration} /> <T bn="মিনিট" en="min" /></span>}
                  </p>
                  <h2>{lesson.title}</h2>
                </div>
                {enrollment && (
                  <button type="button" className={`btn ${done.has(lesson.id) ? 'btn-outline is-done' : 'btn-primary'} lh-done-btn`} data-done={lesson.id} data-next={next ? lessonHref(next.id) : ''}>
                    <Icon name={done.has(lesson.id) ? 'check_circle' : 'task_alt'} />
                    <span data-done-label>{done.has(lesson.id) ? <T bn="সম্পন্ন" en="Done" /> : <T bn="সম্পন্ন করেছি" en="Mark as done" />}</span>
                  </button>
                )}
              </div>
              {lesson.kind !== 'pdf' && lesson.pdf_url && (
                <a className="lh-cr-notes" href={lesson.pdf_url} target="_blank" rel="noopener">
                  <Icon name="description" /><T bn="এই ক্লাসের নোট (PDF)" en="Notes for this class (PDF)" /><Icon name="open_in_new" />
                </a>
              )}

              {(prev || next) && (
                <nav className="lh-cr-nav" aria-label="Lesson navigation">
                  {prev ? <StepCard href={lessonHref(prev.id)} l={prev} no={idx} dir="prev" glyph={L.glyph} /> : <span></span>}
                  {next ? <StepCard href={lessonHref(next.id)} l={next} no={idx + 2} dir="next" glyph={L.glyph} /> : <span></span>}
                </nav>
              )}
            </section>
          ) : course.type !== 'live' && (
            <section className="lh-c-card lh-empty" data-reveal>
              <Icon name="video_library" />
              <h2><T bn="ক্লাসগুলো শীঘ্রই যোগ হবে" en="Lessons are coming soon" /></h2>
              <p><T bn="শিক্ষক ক্লাস যোগ করলেই এখানে দেখতে পাবেন।" en="They appear here as soon as the teacher adds them." /></p>
            </section>
          )}

          {recordings.length > 0 && (
            <section className="lh-c-card" data-reveal>
              <h2><T bn="লাইভ ক্লাসের রেকর্ডিং" en="Live class recordings" /></h2>
              <ul className="lh-rec-list">
                {recordings.map(r => (
                  <li key={r.id}><button type="button" data-pp-rec={r.id}>
                    <Icon name="play_circle" /><span><b>{r.title}</b><small><DateBn date={r.scheduled_at} /></small></span>
                  </button></li>
                ))}
              </ul>
              <div className="lh-pp-modal" hidden data-pp-modal role="dialog" aria-modal="true" aria-label="Recording">
                <div className="lh-pp-modal-box">
                  <button type="button" className="lh-pp-close" data-pp-close aria-label="Close"><Icon name="close" /></button>
                  <ProtectedPlayer mark={mark} code={markCode} glyph={L.glyph} title="" />
                </div>
              </div>
            </section>
          )}
        </div>

        <aside className="lh-cr-side">
          {flat.length > 0 && (
            <section className="lh-pl" data-reveal="right" aria-label="All classes">
              <header className="lh-pl-head">
                <h2><T bn="সব ক্লাস" en="All classes" /></h2>
                <span className="lh-pl-count"><N v={doneCount} />/<N v={flat.length} /></span>
                <div className="lh-pl-bar" aria-hidden="true"><span style={{ width: `${pct}%` }}></span></div>
              </header>
              <div className="lh-pl-scroll" data-pl-scroll>
                {modules.map((m, mi) => m.lessons.length > 0 && (
                  <div key={m.id} className="lh-pl-mod">
                    <p className="lh-pl-mod-title">
                      <span className="lh-curr-n"><N v={mi + 1} /></span>
                      <span className="lh-pl-mod-name">{m.title}</span>
                      <small><N v={m.lessons.filter(l => done.has(l.id)).length} />/<N v={m.lessons.length} /></small>
                    </p>
                    <ol className="lh-pl-list">
                      {m.lessons.map(ls => {
                        const cur = lesson?.id === ls.id;
                        const isDone = done.has(ls.id);
                        const thumb = lessonThumb(ls);
                        const [icon, kbn, ken] = KIND[ls.kind] ?? KIND.video;
                        return (
                          <li key={ls.id} className={`lh-pl-item${cur ? ' is-current' : ''}${isDone ? ' is-done' : ''}`}>
                            <a href={lessonHref(ls.id)} aria-current={cur ? 'true' : undefined}>
                              <span className="lh-pl-thumb">
                                {thumb ? <img src={thumb} alt="" loading="lazy" decoding="async" /> : <span className="lh-pl-glyph" aria-hidden="true">{L.glyph}</span>}
                                {Number(ls.duration) > 0 && <span className="lh-pl-dur"><N v={ls.duration} />m</span>}
                                {cur && <span className="lh-pl-eq" aria-hidden="true"><i></i><i></i><i></i></span>}
                              </span>
                              <span className="lh-pl-body">
                                <small>{cur ? <T bn="এখন চলছে" en="Now playing" />
                                  : <><T bn={`ক্লাস ${bnNum(pad(classNo(ls.id)))}`} en={`Class ${pad(classNo(ls.id))}`} /> · <Icon name={icon} /><T bn={kbn} en={ken} /></>}</small>
                                <b>{ls.title}</b>
                              </span>
                              <span className="material-icons-round lh-pl-state" data-pl-icon aria-hidden="true">{isDone ? 'check_circle' : cur ? 'play_circle' : 'radio_button_unchecked'}</span>
                            </a>
                          </li>
                        );
                      })}
                    </ol>
                  </div>
                ))}
              </div>
            </section>
          )}
        </aside>
      </div>
    </>
  );
}

/** Previous / next class as a small card with its thumbnail. */
function StepCard({ href, l, no, dir, glyph }: {
  href: string; l: Row; no: number; dir: 'prev' | 'next'; glyph: string;
}) {
  const thumb = lessonThumb(l);
  return (
    <a href={href} className={`lh-cr-step is-${dir}`}>
      <span className="lh-cr-step-thumb">{thumb ? <img src={thumb} alt="" loading="lazy" /> : <span aria-hidden="true">{glyph}</span>}</span>
      <span className="lh-cr-step-body">
        <small>{dir === 'next'
          ? <><T bn="পরের ক্লাস" en="Next class" /><Icon name="arrow_forward" /></>
          : <><Icon name="arrow_back" /><T bn="আগের ক্লাস" en="Previous" /></>}</small>
        <b><T bn={`${bnNum(pad(no))}. `} en={`${pad(no)}. `} />{l.title}</b>
      </span>
    </a>
  );
}

/**
 * The class video behind a protective layer (see lh.js "Protected player"): nothing playable is in
 * the HTML; pressing play asks /api/player, which checks the login and course access first.
 * The layer blocks YouTube's title, logo, share and "Watch on YouTube" links and the right-click
 * menu; the learner's name and phone float over the picture (also in full screen).
 */
function ProtectedPlayer({ lessonId, poster, mark, code, glyph, title }: {
  lessonId?: number; poster?: string; mark: string; code: string; glyph: string; title: string;
}) {
  return (
    <div className="lh-pp" data-pp data-lesson={lessonId ?? ''} tabIndex={0} aria-label={title ? `Video: ${title}` : 'Video'}>
      <div className="lh-pp-frame" data-pp-frame></div>
      <div className="lh-pp-band" aria-hidden="true"></div>
      <div className="lh-pp-tiles" aria-hidden="true">
        {Array.from({ length: 6 }, (_, i) => <span key={i}>{mark} · {code}</span>)}
      </div>
      <span className="lh-watermark lh-pp-mark" aria-hidden="true" data-watermark>{mark}<small>{code}</small></span>
      <button type="button" className="lh-pp-cover" data-pp-start aria-label="Play">
        {poster ? <img src={poster} alt="" draggable={false} /> : <span className="lh-pp-glyph" aria-hidden="true">{glyph}</span>}
        <span className="lh-pp-big"><span className="material-icons-round" aria-hidden="true">play_arrow</span></span>
      </button>
    </div>
  );
}
