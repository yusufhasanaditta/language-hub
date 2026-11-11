// Public site chrome: top navigation, footer, mobile tab bar and the page scripts.
import { headers } from 'next/headers';
import { q, val, type Row } from '@/lib/db';
import { currentUser, isAdmin, isTeacher, type User } from '@/lib/auth';
import { getSettings } from '@/lib/settings';
import { T } from '@/lib/i18n';
import { lang } from '@/lib/langs';
import { timeAgo, avatarUrl } from '@/lib/format';
import { Icon, Flash } from './ui';
import LegacyScripts from './LegacyScripts';

function Glyph({ cat }: { cat: Row }) {
  return <span className="lh-glyph">{lang(cat.slug).glyph}</span>;
}

function AccountLinks({ user, cls }: { user: User; cls: string }) {
  const links: [string, string, string, string][] = [
    ['/dashboard', 'person', 'আমার প্রোফাইল', 'My profile'],
    ['/my-courses', 'play_circle', 'আমার কোর্স', 'My courses'],
    ['/orders', 'receipt_long', 'পেমেন্ট ও ইনভয়েস', 'Payments & invoices'],
    ['/notifications', 'notifications', 'নোটিফিকেশন', 'Notifications'],
    ['/profile', 'manage_accounts', 'প্রোফাইল এডিট', 'Edit profile'],
  ];
  return (
    <>
      {links.map(([href, icon, bn, en]) => (
        <a key={href} href={href} className={cls}><Icon name={icon} /> <T bn={bn} en={en} /></a>
      ))}
      {isAdmin(user) && <a href="/admin" className={cls}><Icon name="admin_panel_settings" /> Admin Panel</a>}
      {isTeacher(user) && <a href="/teacher" className={cls}><Icon name="cast_for_education" /> Teacher Panel</a>}
      <a href="/contact" className={cls}><Icon name="help" /> <T bn="সাহায্য" en="Help" /></a>
      <a href="/logout" className={`${cls} text-danger`}><Icon name="logout" /> <T bn="লগআউট" en="Log out" /></a>
    </>
  );
}

