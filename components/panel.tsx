// Admin / teacher panel chrome: sidebar (desktop), top bar (phone), account menu.
import { headers } from 'next/headers';
import { isAdmin, isTeacher, ROLE_NAMES, type User } from '@/lib/auth';
import { avatarUrl, formatPrice, fmtDate } from '@/lib/format';
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
      <div className="admin-layout">
        <header className="panel-topbar">
          <button className="icon-btn" id="panelNavToggle" aria-label="Menu" aria-controls="adminSidebar"><Icon name="menu" /></button>
          <a href={home} className="panel-topbar-brand"><img src="/image/language-hub-logo.png" alt="" /><span>{kind === 'admin' ? 'Admin' : 'Teacher'}</span></a>
          <a href="/" className="icon-btn" aria-label="Visit site"><Icon name="open_in_new" /></a>
        </header>

        <aside className="admin-sidebar" id="adminSidebar">
          <div className="admin-logo">
            <a href={home} style={{ display: 'flex', alignItems: 'center', gap: 10, textDecoration: 'none' }}>
              <img src="/image/language-hub-logo.png" alt="Logo" style={{ height: 32, width: 'auto' }} />
              <span style={{ fontFamily: 'var(--font-display)', fontWeight: 800, color: '#fff', fontSize: '1.2rem' }}>Language Hub</span>
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
            ? <div key={i} className="admin-nav-section" style={{ height: 16 }}></div>
            : <a key={item[0]} href={item[0]} className={`admin-nav-item ${isActive(item[0]) ? 'active' : ''}`}><Icon name={item[1]} />{item[2]}</a>)}
          <div style={{ padding: 'var(--sp-4) var(--sp-6)', marginTop: 32, borderTop: '1px solid rgba(255,255,255,.1)' }}>
            <a href="/" style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'rgba(255,255,255,.6)', fontSize: 'var(--text-sm)', textDecoration: 'none', marginBottom: 8 }}><Icon name="home" /> Visit site</a>
            <a href="/logout" style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--c-error)', fontSize: 'var(--text-sm)', textDecoration: 'none' }}><Icon name="logout" /> Log out</a>
          </div>
        </aside>

        <div className="admin-content">{children}</div>
      </div>
      <LegacyScripts srcs={['/assets/js/main.js?v=2.0.0', '/assets/js/app.js?v=2.0.0']} globals={{ APP_URL: '', CSRF_TOKEN: '', IS_LOGGED_IN: true, VAPID_PUBLIC_KEY: '' }} />
    </>
  );
}

export function PageHead({ title, sub, children }: { title: string; sub?: React.ReactNode; children?: React.ReactNode }) {
  return (
    <div className="pn-head">
      <div><h1>{title}</h1>{sub && <p>{sub}</p>}</div>
      {children && <div className="pn-actions">{children}</div>}
    </div>
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
