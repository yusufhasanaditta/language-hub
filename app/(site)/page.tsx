import { q, type Row } from '@/lib/db';
import { publishedCourses, courseUrl } from '@/lib/courses';
import { T, N, DateBn } from '@/lib/i18n';
import { lang, langStyle } from '@/lib/langs';
import { priceOf, isFreeCourse } from '@/lib/format';
import { HOME_FAQS } from '@/lib/content';
import { CourseCard, Countdown, Icon, Price } from '@/components/ui';

const SKY: [string, number, number, number, number][] = [
  ['中', 8, 14, 1.0, 7], ['あ', 84, 10, 0.6, 5.5], ['한', 76, 58, 1.3, 6], ['A', 14, 62, 0.8, 5],
  ['文', 46, 6, 0.4, 3.2], ['カ', 92, 34, 1.6, 3.4], ['글', 4, 38, 1.5, 3], ['Z', 60, 40, 0.5, 2.6],
];

export default async function HomePage() {
  const [all, langs] = await Promise.all([
    publishedCourses(),
    q`SELECT cat.*, (SELECT count(*)::int FROM courses c WHERE c.category_id = cat.id AND c.status = 'published') AS n
      FROM categories cat ORDER BY cat.sort_order, cat.id`,
  ]);
  const today = new Date(Date.now() + 6 * 3600_000).toISOString().slice(0, 10);
  const next = all
    .filter(c => c.type === 'live' && c.batch_date && c.batch_date >= today
      && (c.max_students === 0 || c.student_count < c.max_students))
    .sort((a, b) => a.batch_date.localeCompare(b.batch_date))[0];

  const flapColors = langs.map(c => `var(${lang(c.slug).cssVar})`);

  return (
    <>
      <section className="lh-pick lh-hero2" data-hero style={{ '--lang': flapColors[0] ?? 'var(--lh-brand)' } as React.CSSProperties}>
        <div className="lh-glow" aria-hidden="true"></div>
        <div className="lh-sky" aria-hidden="true">
          {SKY.map(([g, x, y, d, size], i) => (
            <span key={i} style={{ '--x': `${x}%`, '--y': `${y}%`, '--d': d, '--fs': `${size}rem`, '--dl': `${i * -1.7}s` } as React.CSSProperties}>{g}</span>
          ))}
        </div>
        <svg className="lh-route" viewBox="0 0 400 160" preserveAspectRatio="none" aria-hidden="true">
          <path id="lhRoute" d="M-10 140 C 90 30, 250 10, 410 70" fill="none" />
          <g className="lh-plane">
            <path d="M-7 -1.6 L4 -1.6 L9 0 L4 1.6 L-7 1.6 Z M-2 -1.6 L1 -7 L3 -7 L2 -1.6 Z M-2 1.6 L1 7 L3 7 L2 1.6 Z M-7 -1.6 L-8 -4 L-6 -4 L-4.5 -1.6 Z M-7 1.6 L-8 4 L-6 4 L-4.5 1.6 Z" />
            <animateMotion dur="9s" repeatCount="indefinite" rotate="auto" keyPoints="0;1" keyTimes="0;1" calcMode="spline" keySplines=".45 0 .55 1">
              <mpath href="#lhRoute" />
            </animateMotion>
          </g>
        </svg>

        <div className="container lh-pick-head" data-hero-fade>
          <p className="lh-stamp"><span><T bn="ভর্তি চলছে" en="Now boarding" /></span></p>
          <h1 className="lh-pick-title">
            <span className="lh-h-line"><T bn="বাংলায় শিখুন" en="Learn in Bangla" /></span>
            <span className="sr-only">{langs.map(c => c.name_bn).join(', ')}</span>
            <span className="lh-flap" data-flap aria-hidden="true"
                  data-bn={langs.map(c => c.name_bn).join('|')} data-en={langs.map(c => c.name_en).join('|')}
                  data-colors={flapColors.join('|')}>
              <span className="lh-flap-size">{[...langs.map(c => c.name_bn), ...langs.map(c => c.name_en)].map((w, i) => <span key={i}>{w}</span>)}</span>
              <span className="lh-flap-face lh-flap-top"></span>
              <span className="lh-flap-face lh-flap-bot"></span>
              <span className="lh-flap-face lh-flap-top lh-flap-fly-top"></span>
              <span className="lh-flap-face lh-flap-bot lh-flap-fly-bot"></span>
            </span>
          </h1>
          <p className="lh-pick-sub"><T bn="শুরু থেকে পরীক্ষা পর্যন্ত — আপনার ভাষার যাত্রা এখান থেকেই।" en="From day one to exam day — your language journey starts here." /></p>
        </div>

        <div className="lh-carousel" data-carousel>
          <div className="lh-carousel-track" data-track>
            {langs.map(cat => {
              const L = lang(cat.slug);
              return (
                <a key={cat.id} href={`/category/${cat.slug}`} className="lh-lcard" style={langStyle(cat.slug)} data-slide>
                  <span className="lh-lcard-shine" aria-hidden="true"></span>
                  <span className="lh-lcard-glyph" aria-hidden="true">{L.glyph}</span>
                  <span className="lh-lcard-top">
                    {L.exam && <span className="lh-lcard-exam">{L.exam}</span>}
                    <span className="lh-lcard-count"><N v={cat.n} /> <T bn="কোর্স" en="courses" /></span>
                  </span>
                  <span className="lh-lcard-bottom">
                    <span className="lh-lcard-hello">{L.hello}</span>
                    <span className="lh-lcard-name"><T bn={cat.name_bn} en={cat.name_en} /></span>
                    <span className="lh-lcard-cta"><T bn="কোর্স দেখুন" en="See courses" /> <Icon name="arrow_forward" /></span>
                  </span>
                </a>
              );
            })}
          </div>
          <div className="lh-dots" data-dots aria-hidden="true"></div>
        </div>
      </section>

      <AllCourses all={all} langs={langs} />
      {next && <NextBatch c={next} />}

      <section className="lh-section lh-section-tint">
        <div className="container lh-narrow">
          <div className="lh-section-head lh-center" data-reveal>
            <p className="lh-eyebrow"><T bn="দুইভাবে শিখুন" en="Two ways to learn" /></p>
            <h2><T bn="আপনার জন্য কোনটি?" en="Which one fits you?" /></h2>
          </div>
          <div className="lh-switch" data-switch data-reveal>
            <div className="lh-seg" role="tablist">
              <span className="lh-seg-thumb" aria-hidden="true"></span>
              <button type="button" role="tab" aria-selected="true" data-tab="0"><span className="lh-chip-dot"></span><T bn="লাইভ ব্যাচ" en="Live batch" /></button>
              <button type="button" role="tab" aria-selected="false" data-tab="1"><T bn="রেকর্ডেড" en="Recorded" /></button>
            </div>
            <div className="lh-panes">
              <div className="lh-panes-track">
                <article className="lh-plan lh-plan-live" role="tabpanel">
                  <h3><T bn="শিক্ষকের সাথে সরাসরি" en="Face-to-face with a teacher" /></h3>
                  <ul>
                    <li><T bn="ব্যাচে সর্বোচ্চ ১৫ জন" en="At most 15 students per batch" /></li>
                    <li><T bn="সরাসরি প্রশ্ন ও স্পিকিং প্র্যাকটিস" en="Ask questions, practise speaking live" /></li>
                    <li><T bn="প্রতিটি ক্লাসের রেকর্ডিং" en="Recording of every class" /></li>
                    <li><T bn="নির্দিষ্ট রুটিন ও পরীক্ষার প্রস্তুতি" en="Fixed schedule and exam preparation" /></li>
                  </ul>
                  <a href="/courses?type=live" className="btn btn-primary lh-btn-block"><T bn="লাইভ ব্যাচ দেখুন" en="See live batches" /></a>
                </article>
                <article className="lh-plan" role="tabpanel">
                  <h3><T bn="নিজের সময়ে, নিজের গতিতে" en="Your time, your pace" /></h3>
                  <ul>
                    <li><T bn="যখন খুশি, যতবার খুশি দেখুন" en="Watch any time, as often as you like" /></li>
                    <li><T bn="ছোট ছোট ভিডিও লেসন" en="Short, focused video lessons" /></li>
                    <li><T bn="কম খরচে শুরু" en="Lower price to get started" /></li>
                    <li><T bn="মোবাইলে দেখার জন্য তৈরি" en="Made for watching on your phone" /></li>
                  </ul>
                  <a href="/courses?type=recorded" className="btn btn-outline lh-btn-block"><T bn="রেকর্ডেড কোর্স দেখুন" en="See recorded courses" /></a>
                </article>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section className="lh-section">
        <div className="container lh-narrow">
          <div className="lh-section-head lh-center" data-reveal>
            <p className="lh-eyebrow"><T bn="প্রশ্নোত্তর" en="FAQ" /></p>
            <h2><T bn="যা জানতে চান" en="Good questions" /></h2>
          </div>
          <div className="lh-faq" data-reveal-group>
            {HOME_FAQS.map(([qbn, qen, abn, aen]) => (
              <details key={qen}>
                <summary><T bn={qbn} en={qen} /><Icon name="add" /></summary>
                <div className="lh-faq-a"><p><T bn={abn} en={aen} /></p></div>
              </details>
            ))}
          </div>
        </div>
      </section>
    </>
  );
}

