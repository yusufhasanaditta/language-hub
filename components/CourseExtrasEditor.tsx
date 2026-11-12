// Panel editors for a course's teaching team (admin) and its extra details (admin + the course's teachers).
import type { Row } from '@/lib/db';
import { avatarUrl } from '@/lib/format';
import { TEAM_ROLES, DETAIL_KINDS, DETAIL_ICONS, type DetailKind } from '@/lib/team';
import { ActionButton } from './panel';
import { Icon } from './ui';

/** Suggestions for every "role" input with list="team-roles". */
export const RoleList = () => (
  <datalist id="team-roles">{TEAM_ROLES.map(([en]) => <option key={en} value={en} />)}</datalist>
);

/** One team member: avatar, name, lead tag and a second line (email or role); extra controls go in children. */
export function MemberRow({ t, sub, children }: { t: Row; sub: React.ReactNode; children?: React.ReactNode }) {
  return (
    <li className={`pn-member ${t.is_lead ? 'is-lead' : ''}`}>
      <img src={avatarUrl(t.avatar, t.name)} alt="" width={44} height={44} />
      <div className="pn-member-who">
        <b>{t.name}{t.is_lead && <span className="pn-lead-tag"><Icon name="star" />Lead</span>}</b>
        <small>{sub}</small>
      </div>
      {children}
    </li>
  );
}

/** The "add a teacher to a course" form; `pick` is the select of teachers (course page) or courses (user page). */
export function TeamAddForm({ hidden, pick, disabled = false }: { hidden: Record<string, string | number>; pick: React.ReactNode; disabled?: boolean }) {
  return (
    <form method="post" action="/do/admin/team-add" className="pn-team-add">
      {Object.entries(hidden).map(([k, v]) => <input key={k} type="hidden" name={k} value={v} />)}
      {pick}
      <div><label htmlFor="team-role">Role on this course</label>
        <input id="team-role" name="role" list="team-roles" className="form-control" maxLength={60} placeholder="Instructor" /></div>
      <label className="pn-check"><input type="checkbox" name="lead" value="1" /> Make lead teacher</label>
      <button type="submit" className="btn btn-primary btn-sm" disabled={disabled}><Icon name="person_add" /> Add to course</button>
    </form>
  );
}

/** Admin: add any teacher to the course, set their role and order, pick the lead, remove. */
export function TeamEditor({ courseId, team, teachers }: { courseId: number; team: Row[]; teachers: Row[] }) {
  const onTeam = new Set(team.map(t => t.user_id));
  const free = teachers.filter(t => !onTeam.has(t.id));
  const f = { course_id: courseId };
  return (
    <div className="pn-card" id="team">
      <h2><Icon name="groups" /> Teaching team <span className="pn-count">{team.length}</span></h2>
      <p className="pn-muted">Add as many teachers as you like. Everyone on the team can manage this course from their teacher panel; the lead is shown first on the course page.</p>
      <RoleList />
      {team.length === 0 && <p className="pn-empty-note"><Icon name="person_add" /> No teacher yet — add the first one below. They become the lead.</p>}
      <ul className="pn-team" data-reveal-group>
        {team.map(t => (
          <MemberRow key={t.tp_id} t={t} sub={t.email}>
            <form method="post" action="/do/admin/team-update" className="pn-member-form">
              <input type="hidden" name="course_id" value={courseId} />
              <input type="hidden" name="tp_id" value={t.tp_id} />
              <label className="sr-only" htmlFor={`role-${t.tp_id}`}>Role</label>
              <input id={`role-${t.tp_id}`} name="role" list="team-roles" className="form-control" defaultValue={t.role} maxLength={60} />
              <label className="sr-only" htmlFor={`ord-${t.tp_id}`}>Order</label>
              <input id={`ord-${t.tp_id}`} name="sort_order" type="number" className="form-control pn-ord" defaultValue={t.sort_order} title="Order" />
              <button type="submit" className="btn btn-outline btn-xs">Save</button>
            </form>
            <div className="pn-member-actions">
              {!t.is_lead && <ActionButton action="/do/admin/team-lead" fields={{ ...f, tp_id: t.tp_id }} label="Make lead" />}
              <ActionButton action="/do/admin/team-remove" fields={{ ...f, tp_id: t.tp_id }} label="Remove" cls="btn btn-outline btn-xs pn-danger" confirm={`Remove ${t.name} from this course?`} />
            </div>
          </MemberRow>
        ))}
      </ul>
      <TeamAddForm hidden={f} disabled={!free.length} pick={
        <div><label htmlFor="team-user">Teacher</label>
          <select id="team-user" name="user_id" className="form-control" required defaultValue="">
            <option value="" disabled>{free.length ? 'Choose a teacher…' : 'Every teacher is already on this course'}</option>
            {free.map(t => <option key={t.id} value={t.id}>{t.name}{t.email ? ` — ${t.email}` : ''}</option>)}
          </select></div>
      } />
      <p className="pn-hint">Not in the list? <a className="pn-link" href="/admin/users/new">Create a user</a> with the teacher role, then add them here.</p>
    </div>
  );
}

