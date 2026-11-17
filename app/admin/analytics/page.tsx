// Admin analytics: visitors, course reach, sales and students over a chosen period, plus the
// "learners shown" numbers per course. Visits come from page_views (see /api/track).
import { raw } from '@/lib/db';
import { requireAdmin } from '@/lib/auth';
import { PanelHero } from '@/components/panel';
import { Icon } from '@/components/ui';
import { ChartCard, LineChart, DayBars, RankBars, SplitBar, HourHeat, Kpi, dayLabel, fmtInt, fmtTk, fmtPct } from '@/components/Charts';

export const metadata = { title: 'Analytics' };

const RANGES = [7, 30, 90] as const;
const TODAY = `(now() AT TIME ZONE 'Asia/Dhaka')::date`;
/** Start of a Dhaka calendar day as a timestamp, for timestamptz columns. */
const dayStart = (d: string) => `((${d})::timestamp AT TIME ZONE 'Asia/Dhaka')`;

/** Totals for the Dhaka days (from, to] counted back from today: from = days ago (exclusive). */
const totalsSql = (fromAgo: number, toAgo: number) => {
  const lo = `${TODAY} - ${fromAgo - 1}`, hi = `${TODAY} - ${toAgo}`;
  return `SELECT
    (SELECT count(DISTINCT day::text || visitor) FROM page_views WHERE day BETWEEN ${lo} AND ${hi})::int AS visitors,
    (SELECT count(*) FROM page_views WHERE day BETWEEN ${lo} AND ${hi})::int AS views,
    (SELECT COALESCE(sum(total), 0) FROM orders WHERE status = 'paid' AND paid_at >= ${dayStart(lo)} AND paid_at < ${dayStart(`${hi} + 1`)})::float AS revenue,
    (SELECT count(*) FROM enrollments WHERE enrolled_at >= ${dayStart(lo)} AND enrolled_at < ${dayStart(`${hi} + 1`)})::int AS enrolls,
    (SELECT count(*) FROM users WHERE role_id = 1 AND created_at >= ${dayStart(lo)} AND created_at < ${dayStart(`${hi} + 1`)})::int AS signups`;
};

