// Published testimonials (Admin → Testimonials): words from students, guardians and teachers.
// The filter tabs are radio buttons, so they work without JavaScript.
import { q } from '@/lib/db';
import type { Row } from '@/lib/db';
import { T } from '@/lib/i18n';
import { avatarUrl } from '@/lib/format';
import { Icon } from './ui';

const KIND: Record<string, [string, string, string]> = {
  student: ['শিক্ষার্থী', 'Student', 'school'],
  guardian: ['অভিভাবক', 'Guardian', 'family_restroom'],
  teacher: ['শিক্ষক', 'Teacher', 'co_present'],
};

export async function loadTestimonials(courseId?: number) {
  return courseId
    ? q`SELECT * FROM testimonials WHERE is_approved AND course_id = ${courseId} ORDER BY sort_order DESC, created_at DESC LIMIT 12`
    : q`SELECT * FROM testimonials WHERE is_approved ORDER BY sort_order DESC, created_at DESC LIMIT 24`;
}

function Card({ t }: { t: Row }) {
  const [bn, en, icon] = KIND[t.kind] ?? KIND.student;
  const stars = Math.max(1, Math.min(5, Number(t.rating) || 5));
  return (
    <figure className={`lh-tm is-${t.kind}`} data-kind={t.kind}>
      <span className="lh-tm-mark" aria-hidden="true">“</span>
      <div className="lh-tm-stars" aria-label={`${stars}/5`}>{'★'.repeat(stars)}<span>{'★'.repeat(5 - stars)}</span></div>
      <blockquote>{t.text}</blockquote>
      <figcaption>
        <img src={avatarUrl(t.photo, t.name)} alt="" width={48} height={48} loading="lazy" />
        <span className="lh-tm-who">
          <b>{t.name}</b>
          {t.batch && <small>{t.batch}</small>}
        </span>
        <span className="lh-tm-kind"><Icon name={icon} /><T bn={bn} en={en} /></span>
      </figcaption>
    </figure>
  );
}

/** Home page section with filter tabs; on a course page pass `compact` for a simple list. */
export function Testimonials({ items, compact = false }: { items: Row[]; compact?: boolean }) {
  if (!items.length) return null;
  if (compact) {
    return (
      <section className="lh-c-card" data-reveal>
        <h2><T bn="যারা এই কোর্স করেছেন" en="From people who took this course" /></h2>
        <div className="lh-tm-grid is-compact">{items.map(t => <Card key={t.id} t={t} />)}</div>
      </section>
    );
  }
  const kinds = Object.keys(KIND).filter(k => items.some(t => t.kind === k));
  return (
    <section className="lh-section lh-tms" id="testimonials">
      <div className="container">
        <div className="lh-section-head lh-center" data-reveal>
          <p className="lh-eyebrow"><T bn="তাদের কথা" en="In their words" /></p>
          <h2><T bn="শিক্ষার্থী, অভিভাবক ও শিক্ষকদের অভিজ্ঞতা" en="What students, guardians and teachers say" /></h2>
        </div>
        <div className="lh-tm-filter" data-tm-filter>
          {kinds.length > 1 && (
            <div className="lh-tm-tabs" role="radiogroup" aria-label="Filter">
              <label><input type="radio" name="tm-kind" value="all" defaultChecked /><span><T bn="সবাই" en="All" /></span></label>
              {kinds.map(k => (
                <label key={k}><input type="radio" name="tm-kind" value={k} /><span><Icon name={KIND[k][2]} /><T bn={KIND[k][0]} en={KIND[k][1]} /></span></label>
              ))}
            </div>
          )}
          <div className="lh-tm-grid" data-reveal-group>{items.map(t => <Card key={t.id} t={t} />)}</div>
        </div>
      </div>
    </section>
  );
}
