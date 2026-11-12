// Course listing with search, language/type/free filters and sorting.
// Used by /courses and /category/[slug].
import { q } from '@/lib/db';
import { publishedCourses } from '@/lib/courses';
import { T, N } from '@/lib/i18n';
import { lang, langStyle } from '@/lib/langs';
import { CourseCard, Icon } from './ui';
import { PAY_PRICE_SQL } from '@/lib/format';

export type ListParams = { q?: string; type?: string; free?: string; sort?: string; category?: string };

const SORTS: Record<string, [string, string, string]> = {
  popular: ['জনপ্রিয়', 'Popular', 'student_count DESC, c.is_featured DESC'],
  newest: ['নতুন', 'Newest', 'c.created_at DESC'],
  price_low: ['কম দাম', 'Price ↑', `${PAY_PRICE_SQL} ASC`],
  price_high: ['বেশি দাম', 'Price ↓', `${PAY_PRICE_SQL} DESC`],
};

export async function CourseList({ params, basePath }: { params: ListParams; basePath: string }) {
  const search = (params.q ?? '').trim().slice(0, 80);
  const type = params.type === 'live' || params.type === 'recorded' ? params.type : '';
  const free = params.free === '1';
  const sort = params.sort && SORTS[params.sort] ? params.sort : 'popular';
  const categories = await q`SELECT * FROM categories ORDER BY sort_order, id`;
  const activeCat = categories.find(c => c.slug === params.category) ?? null;

  const where: string[] = [];
  const args: unknown[] = [];
  if (activeCat) { args.push(activeCat.id); where.push(`c.category_id = $${args.length}`); }
  if (type === 'live') where.push(`c.type = 'live'`);
  if (type === 'recorded') where.push(`c.type <> 'live'`);
  if (free) where.push(`(c.is_free OR ${PAY_PRICE_SQL} <= 0)`);
  if (search) {
    args.push(`%${search}%`);
    where.push(`(c.title_bn ILIKE $${args.length} OR c.title_en ILIKE $${args.length} OR u.name ILIKE $${args.length} OR cat.name_en ILIKE $${args.length})`);
  }
  const courses = await publishedCourses(where.map(w => 'AND ' + w).join(' '), args, SORTS[sort][2]);

  // Build a link that changes some query params and keeps the rest.
  const link = (change: Partial<ListParams>) => {
    const p = new URLSearchParams();
    const merged = { q: search, type, free: free ? '1' : '', sort: sort === 'popular' ? '' : sort, ...change };
    for (const [k, v] of Object.entries(merged)) if (v) p.set(k, String(v));
    const s = p.toString();
    return (change.category !== undefined ? (change.category ? `/category/${change.category}` : '/courses') : basePath) + (s ? `?${s}` : '');
  };
  const L = activeCat ? lang(activeCat.slug) : null;

  return (
    <>
      <section className="lh-list-head" style={activeCat ? langStyle(activeCat.slug) : undefined}>
        <div className="lh-glow" aria-hidden="true"></div>
        {L && <span className="lh-list-glyph" aria-hidden="true">{L.glyph}</span>}
        <div className="container" data-reveal>
          <p className="lh-eyebrow"><N v={courses.length} /> <T bn="টি কোর্স" en="courses" /></p>
          <h1 className="lh-list-title">
            {activeCat ? <T bn={`${activeCat.name_bn} শিখুন`} en={`Learn ${activeCat.name_en}`} />
              : search ? <T bn={`“${search}” এর ফলাফল`} en={`Results for “${search}”`} />
              : type === 'live' ? <T bn="লাইভ ব্যাচ" en="Live batches" />
              : free ? <T bn="ফ্রি কোর্স" en="Free courses" />
              : <T bn="সব কোর্স" en="All courses" />}
          </h1>
          <form method="GET" action={basePath} className="lh-search" role="search">
            {type && <input type="hidden" name="type" value={type} />}
            {free && <input type="hidden" name="free" value="1" />}
            <Icon name="search" />
            <label htmlFor="q" className="sr-only">Search</label>
            <input type="search" id="q" name="q" defaultValue={search} placeholder="কোর্স বা শিক্ষক খুঁজুন…" autoComplete="off" />
            {search && <a href={link({ q: '' })} className="lh-search-x" aria-label="Clear search"><Icon name="close" /></a>}
          </form>
        </div>
      </section>

      <div className="lh-filters">
        <div className="lh-chips" role="list">
          <a role="listitem" href={link({ category: '' })} className={`lh-fchip ${!activeCat ? 'is-on' : ''}`}><T bn="সব ভাষা" en="All" /></a>
          {categories.map(cat => (
            <a key={cat.id} role="listitem" href={link({ category: cat.slug })} className={`lh-fchip ${activeCat?.id === cat.id ? 'is-on' : ''}`} style={langStyle(cat.slug)}>
              <span className="lh-fchip-g">{lang(cat.slug).glyph}</span><T bn={cat.name_bn} en={cat.name_en} />
            </a>
          ))}
          <span className="lh-chips-sep" aria-hidden="true"></span>
          <a role="listitem" href={link({ type: type === 'live' ? '' : 'live', free: '' })} className={`lh-fchip ${type === 'live' ? 'is-on' : ''}`}><span className="lh-chip-dot"></span><T bn="লাইভ" en="Live" /></a>
          <a role="listitem" href={link({ type: type === 'recorded' ? '' : 'recorded', free: '' })} className={`lh-fchip ${type === 'recorded' ? 'is-on' : ''}`}><T bn="রেকর্ডেড" en="Recorded" /></a>
          <a role="listitem" href={link({ free: free ? '' : '1', type: '' })} className={`lh-fchip ${free ? 'is-on' : ''}`}><T bn="ফ্রি" en="Free" /></a>
        </div>
        <form method="GET" action={basePath} className="lh-sort">
          {search && <input type="hidden" name="q" value={search} />}
          {type && <input type="hidden" name="type" value={type} />}
          {free && <input type="hidden" name="free" value="1" />}
          <span className="sr-only">Sort</span>
          <Icon name="swap_vert" />
          <select name="sort" defaultValue={sort} data-autosubmit>
            {Object.entries(SORTS).map(([k, [bn, en]]) => <option key={k} value={k}>{bn} · {en}</option>)}
          </select>
          <noscript><button type="submit">OK</button></noscript>
        </form>
        <script dangerouslySetInnerHTML={{ __html:
          `document.querySelectorAll('[data-autosubmit]').forEach(function(s){s.onchange=function(){s.form.submit()}});` }} />
      </div>

      <section className="container lh-list-body">
        {courses.length === 0 ? (
          <div className="lh-empty" data-reveal>
            <Icon name="travel_explore" />
            <h2><T bn="কোনো কোর্স পাওয়া যায়নি" en="No courses found" /></h2>
            <p><T bn="অন্য শব্দে খুঁজুন বা ফিল্টার বদলান।" en="Try another word or change the filters." /></p>
            <a href="/courses" className="btn btn-primary"><T bn="সব কোর্স দেখুন" en="See all courses" /></a>
          </div>
        ) : (
          <div className="lh-grid" data-reveal-group>
            {courses.map(c => <CourseCard key={c.id} c={c} />)}
          </div>
        )}
      </section>
    </>
  );
}