export default async function AnalyticsPage({ searchParams }: { searchParams: Promise<{ range?: string }> }) {
  await requireAdmin();
  const asked = Number((await searchParams).range);
  const R: number = (RANGES as readonly number[]).includes(asked) ? asked : 30;
  const lo = `${TODAY} - ${R - 1}`, start = dayStart(lo);

  const [daily, [cur], [prev], courses, pages, refs, devices, heat, langs, orderStatus, payMethods, allTime] = await Promise.all([
    raw(`WITH d AS (SELECT generate_series(${lo}, ${TODAY}, interval '1 day')::date AS day)
      SELECT to_char(d.day, 'YYYY-MM-DD') AS day,
        (SELECT count(DISTINCT visitor) FROM page_views v WHERE v.day = d.day)::int AS visitors,
        (SELECT count(*) FROM page_views v WHERE v.day = d.day)::int AS views,
        (SELECT COALESCE(sum(total), 0) FROM orders o WHERE o.status = 'paid' AND (o.paid_at AT TIME ZONE 'Asia/Dhaka')::date = d.day)::float AS revenue,
        (SELECT count(*) FROM users u WHERE u.role_id = 1 AND (u.created_at AT TIME ZONE 'Asia/Dhaka')::date = d.day)::int AS signups,
        (SELECT count(*) FROM enrollments e WHERE (e.enrolled_at AT TIME ZONE 'Asia/Dhaka')::date = d.day)::int AS enrolls
      FROM d ORDER BY d.day`),
    raw(totalsSql(R, 0)),
    raw(totalsSql(2 * R, R)),
    raw(`SELECT c.id, c.title_bn, c.slug, c.extra_students, c.max_students, c.status,
        (SELECT count(DISTINCT v.day::text || v.visitor) FROM page_views v WHERE v.course_id = c.id AND v.day >= ${lo})::int AS visitors,
        (SELECT count(*) FROM page_views v WHERE v.course_id = c.id AND v.day >= ${lo})::int AS views,
        (SELECT count(*) FROM enrollments e WHERE e.course_id = c.id AND e.enrolled_at >= ${start})::int AS enrolls,
        (SELECT COALESCE(sum(o.total), 0) FROM orders o WHERE o.status = 'paid' AND o.paid_at >= ${start}
           AND o.id IN (SELECT order_id FROM order_items WHERE course_id = c.id))::float AS revenue,
        (SELECT count(*) FROM enrollments e WHERE e.course_id = c.id AND e.status = 'active')::int AS real_students
      FROM courses c WHERE c.status <> 'archived' ORDER BY c.status = 'published' DESC, c.id DESC`),
    raw(`SELECT path, count(*)::int AS views, count(DISTINCT day::text || visitor)::int AS visitors
      FROM page_views WHERE day >= ${lo} GROUP BY path ORDER BY views DESC LIMIT 8`),
    raw(`SELECT COALESCE(referrer, '') AS ref, count(DISTINCT day::text || visitor)::int AS visitors
      FROM page_views WHERE day >= ${lo} GROUP BY 1 ORDER BY visitors DESC`),
    raw(`SELECT device, count(DISTINCT day::text || visitor)::int AS visitors FROM page_views WHERE day >= ${lo} GROUP BY device`),
    raw(`SELECT extract(dow FROM created_at AT TIME ZONE 'Asia/Dhaka')::int AS d, extract(hour FROM created_at AT TIME ZONE 'Asia/Dhaka')::int AS h, count(*)::int AS n
      FROM page_views WHERE day >= ${lo} GROUP BY 1, 2`),
    raw(`SELECT cat.name_en AS label, count(*)::int AS n FROM enrollments e JOIN courses c ON c.id = e.course_id JOIN categories cat ON cat.id = c.category_id
      WHERE e.status = 'active' GROUP BY cat.name_en ORDER BY n DESC`),
    raw(`SELECT status, count(*)::int AS n FROM orders WHERE created_at >= ${start}
        AND NOT (status = 'pending' AND pay_method = 'sslcommerz') GROUP BY status ORDER BY n DESC`),
    raw(`SELECT COALESCE(NULLIF(pay_method, ''), 'other') AS m, count(*)::int AS n, COALESCE(sum(total), 0)::float AS amount
      FROM orders WHERE status = 'paid' AND paid_at >= ${start} GROUP BY 1 ORDER BY n DESC`),
    raw(`SELECT (SELECT count(*) FROM users WHERE role_id = 1)::int AS students,
        (SELECT COALESCE(sum(total), 0) FROM orders WHERE status = 'paid')::float AS revenue,
        (SELECT count(*) FROM enrollments WHERE status = 'active')::int AS enrolls,
        (SELECT min(day) FROM page_views) AS tracking_since`),
  ]);

  const days = daily.map(r => r.day as string);
  const col = (k: string) => daily.map(r => Number(r[k]) || 0);
  const conv = (e: number, v: number) => (v ? (e / v) * 100 : 0);
  const published = courses.filter(c => c.status === 'published');
  const reach = [...published].sort((a, b) => b.visitors - a.visitors);
  const refRows = (() => {
    const top = refs.slice(0, 7).map(r => ({ label: r.ref || 'Direct / typed / app', value: r.visitors }));
    const rest = refs.slice(7).reduce((a, r) => a + r.visitors, 0);
    return rest ? [...top, { label: 'Other sites', value: rest }] : top;
  })();
  const deviceOrder = ['mobile', 'desktop', 'tablet'];
  const deviceParts = deviceOrder.map(d => ({ label: d[0].toUpperCase() + d.slice(1), value: devices.find(x => x.device === d)?.visitors ?? 0 }));
  const grid = Array.from({ length: 7 }, (_, d) => Array.from({ length: 24 }, (_, h) => heat.find(x => x.d === d && x.h === h)?.n ?? 0));
  const statusParts = ['paid', 'pending', 'failed', 'cancelled', 'refunded']
    .map(s => ({ label: s[0].toUpperCase() + s.slice(1), value: orderStatus.find(x => x.status === s)?.n ?? 0 }))
    .concat(orderStatus.filter(x => !['paid', 'pending', 'failed', 'cancelled', 'refunded'].includes(x.status)).map(x => ({ label: String(x.status), value: x.n })));
  const methodName = (m: string) => ({ sslcommerz: 'Online (SSLCommerz)', manual: 'bKash / Nagad (manual)', free: 'Free' } as Record<string, string>)[m] ?? m;
  const since = allTime[0].tracking_since ? dayLabel(new Date(allTime[0].tracking_since).toISOString().slice(0, 10)) : null;

  const dayTable = (cols: [string, string, (n: number) => string][]) => (
    <table className="data-table"><thead><tr><th>Day</th>{cols.map(c => <th key={c[0]}>{c[1]}</th>)}</tr></thead>
      <tbody>{[...daily].reverse().map(r => <tr key={r.day}><td>{dayLabel(r.day)}</td>{cols.map(c => <td key={c[0]}>{c[2](Number(r[c[0]]) || 0)}</td>)}</tr>)}</tbody></table>
  );

  return (
    <>
      <PanelHero eyebrow="Admin · Analytics" title="How the site is doing"
        sub={since ? `Visits counted since ${since} · all dates in Bangladesh time` : 'Visit counting has just started — numbers fill in as people visit'}>
        <nav className="an-range" aria-label="Period">
          {RANGES.map(r => <a key={r} href={`/admin/analytics?range=${r}`} className={r === R ? 'is-on' : ''} aria-current={r === R ? 'page' : undefined}>Last {r} days</a>)}
        </nav>
      </PanelHero>

      <div className="an-kpis">
        <Kpi icon="visibility" label="Visitors" now={cur.visitors} before={prev.visitors} />
        <Kpi icon="pageview" label="Page views" now={cur.views} before={prev.views} />
        <Kpi icon="payments" label="Revenue" now={cur.revenue} before={prev.revenue} fmt={fmtTk} />
        <Kpi icon="how_to_reg" label="New enrollments" now={cur.enrolls} before={prev.enrolls} />
        <Kpi icon="person_add" label="New students" now={cur.signups} before={prev.signups} />
        <Kpi icon="percent" label="Visitor → enrollment" value={fmtPct(conv(cur.enrolls, cur.visitors))} now={conv(cur.enrolls, cur.visitors)} before={conv(prev.enrolls, prev.visitors)} fmt={fmtPct} />
      </div>
      <p className="an-alltime pn-muted">All time: <b>{fmtInt(allTime[0].students)}</b> students · <b>{fmtInt(allTime[0].enrolls)}</b> active enrollments · <b>{fmtTk(allTime[0].revenue)}</b> revenue</p>

      <div className="an-grid2">
        <ChartCard wide title="Visitors and page views" sub={`Per day, last ${R} days`}
          legend={[{ label: 'Visitors', slot: 1 }, { label: 'Page views', slot: 2 }]}
          table={dayTable([['visitors', 'Visitors', fmtInt], ['views', 'Page views', fmtInt]])}>
          <LineChart days={days} series={[{ label: 'Visitors', slot: 1, values: col('visitors') }, { label: 'Page views', slot: 2, values: col('views') }]} />
        </ChartCard>

        <ChartCard title="Revenue" sub={`Paid orders per day · total ${fmtTk(cur.revenue)}`} table={dayTable([['revenue', 'Revenue', fmtTk]])}>
          <DayBars days={days} values={col('revenue')} label="Revenue" fmt={fmtTk} />
        </ChartCard>

        <ChartCard title="New enrollments" sub={`Per day · total ${fmtInt(cur.enrolls)}`} table={dayTable([['enrolls', 'Enrollments', fmtInt], ['signups', 'New students', fmtInt]])}>
          <DayBars days={days} values={col('enrolls')} label="Enrollments" />
        </ChartCard>

        <ChartCard wide title="Course reach" sub="Which course people look at most, and how many of them buy"
          table={<table className="data-table"><thead><tr><th>Course</th><th>Visitors</th><th>Page views</th><th>Enrollments</th><th>Conversion</th><th>Revenue</th></tr></thead>
            <tbody>{reach.map(c => <tr key={c.id}><td>{c.title_bn}</td><td>{fmtInt(c.visitors)}</td><td>{fmtInt(c.views)}</td><td>{fmtInt(c.enrolls)}</td><td>{fmtPct(conv(c.enrolls, c.visitors))}</td><td>{fmtTk(c.revenue)}</td></tr>)}</tbody></table>}>
          <RankBars rows={reach.map(c => ({ label: c.title_bn, value: c.visitors, href: `/course/${encodeURIComponent(c.slug)}`,
            note: `${fmtInt(c.enrolls)} enrolled · ${fmtPct(conv(c.enrolls, c.visitors))} conversion · ${fmtTk(c.revenue)}` }))}
            unit=" visitors" empty="No course page visits yet in this period" />
        </ChartCard>

        <ChartCard title="Revenue by course" sub={`Last ${R} days`}>
          <RankBars rows={[...published].sort((a, b) => b.revenue - a.revenue).map(c => ({ label: c.title_bn, value: c.revenue }))} fmt={fmtTk} empty="No paid orders in this period" />
        </ChartCard>

        <ChartCard title="Students by language" sub="Active enrollments, all time">
          <RankBars rows={langs.map(l => ({ label: l.label, value: l.n }))} unit=" students" empty="No enrollments yet" />
        </ChartCard>

        <ChartCard title="Where visitors come from" sub="Website that sent them (Facebook, Google…)"
          table={<table className="data-table"><thead><tr><th>Source</th><th>Visitors</th></tr></thead><tbody>{refRows.map(r => <tr key={r.label}><td>{r.label}</td><td>{fmtInt(r.value)}</td></tr>)}</tbody></table>}>
          <RankBars rows={refRows} unit=" visitors" empty="No visits yet in this period" />
        </ChartCard>

        <ChartCard title="Most visited pages" sub={`Last ${R} days`}
          table={<table className="data-table"><thead><tr><th>Page</th><th>Page views</th><th>Visitors</th></tr></thead><tbody>{pages.map(p => <tr key={p.path}><td>{p.path}</td><td>{fmtInt(p.views)}</td><td>{fmtInt(p.visitors)}</td></tr>)}</tbody></table>}>
          <RankBars rows={pages.map(p => ({ label: p.path === '/' ? 'Home page' : decodeSafe(p.path), value: p.views, href: p.path, note: `${fmtInt(p.visitors)} visitors` }))} unit=" views" empty="No visits yet in this period" />
        </ChartCard>

        <ChartCard title="Devices" sub="Phone, computer or tablet (visitors)">
          <SplitBar parts={deviceParts} />
        </ChartCard>

        <ChartCard title="Orders" sub={`Created in the last ${R} days`}>
          <SplitBar parts={statusParts} />
          <h4 className="an-sub">Paid orders by payment method</h4>
          <RankBars rows={payMethods.map(p => ({ label: methodName(p.m), value: p.n, note: fmtTk(p.amount) }))} unit=" orders" empty="No paid orders in this period" />
        </ChartCard>

        <ChartCard wide title="Busiest times" sub="Page views by weekday and hour (Bangladesh time) — good times to post or run ads">
          <HourHeat grid={grid} />
        </ChartCard>
      </div>

      <section className="pn-card" id="learners">
        <h2><Icon name="groups" /> Learners shown on the site</h2>
        <p className="pn-muted">Shown number = real buyers + the starting number you type. Each new purchase adds 1 automatically.
          It is used on course cards, the course page, seat counts and the teacher&apos;s learner total. Keep it below the seat limit, or the course shows as full.</p>
        <form method="post" action="/do/admin/course-extra">
          <div className="data-table-wrap">
            <table className="data-table">
              <thead><tr><th>Course</th><th>Real buyers</th><th>Starting number</th><th>Shown on site</th><th>Seat limit</th></tr></thead>
              <tbody>
                {courses.map(c => {
                  const shown = c.real_students + c.extra_students;
                  return (
                    <tr key={c.id}>
                      <td><a className="pn-link" href={`/admin/courses/${c.id}`}>{c.title_bn}</a>{c.status !== 'published' && <span className="pn-muted"> · {c.status}</span>}</td>
                      <td>{fmtInt(c.real_students)}</td>
                      <td><input className="form-control an-num" type="number" name={`extra_${c.id}`} min={0} max={100000} defaultValue={c.extra_students} aria-label={`Starting number for ${c.title_bn}`} /></td>
                      <td><b>{fmtInt(shown)}</b></td>
                      <td>{c.max_students > 0 ? <>{fmtInt(c.max_students)}{shown >= c.max_students && <span className="pn-danger"> · full</span>}</> : 'No limit'}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <button className="btn btn-primary" type="submit" style={{ marginTop: 14 }}><Icon name="save" /> Save learner numbers</button>
        </form>
      </section>
    </>
  );
}

function decodeSafe(p: string) { try { return decodeURIComponent(p); } catch { return p; } }
