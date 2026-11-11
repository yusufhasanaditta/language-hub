import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { q, one } from '@/lib/db';
import { requireUser, isAdmin, isTeacher } from '@/lib/auth';
import { courseBy, curriculum, courseUrl } from '@/lib/courses';
import { T, N, DateBn } from '@/lib/i18n';
import { lang, langStyle } from '@/lib/langs';
import { embedUrl } from '@/lib/format';
import { Countdown, Icon } from '@/components/ui';
import { LiveCard } from '@/components/student';

export const metadata: Metadata = { title: 'ক্লাসরুম' };

type Props = { searchParams: Promise<{ course?: string; lesson?: string; welcome?: string }> };

export default async function LearnPage({ searchParams }: Props) {
  const sp = await searchParams;
  const user = await requireUser();
  const cid = Number(sp.course) || 0;
  const course = cid ? await courseBy('id', cid, false) : null;
  if (!course) redirect('/my-courses');

  const enrollment = await one<{ id: number }>`SELECT id FROM enrollments WHERE user_id = ${user.id} AND course_id = ${cid} AND status = 'active'`;
  // Staff preview: admins, and the course's own teacher (not every teacher).
  const ownsCourse = isTeacher(user) && !!(await one`SELECT 1 FROM teacher_profiles WHERE id = ${course.teacher_id} AND user_id = ${user.id}`);
  if (!enrollment && !isAdmin(user) && !ownsCourse) redirect(courseUrl(course));

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
  const embed = lesson?.kind === 'video' ? embedUrl(lesson.video_url) : '';
  const mark = user.phone || user.email || user.name; // watermark discourages screen recording
  const L = lang(course.cat_slug);
  const lessonHref = (id: number) => `/learn?course=${cid}&lesson=${id}`;
  const startsLater = !!course.batch_date && new Date(`${course.batch_date}T00:00:00+06:00`).getTime() > Date.now();

  return (
    <>
      <section className="lh-learn-head" style={langStyle(course.cat_slug)}>
        <div className="container">
          <a href="/my-courses" className="lh-c-back"><Icon name="arrow_back" /><T bn="আমার কোর্স" en="My courses" /></a>
          <h1 className="lh-learn-title"><T bn={course.title_bn} en={course.title_en || course.title_bn} /></h1>
          <div className="lh-progress" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100}>
            <div className="lh-progress-bar"><span style={{ width: `${pct}%` }}></span></div>
            <b data-pct><N v={pct} />%</b>
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
            </div>
          </div>
        </div>
      )}

      <div className="container lh-learn">
        <div className="lh-learn-main">
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
                {lesson.kind === 'video' && embed ? (
                  <iframe src={embed} title={lesson.title} allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowFullScreen referrerPolicy="strict-origin-when-cross-origin"></iframe>
                ) : lesson.kind === 'pdf' && lesson.pdf_url ? (
                  <div className="lh-player-empty"><Icon name="description" />
                    <a className="btn btn-primary" href={lesson.pdf_url} target="_blank" rel="noopener"><T bn="PDF খুলুন" en="Open PDF" /></a></div>
                ) : lesson.kind === 'text' && lesson.content ? (
                  <div className="lh-player-text lh-prose" style={{ whiteSpace: 'pre-line' }}>{lesson.content.replace(/<[^>]*>/g, '')}</div>
                ) : (
                  <div className="lh-player-empty"><span className="lh-ticket-glyph" aria-hidden="true">{L.glyph}</span>
                    <p><T bn="এই ক্লাসের ভিডিও শীঘ্রই যোগ হবে।" en="The video for this lesson is coming soon." /></p></div>
                )}
                {lesson.kind !== 'text' && <span className="lh-watermark" aria-hidden="true" data-watermark>{mark}</span>}
              </div>
              <div className="lh-player-bar">
                <div>
                  <p className="lh-ticket-meta"><T bn="ক্লাস" en="Lesson" /> <N v={idx + 1} />/<N v={flat.length} /></p>
                  <h2>{lesson.title}</h2>
                </div>
                {enrollment && (
                  <button type="button" className={`btn ${done.has(lesson.id) ? 'btn-outline is-done' : 'btn-primary'} lh-done-btn`} data-done={lesson.id} data-next={next ? lessonHref(next.id) : ''}>
                    <Icon name={done.has(lesson.id) ? 'check_circle' : 'task_alt'} />
                    <span data-done-label>{done.has(lesson.id) ? <T bn="সম্পন্ন" en="Done" /> : <T bn="সম্পন্ন করেছি" en="Mark as done" />}</span>
                  </button>
                )}
              </div>
              <div className="lh-player-nav">
                {prev ? <a className="btn btn-outline" href={lessonHref(prev.id)}><Icon name="arrow_back" /><T bn="আগের" en="Previous" /></a> : <span></span>}
                {next && <a className="btn btn-outline" href={lessonHref(next.id)}><T bn="পরের" en="Next" /><Icon name="arrow_forward" /></a>}
              </div>
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
                  <li key={r.id}><a href={r.recording_url} data-video={r.recording_url}>
                    <Icon name="play_circle" /><span><b>{r.title}</b><small><DateBn date={r.scheduled_at} /></small></span>
                  </a></li>
                ))}
              </ul>
            </section>
          )}
        </div>

        <aside className="lh-learn-side">
          {modules.length > 0 && (
            <section className="lh-c-card lh-learn-list" data-reveal="right">
              <h2><T bn="সব ক্লাস" en="All lessons" /> <small><N v={doneCount} />/<N v={flat.length} /></small></h2>
              {modules.map((m, mi) => m.lessons.length > 0 && (
                <div key={m.id}>
                  <p className="lh-learn-mod"><span className="lh-curr-n"><N v={mi + 1} /></span>{m.title}</p>
                  <ol className="lh-curr-list">
                    {m.lessons.map(ls => {
                      const cur = lesson?.id === ls.id;
                      return (
                        <li key={ls.id} className={`${cur ? 'is-current' : ''} ${done.has(ls.id) ? 'is-done' : ''}`}>
                          <a href={lessonHref(ls.id)} aria-current={cur ? 'true' : undefined}>
                            <Icon name={done.has(ls.id) ? 'check_circle' : cur ? 'play_circle' : 'radio_button_unchecked'} />
                            <span className="lh-curr-t">{ls.title}</span>
                            {ls.duration > 0 && <small><N v={ls.duration} />m</small>}
                          </a>
                        </li>
                      );
                    })}
                  </ol>
                </div>
              ))}
            </section>
          )}
        </aside>
      </div>
    </>
  );
}
