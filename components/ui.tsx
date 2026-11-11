// Small shared pieces used across the public site and the student area.
import type { Row } from '@/lib/db';
import { T, N, bnNum } from '@/lib/i18n';
import { formatPrice, priceOf, isFreeCourse } from '@/lib/format';
import { lang, langStyle } from '@/lib/langs';
import { courseUrl } from '@/lib/courses';
import { readFlash } from '@/lib/http';

const Icon = ({ name, className = '' }: { name: string; className?: string }) => (
  <span className={`material-icons-round ${className}`} aria-hidden="true">{name}</span>
);
export { Icon };

/** Ticket-style course card (homepage, listings, teacher page). */
export function CourseCard({ c }: { c: Row }) {
  const L = lang(c.cat_slug);
  const [now, was] = priceOf(c);
  const free = isFreeCourse(c);
  const live = c.type === 'live';
  return (
    <a className="lh-ticket" href={courseUrl(c)} style={langStyle(c.cat_slug)}
       data-lang={c.cat_slug ?? ''} data-type={live ? 'live' : 'recorded'} data-free={free ? 1 : 0}>
      <div className="lh-ticket-art">
        {c.thumbnail && /^https?:\/\//.test(c.thumbnail)
          ? <img src={c.thumbnail} alt="" loading="lazy" />
          : <><span className="lh-ticket-glyph" aria-hidden="true">{L.glyph}</span><span className="lh-ticket-hello" aria-hidden="true">{L.hello}</span></>}
        {live
          ? <span className="lh-chip lh-chip-live"><i></i><T bn="লাইভ" en="Live" /></span>
          : <span className="lh-chip"><T bn="রেকর্ডেড" en="Recorded" /></span>}
      </div>
      <div className="lh-ticket-body">
        <p className="lh-ticket-meta"><T bn={c.cat_name} en={c.cat_name_en} /> · <N v={c.lesson_count ?? 0} /> <T bn="ক্লাস" en="lessons" /></p>
        <h3><T bn={c.title_bn} en={c.title_en || c.title_bn} /></h3>
        <p className="lh-ticket-teacher">{c.teacher_name ?? ''}</p>
      </div>
      <div className="lh-ticket-stub">
        <Price now={now} was={was} free={free} />
        <Icon name="arrow_forward" className="lh-ticket-go" />
      </div>
    </a>
  );
}

export function Price({ now, was, free }: { now: number; was: number | null; free: boolean }) {
  if (free) return <span className="lh-price lh-price-free"><T bn="ফ্রি" en="Free" /></span>;
  return (
    <>
      <span className="lh-price">৳<N v={formatPrice(now)} /></span>
      {was ? <s>৳<N v={formatPrice(was)} /></s> : null}
    </>
  );
}

/** Live countdown (filled in by lh.js). */
export function Countdown({ to }: { to: Date | string }) {
  return (
    <div className="lh-pass-countdown lh-c-grid" data-countdown={new Date(to).toISOString()}>
      <div><b data-d>--</b><small><T bn="দিন" en="days" /></small></div>
      <div><b data-h>--</b><small><T bn="ঘণ্টা" en="hrs" /></small></div>
      <div><b data-m>--</b><small><T bn="মিনিট" en="min" /></small></div>
      <div><b data-s>--</b><small><T bn="সেকেন্ড" en="sec" /></small></div>
    </div>
  );
}

/** One-time message after a form submit (set with flash() in a route handler). */
export async function Flash() {
  const f = await readFlash();
  if (!f) return null;
  const icon = { success: 'check_circle', error: 'error', warning: 'warning', info: 'info' }[f.type] ?? 'info';
  return (
    <div className="flash-container" id="flashContainer">
      <div className={`alert alert-${f.type}`} role="alert">
        <Icon name={icon} />
        <span><T bn={f.bn} en={f.en} /></span>
        <button className="alert-close" type="button" aria-label="Close" data-close-flash>✕</button>
      </div>
      <script dangerouslySetInnerHTML={{ __html:
        `document.cookie='lh_flash=; Max-Age=0; path=/';document.querySelectorAll('[data-close-flash]').forEach(function(b){b.onclick=function(){b.parentElement.remove()}});` }} />
    </div>
  );
}

/** Page heading used by list-style pages (courses, my courses, orders …). */
export function ListHead({ back, title, glyph, style }: { back?: [string, string, string]; title: React.ReactNode; glyph?: string; style?: React.CSSProperties }) {
  return (
    <section className="lh-list-head" style={style}>
      <div className="lh-glow" aria-hidden="true"></div>
      {glyph ? <span className="lh-list-glyph" aria-hidden="true">{glyph}</span> : null}
      <div className="container" data-reveal>
        {back ? <a href={back[0]} className="lh-c-back"><Icon name="arrow_back" /><T bn={back[1]} en={back[2]} /></a> : null}
        <h1 className="lh-list-title">{title}</h1>
      </div>
    </section>
  );
}

export function Empty({ icon, bn, en, children }: { icon: string; bn: string; en: string; children?: React.ReactNode }) {
  return (
    <div className="lh-c-card lh-empty" data-reveal>
      <Icon name={icon} />
      <h2><T bn={bn} en={en} /></h2>
      {children}
    </div>
  );
}

export const bnPrice = (n: number) => bnNum(formatPrice(n));
