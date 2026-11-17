// Small server-rendered charts for the admin analytics page — no chart library.
// Plot shapes are SVG/CSS; every label is HTML text so it stays crisp at any width.
// Hover/focus tooltips come from `data-tip` ("title|line|line") via lh.js; every chart
// also has a "Show as table" view, so nothing is told by colour alone.
import { Fragment, type ReactNode } from 'react';

export type Fmt = (n: number) => string;
export const fmtInt: Fmt = n => Math.round(n).toLocaleString('en-US');
export const fmtTk: Fmt = n => '৳' + Math.round(n).toLocaleString('en-US');
export const fmtPct: Fmt = n => (Math.round(n * 10) / 10).toLocaleString('en-US') + '%';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export const dayLabel = (d: string) => `${Number(d.slice(8, 10))} ${MONTHS[Number(d.slice(5, 7)) - 1]}`;

/** Axis top = 4 whole, "nice" steps (1, 2 or 5 × 10^n, never below 1) — so every tick label is distinct. */
function niceMax(max: number) {
  const raw = Math.max(1, max / 4);
  const p = 10 ** Math.floor(Math.log10(raw));
  const step = [1, 2, 5, 10].map(m => m * p).find(s => s >= raw) ?? 10 * p;
  return step * 4;
}
const ticks = (top: number) => [0, 0.25, 0.5, 0.75, 1].map(f => top * f);
function YAxis({ top, fmt }: { top: number; fmt: Fmt }) {
  return <div className="an-y" aria-hidden="true">{ticks(top).map(t => <span key={t} style={{ top: `${100 - (t / top) * 100}%` }}>{fmt(t)}</span>)}</div>;
}
/** Up to ~6 evenly spaced x labels, always including the last day. */
function xLabels(days: string[]) {
  const step = Math.max(1, Math.ceil(days.length / 6));
  return days.map((d, i) => ((days.length - 1 - i) % step === 0 ? dayLabel(d) : ''));
}

export function ChartCard({ title, sub, legend, children, table, wide = false }:
  { title: string; sub?: string; legend?: { label: string; slot: number }[]; children: ReactNode; table?: ReactNode; wide?: boolean }) {
  return (
    <section className={`pn-card an-card${wide ? ' an-wide' : ''}`}>
      <header className="an-head">
        <div><h3>{title}</h3>{sub && <p className="pn-muted">{sub}</p>}</div>
        {legend && legend.length > 1 && (
          <ul className="an-legend">{legend.map(l => <li key={l.label}><i className={`an-s${l.slot}`}></i>{l.label}</li>)}</ul>
        )}
      </header>
      {children}
      {table && <details className="an-table"><summary>Show as table</summary><div className="data-table-wrap">{table}</div></details>}
    </section>
  );
}