export async function SiteHeader() {
  const user = await currentUser();
  const cats = await q`SELECT * FROM categories ORDER BY sort_order, id`;
  const notifs = user ? await q`SELECT * FROM notifications WHERE user_id = ${user.id} ORDER BY created_at DESC LIMIT 8` : [];
  const unread = user ? Number(await val`SELECT count(*) FROM notifications WHERE user_id = ${user.id} AND read_at IS NULL`) : 0;
  const first = user ? user.name.split(' ')[0] : '';

  return (
    <>
      <Flash />
      <nav className="navbar" id="navbar">
        <div className="container navbar-inner">
          <a href="/" className="navbar-brand lh-brand">
            <img src="/image/language-hub-logo.png" alt="" width={40} height={40} />
            <span className="brand-text">
              <span className="brand-name">Language Hub</span>
              <span className="brand-tagline">নতুন ভাষা, নতুন সুযোগ</span>
            </span>
          </a>

          <div className="navbar-categories lh-nav">
            <a href="/courses" className="nav-cat"><T bn="কোর্স" en="Courses" /></a>
            <div className="nav-cat-dropdown">
              <button className="nav-cat" type="button"><T bn="ভাষা" en="Languages" /> <span className="material-icons-round" style={{ fontSize: 16 }}>expand_more</span></button>
              <div className="dropdown-menu nav-categories-drop lh-lang-drop" style={{ display: 'none' }}>
                {cats.map(cat => (
                  <a key={cat.id} href={`/category/${cat.slug}`} className="dropdown-item" style={{ '--lang': cat.color } as React.CSSProperties}>
                    <Glyph cat={cat} /> <T bn={cat.name_bn} en={cat.name_en} />
                  </a>
                ))}
              </div>
            </div>
            <a href="/courses?type=live" className="nav-cat"><T bn="লাইভ ব্যাচ" en="Live batches" /></a>
            <a href="/courses?free=1" className="nav-cat"><T bn="ফ্রি" en="Free" /></a>
            <a href="/teachers" className="nav-cat"><T bn="শিক্ষক" en="Teachers" /></a>
          </div>

          <div className="navbar-right">
            <button className="icon-btn lh-toggle" type="button" data-lh-lang aria-label="বাংলা / English">
              <span className="lh-toggle-txt"><span className="i18n" lang="bn">EN</span><span className="i18n" lang="en">বাং</span></span>
            </button>
            <button className="icon-btn lh-toggle" type="button" data-lh-theme aria-label="Dark mode">
              <span className="material-icons-round lh-ico-dark">dark_mode</span>
              <span className="material-icons-round lh-ico-light">light_mode</span>
            </button>
            <button className="icon-btn d-none d-md-flex" id="searchToggle" aria-label="Search"><Icon name="search" /></button>

            {user ? (
              <>
                <div className="notif-wrap">
                  <button className="icon-btn" id="notifBtn" aria-label="Notifications">
                    <Icon name="notifications" />
                    <span className="badge badge-notif" id="notifBadge" style={unread ? undefined : { display: 'none' }}>{unread > 9 ? '9+' : unread}</span>
                  </button>
                  <div className="dropdown-menu notif-dropdown" id="notifDropdown">
                    <div className="dropdown-header" style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
                      <span>Notifications</span>
                      <button type="button" id="notifMarkAllRead" style={{ background: 'none', border: 'none', color: 'var(--c-primary)', fontSize: 'var(--text-xs)', fontWeight: 700, cursor: 'pointer', padding: 0 }}>Mark all read</button>
                    </div>
                    <div id="notifList">
                      {notifs.length === 0 ? <div className="dropdown-empty">No notifications</div> : notifs.map(n => (
                        <a key={n.id} href={n.action_url || '/notifications'} className={`notif-item ${n.read_at ? '' : 'unread'}`} data-notif-id={n.id}>
                          <span className="notif-title">{n.title}</span>
                          <span className="notif-time">{timeAgo(n.created_at)}</span>
                        </a>
                      ))}
                    </div>
                    <div style={{ borderTop: '1px solid var(--c-border)', padding: 'var(--sp-2)' }}>
                      <a href="/notifications" className="dropdown-item" style={{ justifyContent: 'center', fontWeight: 700, color: 'var(--c-primary)', fontSize: 'var(--text-sm)' }}>
                        <span className="material-icons-round" style={{ fontSize: 16 }}>notifications</span> View all
                      </a>
                    </div>
                  </div>
                </div>
                <div className="user-menu-wrap">
                  <button className="user-menu-btn" id="userMenuBtn">
                    <img src={avatarUrl(user.avatar, user.name)} alt="" className="user-avatar-sm" />
                    <span className="user-name-sm d-none d-md-inline">{first}</span>
                    <Icon name="expand_more" />
                  </button>
                  <div className="dropdown-menu user-dropdown" id="userDropdown" style={{ maxHeight: '80vh', overflowY: 'auto' }}>
                    <div className="dropdown-header"><strong>{user.name}</strong><small>{user.email ?? user.phone}</small></div>
                    <AccountLinks user={user} cls="dropdown-item" />
                  </div>
                </div>
              </>
            ) : (
              <a href="/login" className="btn btn-primary btn-sm lh-nav-cta"><T bn="শুরু করুন" en="Get started" /></a>
            )}
          </div>
        </div>

        <div className="search-bar-wrap" id="searchBarWrap">
          <div className="container">
            <form action="/courses" method="GET" className="search-bar-form">
              <span className="material-icons-round search-icon">search</span>
              <input type="search" name="q" placeholder="Search courses…" className="search-input" id="searchInput" autoComplete="off" />
              <button type="button" className="icon-btn" id="searchClose"><Icon name="close" /></button>
            </form>
          </div>
        </div>

        <div className="mobile-nav" id="mobileNav" aria-hidden="true">
          <div className="drawer-head">
            <span className="drawer-title">{user ? first : 'Menu'}</span>
            <button className="icon-btn drawer-close" aria-label="Close menu"><Icon name="close" /></button>
          </div>
          <div className="mobile-nav-inner">
            <a href="/" className="mobile-nav-link"><Icon name="home" /> <T bn="হোম" en="Home" /></a>
            <a href="/courses" className="mobile-nav-link"><Icon name="school" /> <T bn="সব কোর্স" en="All courses" /></a>
            <div className="mobile-nav-divider"></div>
            {cats.map(cat => (
              <a key={cat.id} href={`/category/${cat.slug}`} className="mobile-nav-link" style={{ '--lang': cat.color } as React.CSSProperties}>
                <Glyph cat={cat} /> <T bn={cat.name_bn} en={cat.name_en} />
              </a>
            ))}
            <div className="mobile-nav-divider"></div>
            {user ? <AccountLinks user={user} cls="mobile-nav-link" />
              : <a href="/login" className="mobile-nav-link"><Icon name="login" /> <T bn="লগইন" en="Log in" /></a>}
          </div>
        </div>
      </nav>
      <div className="overlay" id="overlay"></div>
    </>
  );
}

