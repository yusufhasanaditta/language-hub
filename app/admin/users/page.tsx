import { raw } from '@/lib/db';
import { requireAdmin, ROLE_NAMES } from '@/lib/auth';
import { fmtDate } from '@/lib/format';
import { PageHead, Badge } from '@/components/panel';
import { Icon } from '@/components/ui';

export const metadata = { title: 'Users' };

type SP = { q?: string; role?: string };

export default async function UsersPage({ searchParams }: { searchParams: Promise<SP> }) {
  await requireAdmin();
  const sp = await searchParams;
  const search = (sp.q ?? '').trim().slice(0, 80);
  const role = Number(sp.role) || 0;
  const where: string[] = [], args: unknown[] = [];
  if (search) { args.push(`%${search}%`); where.push(`(u.name ILIKE $1 OR u.email ILIKE $1 OR u.phone ILIKE $1 OR u.username ILIKE $1)`); }
  if (role) { args.push(role); where.push(`u.role_id = $${args.length}`); }
  const users = await raw(`
    SELECT u.id, u.name, u.email, u.phone, u.role_id, u.is_active, u.created_at, u.last_login, (u.password_hash IS NOT NULL) AS has_pw,
           (SELECT count(*)::int FROM enrollments e WHERE e.user_id = u.id AND e.status = 'active') AS courses
    FROM users u ${where.length ? 'WHERE ' + where.join(' AND ') : ''}
    ORDER BY u.role_id DESC, u.created_at DESC LIMIT 200`, args);

  return (
    <>
      <PageHead title="Users & teachers" sub="Students, teachers and staff. Open a user to change their role, password or teacher profile.">
        <a href="/admin/users/new" className="btn btn-primary btn-sm"><Icon name="person_add" /> Add user</a>
      </PageHead>
      <form method="get" className="pn-toolbar">
        <input name="q" className="form-control" placeholder="Search name, email, phone…" defaultValue={search} />
        <select name="role" className="form-control" defaultValue={role || ''}>
          <option value="">All roles</option>
          {Object.entries(ROLE_NAMES).map(([id, n]) => <option key={id} value={id}>{n}</option>)}
        </select>
        <button className="btn btn-outline btn-sm" type="submit">Filter</button>
      </form>
      <div className="data-table-wrap">
        <table className="data-table">
          <thead><tr><th>Name</th><th>Email / phone</th><th>Role</th><th>Courses</th><th>Last login</th><th>Status</th></tr></thead>
          <tbody>
            {users.length === 0 ? <tr><td colSpan={6} className="pn-empty">No users found</td></tr> : users.map(u => (
              <tr key={u.id}>
                <td><a className="pn-link" href={`/admin/users/${u.id}`}>{u.name}</a>{!u.has_pw && <div className="pn-muted">no password (email code only)</div>}</td>
                <td>{u.email ?? '—'}<div className="pn-muted">{u.phone ?? ''}</div></td>
                <td><Badge s={ROLE_NAMES[u.role_id]} /></td>
                <td>{u.courses}</td>
                <td className="pn-muted">{u.last_login ? fmtDate(u.last_login) : 'never'}</td>
                <td><Badge s={u.is_active ? 'active' : 'suspended'} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