/** Lines over days (one shared y-axis — use only for series in the same unit). */
export function LineChart({ days, series, fmt = fmtInt }:
  { days: string[]; series: { label: string; slot: number; values: number[] }[]; fmt?: Fmt }) {
  const top = niceMax(Math.max(0, ...series.flatMap(s => s.values)));
  const n = days.length, W = 1000, H = 300;
  const x = (i: number) => (n === 1 ? W / 2 : (i / (n - 1)) * W);
  const y = (v: number) => H - (v / top) * H;
  const labels = xLabels(days);
  return (
    <div className="an-plot">
      <YAxis top={top} fmt={fmt} />
      <div className="an-area">
        <div className="an-grid" aria-hidden="true">{ticks(top).map(t => <i key={t} style={{ top: `${100 - (t / top) * 100}%` }}></i>)}</div>
        <svg viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" aria-hidden="true">
          {series.map(s => (
            <path key={s.label} className={`an-line an-s${s.slot}`} vectorEffect="non-scaling-stroke"
              d={s.values.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`).join('')} />
          ))}
        </svg>
        {/* dots for the last value of each line, with a direct label */}
        {series.map(s => n > 0 && (
          <span key={s.label} className={`an-dot an-s${s.slot}`} style={{ left: `${(x(n - 1) / W) * 100}%`, top: `${(y(s.values[n - 1]) / H) * 100}%` }}></span>
        ))}
        <div className="an-cols">
          {days.map((d, i) => (
            <span key={d} className="an-col" data-tip={[dayLabel(d), ...series.map(s => `${s.label}: ${fmt(s.values[i])}`)].join('|')}></span>
          ))}
        </div>
      </div>
      <div className="an-x">{labels.map((l, i) => <span key={days[i]}>{l}</span>)}</div>
    </div>
  );
}

/** One bar per day (single series). */
export function DayBars({ days, values, label, fmt = fmtInt }: { days: string[]; values: number[]; label: string; fmt?: Fmt }) {
  const top = niceMax(Math.max(0, ...values));
  const labels = xLabels(days);
  return (
    <div className="an-plot">
      <YAxis top={top} fmt={fmt} />
      <div className="an-area">
        <div className="an-grid" aria-hidden="true">{ticks(top).map(t => <i key={t} style={{ top: `${100 - (t / top) * 100}%` }}></i>)}</div>
        <div className="an-bars" style={{ gap: days.length > 45 ? 1 : 2 }}>
          {days.map((d, i) => (
            <span key={d} className="an-bar-col" data-tip={`${dayLabel(d)}|${label}: ${fmt(values[i])}`}>
              <i className="an-bar an-s1" style={{ height: `${(values[i] / top) * 100}%` }}></i>
            </span>
          ))}
        </div>
      </div>
      <div className="an-x">{labels.map((l, i) => <span key={days[i]}>{l}</span>)}</div>
    </div>
  );
}

/** Ranked horizontal bars (one hue); value text sits at the end of every row. */
export function RankBars({ rows, fmt = fmtInt, unit = '', empty = 'No data yet' }:
  { rows: { label: string; value: number; note?: string; href?: string }[]; fmt?: Fmt; unit?: string; empty?: string }) {
  if (!rows.length || rows.every(r => !r.value)) return <p className="an-empty">{empty}</p>;
  const top = Math.max(...rows.map(r => r.value));
  return (
    <ol className="an-rank">
      {rows.map(r => (
        <li key={r.label} data-tip={[r.label, `${fmt(r.value)}${unit}`, ...(r.note ? [r.note] : [])].join('|')}>
          <span className="an-rank-label">{r.href ? <a href={r.href}>{r.label}</a> : r.label}</span>
          <span className="an-rank-track"><i className="an-s1" style={{ width: `${top ? Math.max(1.5, (r.value / top) * 100) : 0}%` }}></i></span>
          <b className="an-rank-val">{fmt(r.value)}{r.note && <small>{r.note}</small>}</b>
        </li>
      ))}
    </ol>
  );
}

/** Part-to-whole: one segmented bar + a legend with each part's share. Max 3 colours, the rest grey. */
export function SplitBar({ parts, fmt = fmtInt }: { parts: { label: string; value: number }[]; fmt?: Fmt }) {
  const total = parts.reduce((a, p) => a + p.value, 0);
  if (!total) return <p className="an-empty">No data yet</p>;
  const shown = parts.filter(p => p.value > 0);
  return (
    <>
      <div className="an-split" role="img" aria-label={shown.map(p => `${p.label} ${fmtPct((p.value / total) * 100)}`).join(', ')}>
        {shown.map((p, i) => (
          <i key={p.label} className={i < 3 ? `an-s${i + 1}` : 'an-s0'} style={{ flexGrow: p.value }}
             data-tip={`${p.label}|${fmt(p.value)} · ${fmtPct((p.value / total) * 100)}`}></i>
        ))}
      </div>
      <ul className="an-split-legend">
        {shown.map((p, i) => (
          <li key={p.label}><i className={i < 3 ? `an-s${i + 1}` : 'an-s0'}></i><span>{p.label}</span><b>{fmtPct((p.value / total) * 100)}</b><small>{fmt(p.value)}</small></li>
        ))}
      </ul>
    </>
  );
}

const DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
/** Weekday × hour heat map (sequential, one hue). grid[dow][hour]. */
export function HourHeat({ grid }: { grid: number[][] }) {
  const max = Math.max(0, ...grid.flat());
  if (!max) return <p className="an-empty">No visits yet</p>;
  const step = (v: number) => (v === 0 ? 0 : Math.min(5, 1 + Math.floor((v / max) * 5)));
  const hr = (h: number) => `${h % 12 || 12}${h < 12 ? 'am' : 'pm'}`;
  return (
    <div className="an-heat-wrap">
      <div className="an-heat">
        <span></span>
        {Array.from({ length: 24 }, (_, h) => <span key={h} className="an-heat-h">{h % 3 === 0 ? hr(h) : ''}</span>)}
        {grid.map((row, d) => (
          <Fragment key={d}>
            <span className="an-heat-d">{DOW[d]}</span>
            {row.map((v, h) => <i key={`${d}-${h}`} className={`an-q${step(v)}`} data-tip={`${DOW[d]} ${hr(h)}–${hr((h + 1) % 24)}|${fmtInt(v)} page views`}></i>)}
          </Fragment>
        ))}
      </div>
      <p className="an-heat-key"><span>Fewer</span>{[0, 1, 2, 3, 4, 5].map(q => <i key={q} className={`an-q${q}`}></i>)}<span>More</span></p>
    </div>
  );
}

/** Headline number with change vs the previous period of the same length. */
export function Kpi({ label, value, now, before, fmt = fmtInt, icon }:
  { label: string; value?: string; now: number; before: number; fmt?: Fmt; icon: string }) {
  const diff = before ? ((now - before) / before) * 100 : null;
  const dir = diff === null ? 'flat' : diff > 0.5 ? 'up' : diff < -0.5 ? 'down' : 'flat';
  return (
    <div className="an-kpi">
      <p className="an-kpi-label"><span className="material-icons-round" aria-hidden="true">{icon}</span>{label}</p>
      <p className="an-kpi-value">{value ?? fmt(now)}</p>
      <p className={`an-kpi-delta is-${dir}`}>
        {diff === null ? (now ? 'new this period' : 'no change')
          : <><span className="material-icons-round" aria-hidden="true">{dir === 'up' ? 'trending_up' : dir === 'down' ? 'trending_down' : 'trending_flat'}</span>
            {dir === 'up' ? '+' : ''}{fmtPct(diff)} vs previous ({fmt(before)})</>}
      </p>
    </div>
  );
}
