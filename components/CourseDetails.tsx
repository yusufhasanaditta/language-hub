// Public rendering of a course's extra details: "at a glance" pass, info sections and FAQ.
import type { Row } from '@/lib/db';
import { T } from '@/lib/i18n';
import { Icon } from './ui';
import { DETAIL_KINDS } from '@/lib/team';

/** Text where lines starting with "-", "•" or "*" become a tick list; the rest are paragraphs. */
function RichLines({ text }: { text: string }) {
  const blocks: (string | string[])[] = [];
  for (const raw of text.replace(/\r/g, '').split('\n')) {
    const line = raw.trim();
    if (!line) continue;
    const item = line.match(/^[-•*]\s*(.+)$/);
    if (item) {
      const last = blocks.at(-1);
      if (Array.isArray(last)) last.push(item[1]); else blocks.push([item[1]]);
    } else blocks.push(line);
  }
  return (
    <>
      {blocks.map((b, i) => Array.isArray(b)
        ? <ul key={i} className="lh-ticks">{b.map((x, j) => <li key={j}>{x}</li>)}</ul>
        : <p key={i} className="lh-prose">{b}</p>)}
    </>
  );
}

/** "At a glance" — the facts as fields of a boarding pass. */
export function Glance({ facts }: { facts: Row[] }) {
  if (!facts.length) return null;
  return (
    <section className="lh-c-card lh-glance-card" data-reveal>
      <h2><T bn="এক নজরে" en="At a glance" /></h2>
      <dl className="lh-glance" data-reveal-group>
        {facts.map(f => (
          <div key={f.id} className="lh-glance-item">
            <span className="lh-glance-ico"><Icon name={f.icon || DETAIL_KINDS.fact.icon} /></span>
            <dt>{f.title}</dt>
            <dd>{f.body || '—'}</dd>
          </div>
        ))}
      </dl>
    </section>
  );
}

export function InfoSections({ sections }: { sections: Row[] }) {
  return (
    <>
      {sections.map(s => (
        <section key={s.id} className="lh-c-card lh-info" data-reveal>
          <h2><span className="lh-info-ico"><Icon name={s.icon || DETAIL_KINDS.section.icon} /></span>{s.title}</h2>
          {s.body && <RichLines text={s.body} />}
        </section>
      ))}
    </>
  );
}

export function CourseFaq({ faqs }: { faqs: Row[] }) {
  if (!faqs.length) return null;
  return (
    <section className="lh-c-card" data-reveal>
      <h2><T bn="প্রশ্নোত্তর" en="Questions & answers" /></h2>
      <div className="lh-faq">
        {faqs.map(f => (
          <details key={f.id}>
            <summary><span className="lh-faq-q">{f.icon && <Icon name={f.icon} />}{f.title}</span><Icon name="add" /></summary>
            {f.body && <div className="lh-faq-a"><RichLines text={f.body} /></div>}
          </details>
        ))}
      </div>
    </section>
  );
}