/** Add (no d) or edit (d) one detail of the given kind. */
function DetailForm({ courseId, action, kind, d }: { courseId: number; action: string; kind: DetailKind; d?: Row }) {
  const K = DETAIL_KINDS[kind];
  return (
    <form method="post" action={action}>
      <input type="hidden" name="course_id" value={courseId} />
      <input type="hidden" name="kind" value={kind} />
      {d && <input type="hidden" name="id" value={d.id} />}
      <div className="pn-grid">
        <div><label>Icon</label>
          <select name="icon" className="form-control" defaultValue={d?.icon ?? ''}>
            <option value="">— default —</option>
            {DETAIL_ICONS.map(i => <option key={i} value={i}>{i.replace(/_/g, ' ')}</option>)}
          </select></div>
        <div className="pn-full"><label>{K.title}</label>
          <input name="title" className="form-control" required maxLength={200} defaultValue={d?.title ?? ''} placeholder={K.titleEg} /></div>
        <div className="pn-full"><label>{K.body}</label>
          <textarea name="body" className="form-control" maxLength={5000} rows={K.rows} defaultValue={d?.body ?? ''} placeholder={K.bodyEg}></textarea></div>
      </div>
      <div className="pn-actions" style={{ marginTop: 12 }}><button className="btn btn-primary btn-sm" type="submit">{d ? 'Save' : 'Add'}</button></div>
    </form>
  );
}

/** Admin or a teacher on the course: add any number of facts, info sections and FAQs. */
export function DetailsEditor({ courseId, details, base }: { courseId: number; details: Row[]; base: string }) {
  const act = (a: string) => `${base}/${a}`;
  return (
    <div className="pn-card" id="details">
      <h2><Icon name="dashboard_customize" /> Course details <span className="pn-count">{details.length}</span></h2>
      <p className="pn-muted">Extra information shown on the course page — add as many as you need.</p>
      <div className="pn-detail-groups">
        {(Object.keys(DETAIL_KINDS) as DetailKind[]).map(kind => {
          const items = details.filter(d => d.kind === kind);
          return (
            <section key={kind} className={`pn-dgroup pn-dgroup-${kind}`}>
              <h3>{DETAIL_KINDS[kind].label} <small>{DETAIL_KINDS[kind].hint}</small></h3>
              {items.length === 0 && <p className="pn-muted pn-small">None yet.</p>}
              <ol className="pn-dlist">
                {items.map((d, i) => (
                  <li key={d.id} className="pn-ditem">
                    <span className="pn-dicon"><Icon name={d.icon || DETAIL_KINDS[kind].icon} /></span>
                    <span className="pn-grow"><b>{d.title}</b>{d.body && <small>{d.body.length > 90 ? d.body.slice(0, 90) + '…' : d.body}</small>}</span>
                    <span className="pn-dmove">
                      <ActionButton action={act('detail-move')} fields={{ course_id: courseId, id: d.id, dir: -1 }} label={<Icon name="arrow_upward" />} cls={`btn btn-outline btn-xs ${i === 0 ? 'is-off' : ''}`} />
                      <ActionButton action={act('detail-move')} fields={{ course_id: courseId, id: d.id, dir: 1 }} label={<Icon name="arrow_downward" />} cls={`btn btn-outline btn-xs ${i === items.length - 1 ? 'is-off' : ''}`} />
                    </span>
                    <details>
                      <summary>Edit</summary>
                      <DetailForm courseId={courseId} action={act('detail-save')} kind={kind} d={d} />
                      <div style={{ padding: '0 16px 16px' }}>
                        <ActionButton action={act('detail-delete')} fields={{ course_id: courseId, id: d.id }} label="Delete" cls="btn btn-outline btn-xs pn-danger" confirm="Delete this detail?" />
                      </div>
                    </details>
                  </li>
                ))}
              </ol>
              <details className="pn-add-detail">
                <summary><Icon name="add_circle" /> {DETAIL_KINDS[kind].add}</summary>
                <DetailForm courseId={courseId} action={act('detail-save')} kind={kind} />
              </details>
            </section>
          );
        })}
      </div>
    </div>
  );
}