export async function SiteFooter() {
  const user = await currentUser();
  const s = await getSettings();
  const cats = await q`SELECT slug, name_bn, name_en FROM categories ORDER BY sort_order, id`;
  const url = (await headers()).get('x-url') ?? '/';
  const path = url.split('?')[0];
  const active = url.startsWith('/courses?type=live') ? 'live' : url.startsWith('/courses?free=1') ? 'free'
    : path === '/' ? 'home' : path.startsWith('/courses') ? 'courses' : path.startsWith('/dashboard') || path === '/login' ? 'me' : '';
  const tabs: [string, string, string, string, string][] = [
    ['home', '/', 'home', 'হোম', 'Home'],
    ['courses', '/courses', 'explore', 'কোর্স', 'Courses'],
    ['live', '/courses?type=live', 'videocam', 'লাইভ', 'Live'],
    ['free', '/courses?free=1', 'redeem', 'ফ্রি', 'Free'],
    user ? ['me', '/dashboard', 'person', 'আমি', 'Me'] : ['me', '/login', 'login', 'লগইন', 'Log in'],
  ];
  const socials = [['facebook_url', 'Facebook'], ['youtube_url', 'YouTube'], ['whatsapp_url', 'WhatsApp']].filter(([k]) => s[k]);

  return (
    <>
      <footer className="footer lh-footer">
        <div className="container">
          <div className="lh-footer-cta">
            <div>
              <p className="lh-eyebrow"><T bn="আজই শুরু করুন" en="Start today" /></p>
              <h2><T bn="প্রথম ক্লাস ফ্রি — নতুন ভাষা, নতুন সুযোগ।" en="First class is free — new language, new chances." /></h2>
            </div>
            <a href="/courses?free=1" className="btn btn-accent btn-lg"><T bn="ফ্রি ক্লাস দেখুন" en="Watch a free class" /> <Icon name="arrow_forward" /></a>
          </div>
          <div className="footer-grid">
            <div className="footer-col footer-brand-col">
              <a href="/" className="footer-logo lh-brand">
                <img src="/image/language-hub-logo.png" alt="" width={36} height={36} />
                <span className="brand-name">Language Hub</span>
              </a>
              <p className="footer-desc"><T bn="বাংলায় বিদেশি ভাষা শেখার অনলাইন স্কুল — চাইনিজ, জাপানিজ, কোরিয়ান ও ইংলিশ।" en="An online school for learning foreign languages in Bangla — Chinese, Japanese, Korean and English." /></p>
              <div className="footer-socials">
                {socials.map(([k, label]) => (
                  <a key={k} href={s[k]} className="social-link" target="_blank" rel="noopener" aria-label={label}>{label[0]}</a>
                ))}
              </div>
            </div>
            <div className="footer-col">
              <h4 className="footer-heading"><T bn="ভাষা" en="Languages" /></h4>
              <ul className="footer-links">
                {cats.map(c => <li key={c.slug}><a href={`/category/${c.slug}`}><T bn={c.name_bn} en={c.name_en} /></a></li>)}
                <li><a href="/courses?free=1"><T bn="ফ্রি কোর্স" en="Free courses" /></a></li>
              </ul>
            </div>
            <div className="footer-col">
              <h4 className="footer-heading"><T bn="প্রতিষ্ঠান" en="Company" /></h4>
              <ul className="footer-links">
                <li><a href="/about"><T bn="আমাদের সম্পর্কে" en="About us" /></a></li>
                <li><a href="/teachers"><T bn="শিক্ষকবৃন্দ" en="Teachers" /></a></li>
                <li><a href="/contact"><T bn="যোগাযোগ" en="Contact" /></a></li>
              </ul>
            </div>
            <div className="footer-col">
              <h4 className="footer-heading"><T bn="সহায়তা" en="Help" /></h4>
              <ul className="footer-links">
                <li><a href="/faq"><T bn="প্রশ্নোত্তর" en="FAQ" /></a></li>
                <li><a href="/refund"><T bn="রিফান্ড নীতি" en="Refund policy" /></a></li>
                <li><a href="/privacy"><T bn="গোপনীয়তা নীতি" en="Privacy policy" /></a></li>
                <li><a href="/terms"><T bn="শর্তাবলি" en="Terms" /></a></li>
              </ul>
              {s.contact_phone && <p className="footer-contact"><Icon name="call" /> <a href={`tel:${s.contact_phone.replace(/[^0-9+]/g, '')}`}>{s.contact_phone}</a></p>}
              {s.contact_email && <p className="footer-contact"><Icon name="mail" /> <a href={`mailto:${s.contact_email}`}>{s.contact_email}</a></p>}
            </div>
          </div>
          <div className="footer-bottom">
            <p>© {new Date().getFullYear()} Language Hub</p>
            <p className="footer-made"><T bn="বাংলাদেশে তৈরি" en="Made in Bangladesh" /></p>
          </div>
        </div>
      </footer>

      <nav className="bottom-nav" aria-label="Primary">
        {tabs.map(([key, href, icon, bn, en]) => (
          <a key={key} href={href} className={`bottom-nav-item ${active === key ? 'active' : ''}`}>
            <Icon name={icon} /><span><T bn={bn} en={en} /></span>
          </a>
        ))}
      </nav>

      <div className="video-modal" id="videoModal" hidden>
        <div className="video-modal-inner">
          <button type="button" className="video-modal-close" aria-label="Close video"><Icon name="close" /></button>
          <div className="video-modal-frame">
            <iframe id="videoModalFrame" title="Video" allowFullScreen allow="autoplay; encrypted-media; picture-in-picture"></iframe>
          </div>
        </div>
      </div>
      <PageScripts loggedIn={!!user} />
    </>
  );
}

/** The original site scripts (menus, filters, carousel, countdowns, theme/lang toggles). */
export function PageScripts({ loggedIn, only }: { loggedIn: boolean; only?: 'lh' }) {
  const srcs = only === 'lh'
    ? ['/assets/js/lh.js?v=2.0.0']
    : ['/assets/js/main.js?v=2.0.0', '/assets/js/app.js?v=2.0.0', '/assets/js/lh.js?v=2.0.0'];
  return <LegacyScripts srcs={srcs} globals={{ APP_URL: '', CSRF_TOKEN: '', IS_LOGGED_IN: loggedIn, VAPID_PUBLIC_KEY: '' }} />;
}