function AllCourses({ all, langs }: { all: Row[]; langs: Row[] }) {
  if (!all.length) return null;
  const count = (f: (c: Row) => boolean) => all.filter(f).length;
  const chips: { key: string; bn: string; en: string; n: number; slug?: string }[] = [{ key: 'all', bn: 'সব', en: 'All', n: all.length }];
  for (const cat of langs) {
    const n = count(c => c.cat_slug === cat.slug);
    if (n) chips.push({ key: `lang:${cat.slug}`, bn: cat.name_bn, en: cat.name_en, n, slug: cat.slug });
  }
  for (const [key, bn, en, f] of [
    ['type:live', 'লাইভ', 'Live', (c: Row) => c.type === 'live'],
    ['type:recorded', 'রেকর্ডেড', 'Recorded', (c: Row) => c.type !== 'live'],
    ['free', 'ফ্রি', 'Free', (c: Row) => isFreeCourse(c)],
  ] as const) {
    const n = count(f);
    if (n) chips.push({ key, bn, en, n });
  }
  return (
    <section className="lh-section lh-all" id="courses" data-course-filter data-page-size="12">
      <div className="container">
        <div className="lh-section-head lh-section-head-row" data-reveal>
          <div>
            <p className="lh-eyebrow"><N v={all.length} /> <T bn="টি কোর্স" en="courses" /></p>
            <h2><T bn="সব কোর্স এক জায়গায়" en="Every course in one place" /></h2>
          </div>
        </div>
      </div>
      <div className="lh-all-chips" role="toolbar" aria-label="Filter courses">
        {chips.map((ch, i) => (
          <button key={ch.key} type="button" className={`lh-fchip ${i === 0 ? 'is-on' : ''}`} data-filter={ch.key}
                  aria-pressed={i === 0} style={ch.slug ? langStyle(ch.slug) : undefined}>
            {ch.slug ? <span className="lh-fchip-g">{lang(ch.slug).glyph}</span> : ch.key === 'type:live' ? <span className="lh-chip-dot"></span> : null}
            <T bn={ch.bn} en={ch.en} /> <span className="lh-fchip-n"><N v={ch.n} /></span>
          </button>
        ))}
      </div>
      <div className="container">
        <div className="lh-grid lh-all-grid" data-items>
          {all.map(c => <CourseCard key={c.id} c={c} />)}
        </div>
        <p className="lh-all-empty" data-empty hidden><T bn="এই ফিল্টারে কোনো কোর্স নেই।" en="No courses in this filter." /></p>
        <div className="lh-all-more">
          <button type="button" className="btn btn-outline" data-more hidden><T bn="আরও দেখুন" en="Show more" /> <Icon name="expand_more" /></button>
        </div>
      </div>
    </section>
  );
}

