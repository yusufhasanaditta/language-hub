import { q } from '@/lib/db';
import { requireAdmin } from '@/lib/auth';
import { PageHead, Badge, ActionButton } from '@/components/panel';

export const metadata = { title: 'Coupons' };

export default async function CouponsPage() {
  await requireAdmin();
  const coupons = await q`SELECT *, to_char(expires_at, 'YYYY-MM-DD') AS exp FROM coupons ORDER BY created_at DESC`;
  return (
    <>
      <PageHead title="Coupons" sub="Discount codes students enter at checkout." />
      <div className="pn-card">
        <h2>New coupon</h2>
        <form method="post" action="/do/admin/coupon-save" className="pn-grid">
          <div><label>Code</label><input name="code" className="form-control" required maxLength={50} pattern="[A-Za-z0-9_-]+" placeholder="LH10" style={{ textTransform: 'uppercase' }} /></div>
          <div><label>Type</label><select name="type" className="form-control"><option value="percent">Percent off</option><option value="flat">Flat amount off (BDT)</option></select></div>
          <div><label>Value</label><input name="value" type="number" min={1} step="1" className="form-control" required /></div>
          <div><label>Minimum order (BDT)</label><input name="min_order" type="number" min={0} className="form-control" defaultValue={0} /></div>
          <div><label>Max uses (0 = unlimited)</label><input name="max_uses" type="number" min={0} className="form-control" defaultValue={0} /></div>
          <div><label>Expires on (optional)</label><input name="expires_at" type="date" className="form-control" /></div>
          <div className="pn-full"><button type="submit" className="btn btn-primary">Create coupon</button></div>
        </form>
      </div>
      <div className="data-table-wrap">
        <table className="data-table">
          <thead><tr><th>Code</th><th>Discount</th><th>Min order</th><th>Used</th><th>Expires</th><th>Status</th><th></th></tr></thead>
          <tbody>
            {coupons.length === 0 ? <tr><td colSpan={7} className="pn-empty">No coupons yet</td></tr> : coupons.map(c => (
              <tr key={c.id}>
                <td><b>{c.code}</b></td>
                <td>{c.type === 'percent' ? `${Number(c.value)}%` : `৳${Number(c.value)}`}</td>
                <td>৳{Number(c.min_order)}</td>
                <td>{c.used_count}{c.max_uses > 0 ? ` / ${c.max_uses}` : ''}</td>
                <td className="pn-muted">{c.exp ?? 'never'}</td>
                <td><Badge s={c.is_active ? 'active' : 'archived'} /></td>
                <td style={{ whiteSpace: 'nowrap' }}>
                  <ActionButton action="/do/admin/coupon-toggle" fields={{ id: c.id }} label={c.is_active ? 'Disable' : 'Enable'} />{' '}
                  <ActionButton action="/do/admin/coupon-delete" fields={{ id: c.id }} label="Delete" cls="btn btn-outline btn-xs pn-danger" confirm="Delete this coupon?" />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
