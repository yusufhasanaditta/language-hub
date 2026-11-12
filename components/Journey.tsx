// Course curriculum as a journey: each module is a "leg", each class a ticket stop
// on a dashed route, ending at the destination (the exam / course complete).
import type { Module } from '@/lib/courses';
import { T, N, bnNum } from '@/lib/i18n';
import { lang } from '@/lib/langs';
import { embedUrl } from '@/lib/format';
import { Icon } from './ui';

const KIND: Record<string, [string, string, string]> = {
  video: ['smart_display', 'ভিডিও', 'Video'],
  pdf: ['description', 'নোট', 'Notes'],
  text: ['article', 'পাঠ', 'Reading'],
  live: ['videocam', 'লাইভ', 'Live'],
  quiz: ['quiz', 'কুইজ', 'Quiz'],
};
const pad = (n: number) => String(n).padStart(2, '0');

export function Journey({ courseId, modules, catSlug, enrolled, done }: {
  courseId: number; modules: Module[]; catSlug: string; enrolled: boolean; done: Set<number>;
}) {
  const L = lang(catSlug);
  const all = modules.flatMap(m => m.lessons);
  const minutes = all.reduce((n, l) => n + (Number(l.duration) || 0), 0);
  const free = all.filter(l => l.is_free).length;
  let no = 0;

  return (
    <section className="lh-c-card lh-journey" data-reveal>
      <div className="lh-journey-top">
        <h2><T bn="কোর্সের যাত্রাপথ" en="Your journey" /></h2>
        <div className="lh-journey-route" aria-hidden="true">
          <span>বাংলা</span><i></i><Icon name="flight" /><i></i><span>{L.native || L.exam || '—'}</span>
        </div>
      </div>
      <div className="lh-jtrack" aria-hidden="true"><i></i><span className="material-icons-round">flight</span></div>
      <ul className="lh-journey-sum">
        <li><Icon name="route" /><N v={modules.length} /> <T bn="অধ্যায়" en="legs" /></li>
        <li><Icon name="confirmation_number" /><N v={all.length} /> <T bn="ক্লাস" en="classes" /></li>
        {minutes > 0 && <li><Icon name="schedule" /><T bn={`${bnNum(Math.round(minutes / 6) / 10)} ঘণ্টা`} en={`${Math.round(minutes / 6) / 10} h`} /></li>}
        {free > 0 && !enrolled && <li className="is-free"><Icon name="play_circle" /><N v={free} /> <T bn="ফ্রি ক্লাস" en="free previews" /></li>}
      </ul>

      {modules.map((m, mi) => {
        const mins = m.lessons.reduce((n, l) => n + (Number(l.duration) || 0), 0);
        return (
          <div key={m.id} className="lh-leg">
            <header className="lh-leg-head">
              <span className="lh-leg-n"><T bn={`অধ্যায় ${bnNum(mi + 1)}`} en={`Leg ${pad(mi + 1)}`} /></span>
              <h3>{m.title}</h3>
              <span className="lh-leg-meta"><N v={m.lessons.length} /> <T bn="ক্লাস" en="classes" />{mins > 0 && <> · <N v={mins} /> <T bn="মিনিট" en="min" /></>}</span>
            </header>
            <ol className="lh-stops" data-reveal-group>
              {m.lessons.map(l => {
                no += 1;
                const [icon, kbn, ken] = KIND[l.kind] ?? KIND.video;
                const isDone = done.has(l.id);
                const preview = !enrolled && l.is_free ? embedUrl(l.video_url) : '';
                const state = isDone ? 'is-done' : enrolled ? 'is-open' : l.is_free ? 'is-free' : 'is-locked';
                const body = (
                  <>
                    <span className="lh-stop-no"><small><T bn="ক্লাস" en="Class" /></small><b><T bn={bnNum(pad(no))} en={pad(no)} /></b></span>
                    <span className="lh-stop-body">
                      <span className="lh-stop-kind"><Icon name={icon} /><T bn={kbn} en={ken} />{Number(l.duration) > 0 && <> · <N v={l.duration} /> <T bn="মি." en="min" /></>}</span>
                      <b className="lh-stop-title">{l.title}</b>
                    </span>
                    <span className="lh-stop-state">
                      {isDone ? <span className="lh-stamp-sm is-done"><T bn="সম্পন্ন" en="Done" /></span>
                        : enrolled ? <Icon name="play_arrow" className="lh-stop-go" />
                        : l.is_free ? <span className="lh-stamp-sm"><T bn="ফ্রি" en="Free" /></span>
                        : <span className="material-icons-round lh-lock" aria-label="Locked">lock</span>}
                    </span>
                  </>
                );
                return (
                  <li key={l.id} className={`lh-stop ${state}`}>
                    {enrolled ? <a href={`/learn?course=${courseId}&lesson=${l.id}`}>{body}</a>
                      : preview ? <a href={l.video_url} data-video={preview} aria-label={`${l.title} — free preview`}>{body}</a>
                      : <div>{body}</div>}
                  </li>
                );
              })}
            </ol>
          </div>
        );
      })}

      <div className="lh-dest">
        <Icon name="flag" />
        <div>
          <small><T bn="গন্তব্য" en="Destination" /></small>
          <b>{L.exam ? <T bn={`${L.exam} পরীক্ষার জন্য প্রস্তুত`} en={`Ready for ${L.exam}`} /> : <T bn="কোর্স সম্পন্ন" en="Course complete" />}</b>
        </div>
      </div>
    </section>
  );
}
