import { q } from '@/lib/db';
import { requireAdmin } from '@/lib/auth';
import { PageHead, ActionButton } from '@/components/panel';

export const metadata = { title: 'Languages' };

export default async function CategoriesPage() {
  await requireAdmin();
  const cats = await q`SELECT cat.*, (SELECT count(*)::int FROM courses c WHERE c.category_id = cat.id) AS n FROM categories cat ORDER BY sort_order, id`;
  const Fields = ({ c }: { c?: Record<string, unknown> }) => (
    <>
      <input name="name_bn" className="form-control" placeholder="নাম (বাংলা)" required maxLength={100} defaultValue={(c?.name_bn as string) ?? ''} />
      <input name="name_en" className="form-control" placeholder="Name (English)" required maxLength={100} defaultValue={(c?.name_en as string) ?? ''} />
      <input name="slug" className="form-control" placeholder="slug" pattern="[a-z0-9-]+" required maxLength={100} defaultValue={(c?.slug as string) ?? ''} style={{ maxWidth: 140 }} />
      <input name="color" type="color" className="form-control" defaultValue={(c?.color as string) ?? '#5B21B6'} style={{ maxWidth: 60, minWidth: 60, padding: 2 }} />
      <input name="sort_order" type="number" className="form-control" defaultValue={(c?.sort_order as number) ?? 0} style={{ maxWidth: 80, minWidth: 70 }} title="Order" />
    </>
  );
  return (
    <>
      <PageHead title="Languages" sub="The languages shown in menus and the homepage carousel. Slugs chinese, japanese, korean and english get their own glyph and colour theme." />
      <div className="pn-card">
        {cats.map(c => (
          <div key={c.id} className="pn-toolbar" style={{ alignItems: 'center' }}>
            <form method="post" action="/do/admin/category-save" className="pn-toolbar" style={{ margin: 0, flex: 1 }}>
              <input type="hidden" name="id" value={c.id} />
              <Fields c={c} />
              <button className="btn btn-outline btn-sm" type="submit">Save</button>
            </form>
            <span className="pn-muted">{c.n} courses</span>
            {c.n === 0 && <ActionButton action="/do/admin/category-delete" fields={{ id: c.id }} label="Delete" cls="btn btn-outline btn-xs pn-danger" confirm="Delete this language?" />}
          </div>
        ))}
        <h3 style={{ marginTop: 24 }}>Add a language</h3>
        <form method="post" action="/do/admin/category-save" className="pn-toolbar">
          <Fields />
          <button className="btn btn-primary btn-sm" type="submit">Add</button>
        </form>
      </div>
    </>
  );
}
