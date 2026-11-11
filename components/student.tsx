// Pieces shared by the student dashboard, My courses and the classroom.
import type { Row } from '@/lib/db';
import { T, N, DateBn } from '@/lib/i18n';
import { lang, langStyle } from '@/lib/langs';
import { fmtDateTime } from '@/lib/format';
import { Countdown, Icon } from './ui';

export function MyCourseRow({ c, meta }: { c: Row; meta: React.ReactNode }) {
  const p = Number(c.progress_pct) || 0;
  return (
    <a className="lh-mycourse" href={`/learn?course=${c.id}`} style={langStyle(c.cat_slug)}>
      <span className="lh-co-glyph" aria-hidden="true">{lang(c.cat_slug).glyph}</span>
      <span className="lh-mycourse-body">
        <span className="lh-ticket-meta">{meta}</span>
        <b><T bn={c.title_bn} en={c.title_en || c.title_bn} /></b>
        <span className="lh-progress"><span className="lh-progress-bar"><span style={{ width: `${p}%` }}></span></span><small><N v={p} />%</small></span>
      </span>
      <Icon name={p >= 100 ? 'replay' : p > 0 ? 'play_arrow' : 'arrow_forward'} className="lh-ticket-go" />
    </a>
  );
}

/** "Next live class" card with countdown and join button. */
export function LiveCard({ s, courseTitle }: { s: Row; courseTitle?: React.ReactNode }) {
  const start = new Date(s.scheduled_at);
  return (
    <section className="lh-live" data-reveal>
      <div className="lh-live-top">
        <span className="lh-chip lh-chip-live"><i></i>{s.status === 'live' ? <T bn="এখন লাইভ" en="Live now" /> : <T bn="পরের লাইভ ক্লাস" en="Next live class" />}</span>
        <span className="lh-live-when"><DateBn date={start} /> · {fmtDateTime(start).split(', ')[1]}</span>
      </div>
      <h2>{s.title}</h2>
      {courseTitle && <p className="lh-live-course">{courseTitle}</p>}
      <Countdown to={start} />
      {s.meeting_url ? (
        <>
          <a href={s.meeting_url} target="_blank" rel="noopener" className="btn btn-primary lh-btn-block" data-join data-start={Math.floor(start.getTime() / 1000)}>
            <Icon name="videocam" /><span data-join-label><T bn="ক্লাসে যোগ দিন" en="Join class" /></span>
          </a>
          <p className="lh-live-note"><T bn="বাটনটি ক্লাস শুরুর ১০ মিনিট আগে চালু হবে।" en="The button opens 10 minutes before class." /></p>
        </>
      ) : <p className="lh-live-note"><T bn="ক্লাসের লিংক শিক্ষক শীঘ্রই দেবেন।" en="Your teacher will add the class link soon." /></p>}
    </section>
  );
}
