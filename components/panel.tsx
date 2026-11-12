// Admin / teacher panel chrome: sidebar (desktop), top bar (phone), account menu.
import { headers } from 'next/headers';
import { isAdmin, isTeacher, ROLE_NAMES, type User } from '@/lib/auth';
import { avatarUrl, formatPrice, fmtDate } from '@/lib/format';
import { lang, langStyle } from '@/lib/langs';
import type { Row } from '@/lib/db';
import { Flash, Icon } from './ui';
import LegacyScripts from './LegacyScripts';

type Item = [href: string, icon: string, label: string] | null;

const ADMIN_NAV: Item[] = [
  ['/admin', 'dashboard', 'Dashboard'],
  null,
  ['/admin/courses', 'play_circle', 'Courses'],
  ['/admin/categories', 'category', 'Languages'],
  ['/admin/users', 'people', 'Users & teachers'],
  ['/admin/enrollments', 'how_to_reg', 'Enrollments'],
  null,
  ['/admin/orders', 'receipt', 'Orders & payments'],
  ['/admin/coupons', 'discount', 'Coupons'],
  null,
  ['/admin/messages', 'mail', 'Contact messages'],
  ['/admin/settings', 'settings', 'Settings'],
];

const TEACHER_NAV: Item[] = [
  ['/teacher', 'dashboard', 'Dashboard'],
  ['/teacher/courses', 'play_circle', 'My courses'],
  ['/teacher/live', 'videocam', 'Live classes'],
  ['/teacher/students', 'groups', 'Students'],
  ['/teacher/profile', 'badge', 'My public profile'],
];

