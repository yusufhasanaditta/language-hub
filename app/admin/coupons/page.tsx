import { q } from '@/lib/db';
import type { Row } from '@/lib/db';
import { requireAdmin } from '@/lib/auth';
import { PageHead, Badge, ActionButton } from '@/components/panel';

export const metadata = { title: 'Coupons' };

/** "All courses" or "Only selected courses" + a checkbox per course, grouped by language. */
function CoursePicker({ courses, all, chosen, name }: { courses: Row[]; all: boolean; chosen: Set<number>; name: string }) {
  const groups = new Map<string, Row[]>();
  for (const c of courses) groups.set(c.cat_name_en ?? 'Other', [...(groups.get(c.cat_name_en ?? 'Other') ?? []), c]);
  return (
    <fieldset className="pn-coupon-scope" data-scope>
      <legend>Works on</legend>
      <label className="pn-check"><input type="radio" name={`${name}_scope`} value="all" defaultChecked={all} /> All courses</label>
      <label className="pn-check"><input type="radio" name={`${name}_scope`} value="selected" defaultChecked={!all} /> Only the courses I tick below</label>
      <div className="pn-coupon-courses">
        {courses.length === 0 ? <p className="pn-muted">No courses yet.</p> : [...groups].map(([lang, list]) => (
          <div key={lang}>
            <p className="pn-coupon-lang">{lang}</p>
            {list.map(c => (
              <label key={c.id} className="pn-check">
                <input type="checkbox" name="course_ids" value={c.id} defaultChecked={chosen.has(c.id)} />
                {c.title_bn}{c.status !== 'published' && <span className="pn-muted"> ({c.status})</span>}
              </label>
            ))}
          </div>
        ))}
      </div>
    </fieldset>
  );
}

export default async function CouponsPage() {
  await requireAdmin();
  const [coupons, courses, links] = await Promise.all([
    q`SELECT *, to_char(expires_at, 'YYYY-MM-DD') AS exp FROM coupons ORDER BY created_at DESC`,
    q`SELECT c.id, c.title_bn, c.status, cat.name_en AS cat_name_en FROM courses c LEFT JOIN categories cat ON cat.id = c.category_id
      WHERE c.status <> 'archived' ORDER BY cat.sort_order NULLS LAST, c.title_bn`,
    q<{ coupon_id: number; course_id: number; title_bn: string }>`SELECT cc.coupon_id, cc.course_id, c.title_bn FROM coupon_courses cc JOIN courses c ON c.id = cc.course_id`,
  ]);
  const linked = (id: number) => links.filter(l => l.coupon_id === id);

  return (
    <>
      <PageHead title="Coupons" sub="Discount codes students enter at checkout — for every course or only the courses you choose." />
      <div className="pn-card">
        <h2>New coupon</h2>
        <form method="post" action="/do/admin/coupon-save" className="pn-grid">
          <div><label>Code</label><input name="code" className="form-control" required maxLength={50} pattern="[A-Za-z0-9_-]+" placeholder="LH10" style={{ textTransform: 'uppercase' }} /></div>
          <div><label>Type</label><select name="type" className="form-control"><option value="percent">Percent off</option><option value="flat">Flat amount off (BDT)</option></select></div>
          <div><label>Value</label><input name="value" type="number" min={1} step="1" className="form-control" required /></div>
          <div><label>Minimum order (BDT)</label><input name="min_order" type="number" min={0} className="form-control" defaultValue={0} /></div>
          <div><label>Max uses (0 = unlimited)</label><input name="max_uses" type="number" min={0} className="form-control" defaultValue={0} /></div>
          <div><label>Expires on (optional)</label><input name="expires_at" type="date" className="form-control" /></div>
          <div className="pn-full"><CoursePicker courses={courses} all chosen={new Set()} name="new" /></div>
          <div className="pn-full"><button type="submit" className="btn btn-primary">Create coupon</button></div>
        </form>
      </div>
      <div className="data-table-wrap">
        <table className="data-table">
          <thead><tr><th>Code</th><th>Discount</th><th>Works on</th><th>Min order</th><th>Used</th><th>Expires</th><th>Status</th><th></th></tr></thead>
          <tbody>
            {coupons.length === 0 ? <tr><td colSpan={8} className="pn-empty">No coupons yet</td></tr> : coupons.map(c => {
              const mine = linked(c.id);
              return (
                <tr key={c.id}>
                  <td><b>{c.code}</b></td>
                  <td>{c.type === 'percent' ? `${Number(c.value)}%` : `৳${Number(c.value)}`}</td>
                  <td style={{ maxWidth: 280 }}>
                    {c.all_courses ? 'All courses'
                      : mine.length ? mine.map(m => m.title_bn).join(', ')
                      : <span className="pn-danger-text">No course selected — the code works nowhere</span>}
                    <details className="pn-coupon-edit">
                      <summary>Change courses</summary>
                      <form method="post" action="/do/admin/coupon-courses">
                        <input type="hidden" name="id" value={c.id} />
                        <CoursePicker courses={courses} all={c.all_courses} chosen={new Set(mine.map(m => m.course_id))} name={`c${c.id}`} />
                        <button type="submit" className="btn btn-primary btn-xs">Save courses</button>
                      </form>
                    </details>
                  </td>
                  <td>৳{Number(c.min_order)}</td>
                  <td>{c.used_count}{c.max_uses > 0 ? ` / ${c.max_uses}` : ''}</td>
                  <td className="pn-muted">{c.exp ?? 'never'}</td>
                  <td><Badge s={c.is_active ? 'active' : 'archived'} /></td>
                  <td style={{ whiteSpace: 'nowrap' }}>
                    <ActionButton action="/do/admin/coupon-toggle" fields={{ id: c.id }} label={c.is_active ? 'Disable' : 'Enable'} />{' '}
                    <ActionButton action="/do/admin/coupon-delete" fields={{ id: c.id }} label="Delete" cls="btn btn-outline btn-xs pn-danger" confirm="Delete this coupon?" />
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </>
  );
}
