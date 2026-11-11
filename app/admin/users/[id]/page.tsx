import { notFound } from 'next/navigation';
import { one, q } from '@/lib/db';
import { requireAdmin, ROLE_NAMES, ROLE } from '@/lib/auth';
import { fmtDate, fmtDateTime } from '@/lib/format';
import { PageHead, Badge, ActionButton } from '@/components/panel';

export const metadata = { title: 'Edit user' };

export default async function UserEditPage({ params }: { params: Promise<{ id: string }> }) {
  const me = await requireAdmin();
  const idParam = (await params).id;
  const isNew = idParam === 'new';
  const u = isNew ? null : await one`SELECT u.*, (u.password_hash IS NOT NULL) AS has_pw FROM users u WHERE u.id = ${Number(idParam) || 0}`;
  if (!isNew && !u) notFound();
  const [tp, enrolls, sessions, courses] = u ? await Promise.all([
    one`SELECT * FROM teacher_profiles WHERE user_id = ${u.id}`,
    q`SELECT e.id, e.status, e.progress_pct, e.enrolled_at, c.title_bn, c.id AS course_id FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE e.user_id = ${u.id} ORDER BY e.enrolled_at DESC`,
    q`SELECT id, device_name, ip, last_seen_at FROM user_sessions WHERE user_id = ${u.id} ORDER BY last_seen_at DESC`,
    q`SELECT id, title_bn FROM courses ORDER BY title_bn`,
  ]) : [null, [], [], []];
  const v = (k: string) => (u?.[k] ?? '') as string;

  return (
    <>
      <PageHead title={isNew ? 'Add user' : u!.name} sub={isNew ? 'Create a student, teacher or admin account with a password.' : <>Joined {fmtDate(u!.created_at)} · <Badge s={ROLE_NAMES[u!.role_id]} /></>}>
        <a href="/admin/users" className="btn btn-outline btn-sm">← All users</a>
      </PageHead>

      <div className="pn-card">
        <h2>Account</h2>
        <form method="post" action="/do/admin/user-save" className="pn-grid">
          <input type="hidden" name="id" value={u?.id ?? ''} />
          <div><label>Full name</label><input name="name" className="form-control" required maxLength={100} defaultValue={v('name')} /></div>
          <div><label>Email</label><input name="email" type="email" className="form-control" required maxLength={150} defaultValue={v('email')} /></div>
          <div><label>Phone</label><input name="phone" className="form-control" maxLength={15} defaultValue={v('phone')} /></div>
          <div><label>Username (public profile URL /t/username)</label><input name="username" className="form-control" maxLength={80} pattern="[a-z0-9-]+" defaultValue={v('username')} /></div>
          <div>
            <label>Role</label>
            <select name="role_id" className="form-control" defaultValue={u?.role_id ?? ROLE.STUDENT} disabled={u?.id === me.id}>
              {Object.entries(ROLE_NAMES).map(([id, n]) => <option key={id} value={id}>{n}</option>)}
            </select>
            {u?.id === me.id && <p className="pn-hint">You cannot change your own role.</p>}
          </div>
          <div>
            <label>{isNew ? 'Password' : 'New password (leave empty to keep)'}</label>
            <input name="password" type="password" className="form-control" minLength={8} required={isNew} autoComplete="new-password" />
            {!isNew && <p className="pn-hint">{u!.has_pw ? 'Has a password.' : 'No password yet — can log in with an email code only.'} Setting one signs out their devices.</p>}
          </div>
          <label className="pn-check pn-full"><input type="checkbox" name="is_active" value="1" defaultChecked={u ? u.is_active : true} disabled={u?.id === me.id} /> Account active (unticked = cannot log in)</label>
          <div className="pn-full"><button type="submit" className="btn btn-primary">{isNew ? 'Create user' : 'Save'}</button></div>
        </form>
      </div>

      {u && (u.role_id === ROLE.TEACHER || tp) && (
        <div className="pn-card">
          <h2>Teacher profile</h2>
          <form method="post" action="/do/admin/teacher-profile" className="pn-grid">
            <input type="hidden" name="user_id" value={u.id} />
            <div><label>Designation</label><input name="designation" className="form-control" maxLength={100} defaultValue={tp?.designation ?? ''} placeholder="Japanese Instructor (JLPT N2)" /></div>
            <div><label>Featured order (higher shows first)</label><input name="featured_order" type="number" min={0} max={255} className="form-control" defaultValue={tp?.featured_order ?? 0} /></div>
            <div className="pn-full"><label>Headline</label><input name="headline" className="form-control" maxLength={255} defaultValue={tp?.headline ?? ''} /></div>
            <div className="pn-full"><label>Bio</label><textarea name="bio" className="form-control" maxLength={3000} defaultValue={tp?.bio ?? ''}></textarea></div>
            <div className="pn-full"><button type="submit" className="btn btn-primary">Save teacher profile</button></div>
          </form>
        </div>
      )}

      {u && (
        <div className="pn-two">
          <div className="pn-card">
            <h2>Enrollments</h2>
            <table className="data-table">
              <tbody>
                {enrolls.length === 0 ? <tr><td className="pn-empty">Not enrolled in any course</td></tr> : enrolls.map(e => (
                  <tr key={e.id}>
                    <td>{e.title_bn}<div className="pn-muted">{e.progress_pct}% · {fmtDate(e.enrolled_at)}</div></td>
                    <td><Badge s={e.status} /></td>
                    <td><ActionButton action="/do/admin/enrollment-status" fields={{ id: e.id, status: e.status === 'active' ? 'suspended' : 'active' }} label={e.status === 'active' ? 'Suspend' : 'Activate'} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
            <form method="post" action="/do/admin/enroll" className="pn-toolbar" style={{ marginTop: 12 }}>
              <input type="hidden" name="email" value={u.email ?? ''} />
              <select name="course_id" className="form-control" required>
                <option value="">Give access to a course…</option>
                {courses.map(c => <option key={c.id} value={c.id}>{c.title_bn}</option>)}
              </select>
              <button className="btn btn-outline btn-sm" type="submit">Enroll</button>
            </form>
          </div>
          <div className="pn-card">
            <h2>Signed-in devices</h2>
            <table className="data-table">
              <tbody>
                {sessions.length === 0 ? <tr><td className="pn-empty">Not signed in anywhere</td></tr> : sessions.map(s => (
                  <tr key={s.id}><td>{s.device_name}<div className="pn-muted">{s.ip} · {fmtDateTime(s.last_seen_at)}</div></td></tr>
                ))}
              </tbody>
            </table>
            {sessions.length > 0 && u.id !== me.id && <div style={{ marginTop: 12 }}><ActionButton action="/do/admin/user-signout" fields={{ id: u.id }} label="Sign out of all devices" confirm="Sign this user out everywhere?" /></div>}
          </div>
        </div>
      )}
    </>
  );
}