function NextBatch({ c }: { c: Row }) {
  const L = lang(c.cat_slug);
  const [now, was] = priceOf(c);
  const seatsLeft = c.max_students > 0 ? Math.max(0, c.max_students - c.student_count) : null;
  return (
    <section className="lh-section lh-section-pass">
      <div className="container lh-pass-wrap">
        <div className="lh-section-head" data-reveal>
          <p className="lh-eyebrow"><T bn="পরবর্তী লাইভ ব্যাচ" en="Next live batch" /></p>
          <h2><T bn="সিট সীমিত — মাত্র ১৫ জন" en="Only 15 seats per batch" /></h2>
          <p className="lh-muted"><T bn="শিক্ষকের সাথে সরাসরি ক্লাস, প্রতিটি ক্লাসের রেকর্ডিংসহ।" en="Learn live with a teacher — every class is recorded too." /></p>
        </div>
        <div className="lh-tilt-stage" data-reveal="right">
          <article className="lh-pass" style={langStyle(c.cat_slug)} data-tilt>
            <header className="lh-pass-head">
              <span><T bn="বোর্ডিং পাস" en="Boarding pass" /></span>
              <span className="lh-chip lh-chip-live"><i></i><T bn="লাইভ" en="Live" /></span>
            </header>
            <div className="lh-pass-route">
              <div><small><T bn="থেকে" en="From" /></small><b>বাংলা</b></div>
              <Icon name="flight_takeoff" className="lh-pass-plane" />
              <div className="lh-pass-to"><small><T bn="গন্তব্য" en="To" /></small><b>{L.native}</b></div>
            </div>
            <h3 className="lh-pass-title"><T bn={c.title_bn} en={c.title_en || c.title_bn} /></h3>
            <dl className="lh-pass-grid">
              <div><dt><T bn="শুরু" en="Starts" /></dt><dd><DateBn date={c.batch_date} /></dd></div>
              <div><dt><T bn="ক্লাস" en="Classes" /></dt><dd><N v={c.lesson_count} /></dd></div>
              <div><dt><T bn="সিট বাকি" en="Seats left" /></dt>
                <dd className={seatsLeft !== null && seatsLeft <= 5 ? 'lh-hot' : ''}>{seatsLeft === null ? '—' : <><N v={seatsLeft} />/<N v={c.max_students} /></>}</dd></div>
            </dl>
            <Countdown to={`${c.batch_date}T00:00:00+06:00`} />
            <div className="lh-pass-perf" aria-hidden="true"></div>
            <footer className="lh-pass-foot">
              <div><Price now={now} was={was} free={false} /></div>
              <a href={courseUrl(c)} className="btn btn-accent"><T bn="সিট বুক করুন" en="Book a seat" /></a>
            </footer>
          </article>
        </div>
      </div>
    </section>
  );
}