export async function PanelShell({ user, kind, children }: { user: User; kind: 'admin' | 'teacher'; children: React.ReactNode }) {
  const path = ((await headers()).get('x-url') ?? '').split('?')[0];
  const nav = kind === 'admin' ? ADMIN_NAV : TEACHER_NAV;
  const home = kind === 'admin' ? '/admin' : '/teacher';
  const isActive = (href: string) => (href === home ? path === home : path === href || path.startsWith(href + '/'));
  const other = kind === 'admin' ? (isTeacher(user) ? ['/teacher', 'Teacher panel'] : null) : isAdmin(user) ? ['/admin', 'Admin panel'] : null;

  return (
    <>
      <Flash />
      <div className="admin-layout lh-panel">
        <header className="panel-topbar">
          <button className="icon-btn" id="panelNavToggle" aria-label="Menu" aria-controls="adminSidebar"><Icon name="menu" /></button>
          <a href={home} className="panel-topbar-brand"><img src="/image/language-hub-logo.png" alt="" /><span>{kind === 'admin' ? 'Admin' : 'Teacher'}</span></a>
          <button type="button" className="icon-btn lh-toggle" data-lh-theme aria-label="Dark mode">
            <span className="material-icons-round lh-ico-dark">dark_mode</span><span className="material-icons-round lh-ico-light">light_mode</span>
          </button>
          <a href="/" className="icon-btn" aria-label="Visit site"><Icon name="open_in_new" /></a>
        </header>

        <aside className="admin-sidebar" id="adminSidebar">
          <div className="pn-side-sky" aria-hidden="true"><span>中</span><span>あ</span><span>한</span><span>A</span></div>
          <div className="admin-logo">
            <a href={home} style={{ display: 'flex', alignItems: 'center', gap: 10, textDecoration: 'none' }}>
              <img src="/image/language-hub-logo.png" alt="Logo" style={{ height: 34, width: 34, borderRadius: '50%', background: '#F5F0FA', padding: 2 }} />
              <span>
                <span style={{ display: 'block', fontFamily: 'var(--font-display)', fontWeight: 800, color: '#fff', fontSize: '1.15rem', lineHeight: 1.1 }}>Language Hub</span>
                <span className="pn-pass-label">{kind === 'admin' ? 'Admin pass' : 'Crew pass'}</span>
              </span>
            </a>
          </div>
          <div className="user-menu-wrap" style={{ padding: '0 var(--sp-6) var(--sp-4)', borderBottom: '1px solid rgba(255,255,255,.08)', marginBottom: 'var(--sp-4)' }}>
            <button className="user-menu-btn" id="userMenuBtn" style={{ width: '100%', borderColor: 'rgba(255,255,255,.2)', color: '#fff' }}>
              <img src={avatarUrl(user.avatar, user.name)} alt="" className="user-avatar-sm" />
              <span className="user-name-sm" style={{ color: '#fff', flex: 1, textAlign: 'left', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{user.name}</span>
              <Icon name="expand_more" />
            </button>
            <div className="dropdown-menu sidebar-user-dropdown" id="userDropdown">
              <div className="dropdown-header"><strong>{user.name}</strong><small>{user.email} · {ROLE_NAMES[user.role_id]}</small></div>
              <a href="/dashboard" className="dropdown-item"><Icon name="person" /> Student view</a>
              <a href="/profile" className="dropdown-item"><Icon name="lock" /> Password &amp; profile</a>
              <div className="dropdown-divider"></div>
              <a href="/logout" className="dropdown-item text-danger"><Icon name="logout" /> Log out</a>
            </div>
          </div>
          {other && <a href={other[0]} className="pn-switch"><Icon name="swap_horiz" /> {other[1]}</a>}
          {nav.map((item, i) => item === null
            ? <div key={i} className="admin-nav-section" role="separator"></div>
            : <a key={item[0]} href={item[0]} className={`admin-nav-item ${isActive(item[0]) ? 'active' : ''}`} aria-current={isActive(item[0]) ? 'page' : undefined}><Icon name={item[1]} />{item[2]}</a>)}
          <div className="admin-nav-section" role="separator"></div>
          <div className="pn-side-foot">
            <a href="/" aria-label="Visit site"><Icon name="home" /> Site</a>
            <button type="button" data-lh-theme aria-label="Dark mode">
              <span className="material-icons-round lh-ico-dark">dark_mode</span><span className="material-icons-round lh-ico-light">light_mode</span>
            </button>
            <a href="/logout" className="pn-out"><Icon name="logout" /> Log out</a>
          </div>
        </aside>

        <div className="admin-content">{children}</div>
      </div>
      <LegacyScripts srcs={['/assets/js/main.js?v=2.0.0', '/assets/js/app.js?v=2.0.0', '/assets/js/lh.js?v=2.0.0']} globals={{ APP_URL: '', CSRF_TOKEN: '', IS_LOGGED_IN: true, VAPID_PUBLIC_KEY: '' }} />
    </>
  );
}

export function PageHead({ title, sub, eyebrow, children }: { title: string; sub?: React.ReactNode; eyebrow?: string; children?: React.ReactNode }) {
  return (
    <div className="pn-head">
      <div>{eyebrow && <p className="pn-eyebrow">{eyebrow}</p>}<h1>{title}</h1>{sub && <p>{sub}</p>}</div>
      {children && <div className="pn-actions">{children}</div>}
    </div>
  );
}

/** Dashboard banner in the home page's hero style (purple gradient, floating glyphs). */
export function PanelHero({ eyebrow, title, sub, route, children }: {
  eyebrow: string; title: string; sub?: React.ReactNode; route?: [string, string]; children?: React.ReactNode;
}) {
  return (
    <section className="pn-hero">
      <div className="pn-hero-sky" aria-hidden="true"><span>中</span><span>あ</span><span>한</span></div>
      <div>
        <p className="pn-eyebrow">{eyebrow}</p>
        <h1>{title}</h1>
        {sub && <p>{sub}</p>}
        {route && <div className="pn-hero-route" aria-hidden="true"><span>{route[0]}</span><i></i><Icon name="flight" /><i></i><span>{route[1]}</span></div>}
      </div>
      {children && <div className="pn-actions">{children}</div>}
    </section>
  );
}

/** A course as a ticket inside the panels (links to the panel's course page). */
export function PanelCourseTicket({ c, href, showPrice = false }: { c: Row; href: string; showPrice?: boolean }) {
  const L = lang(c.cat_slug);
  const max = Number(c.max_students) || 0, n = Number(c.students ?? c.student_count ?? 0);
  return (
    <a className="lh-ticket" href={href} style={langStyle(c.cat_slug)}>
      <div className="lh-ticket-art">
        <span className="lh-ticket-glyph" aria-hidden="true">{L.glyph}</span><span className="lh-ticket-hello" aria-hidden="true">{L.hello}</span>
        <span className={`lh-chip ${c.type === 'live' && c.status === 'published' ? 'lh-chip-live' : ''}`}>
          {c.type === 'live' && c.status === 'published' && <i></i>}
          {c.status !== 'published' ? (c.status === 'draft' ? 'Draft' : 'Archived') : c.type === 'live' ? 'Live' : c.type === 'hybrid' ? 'Hybrid' : 'Recorded'}
        </span>
      </div>
      <div className="lh-ticket-body">
        <p className="lh-ticket-meta">{c.cat_name_en}{c.teacher_name ? ` · ${c.teacher_name}` : ''}</p>
        <h3>{c.title_bn}</h3>
        <div className="pn-ticket-stats">
          <span><Icon name="play_lesson" />{c.lessons ?? c.lesson_count ?? 0} class{Number(c.lessons ?? c.lesson_count ?? 0) === 1 ? "" : "es"}</span>
          <span><Icon name="groups" />{n} student{n === 1 ? "" : "s"}</span>
          {showPrice && <span><Icon name="sell" />{c.is_free ? 'Free' : `৳${formatPrice(c.sale_price ?? c.price)}`}</span>}
        </div>
      </div>
      <div className="lh-ticket-stub">
        <div className="pn-seatbar">
          <small>{max ? `${Math.max(0, max - n)} of ${max} seats left` : 'No seat limit'}</small>
          {max > 0 && <i><b style={{ width: `${Math.min(100, (n / max) * 100)}%` }}></b></i>}
        </div>
        <Icon name="arrow_forward" className="lh-ticket-go" />
      </div>
    </a>
  );
}

export function Stat({ icon, color, value, label, sub }: { icon: string; color: string; value: React.ReactNode; label: string; sub?: React.ReactNode }) {
  return (
    <div className="stat-card">
      <div className={`stat-card-icon ${color}`}><Icon name={icon} /></div>
      <div>
        <div className="stat-card-value">{value}</div>
        <div className="stat-card-label">{label}</div>
        {sub && <div style={{ fontSize: 'var(--text-xs)', color: 'var(--c-success)', marginTop: 4 }}>{sub}</div>}
      </div>
    </div>
  );
}

export const Badge = ({ s }: { s: string }) => <span className={`status-badge status-${s}`}>{s}</span>;
export const Money = ({ v }: { v: number | string }) => <strong style={{ fontFamily: 'var(--font-latin)' }}>৳{formatPrice(v)}</strong>;
export const When = ({ v }: { v: string | Date | null }) => <span className="pn-muted">{fmtDate(v)}</span>;

/** A one-button POST form (approve, delete …) with an optional confirm prompt. */
export function ActionButton({ action, fields, label, cls = 'btn btn-outline btn-xs', confirm }: {
  action: string; fields: Record<string, string | number>; label: React.ReactNode; cls?: string; confirm?: string;
}) {
  return (
    <form method="post" action={action} className="pn-inline" data-confirm={confirm}>
      {Object.entries(fields).map(([k, v]) => <input key={k} type="hidden" name={k} value={v} />)}
      <button type="submit" className={cls}>{label}</button>
    </form>
  );
}

/** Adds a confirm() prompt to forms with data-confirm (runs after hydration). */
export function ConfirmScript() {
  return <script dangerouslySetInnerHTML={{ __html:
    `document.addEventListener('submit',function(e){var m=e.target.getAttribute&&e.target.getAttribute('data-confirm');if(m&&!confirm(m))e.preventDefault()},true);` }} />;
}
