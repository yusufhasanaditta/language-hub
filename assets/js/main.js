/**
 * Language Hub — Main JavaScript
 * Handles: navbar, dropdowns, mobile nav, search, flash messages, AJAX utilities
 */
'use strict';

// ── DOM Ready ─────────────────────────────────────────────────────────────────
function _initAll() {
  initNavbar();
  initDropdowns();
  initMobileNav();
  initSearch();
  initFlash();
  initCounters();
  initTabs();
  initForms();
  initNotifications();
  initPushNotifications();
}

// Support both deferred and inline script loading
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', _initAll);
} else {
  _initAll();
}

// ── Navbar scroll effect ──────────────────────────────────────────────────────
function initNavbar() {
  const nav = document.getElementById('navbar');
  if (!nav) return;
  const onScroll = () => nav.classList.toggle('scrolled', window.scrollY > 10);
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
}

// ── Dropdowns ─────────────────────────────────────────────────────────────────
function initDropdowns() {
  const overlay = document.getElementById('overlay');

  function closeAllDropdowns() {
    document.querySelectorAll('.dropdown-menu.open').forEach(d => d.classList.remove('open'));
  }

  function toggleDropdown(btnId, menuId) {
    const btn  = document.getElementById(btnId);
    const menu = document.getElementById(menuId);
    if (!btn || !menu) return;

    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isOpen = menu.classList.contains('open');
      closeAllDropdowns();
      if (!isOpen) {
        if (menu.classList.contains('sidebar-user-dropdown')) {
          const r = btn.getBoundingClientRect();
          menu.style.top  = (r.bottom + 6) + 'px';
          menu.style.left = r.left + 'px';
          menu.style.width = r.width + 'px';
        }
        menu.classList.add('open');
      }
    });

    // Prevent clicks inside dropdown from closing it
    menu.addEventListener('click', (e) => e.stopPropagation());
  }

  toggleDropdown('notifBtn', 'notifDropdown');
  toggleDropdown('userMenuBtn', 'userDropdown');

  // Close dropdowns when clicking anywhere outside
  document.addEventListener('click', closeAllDropdowns);

  // Overlay only used for mobile nav — still close dropdowns if overlay clicked
  if (overlay) {
    overlay.addEventListener('click', () => {
      closeAllDropdowns();
      closeMobileNav();
      closeSearch();
      overlay.classList.remove('show');
    });
  }
}

// ── Notifications (bell dropdown) ───────────────────────────────────────────
function initNotifications() {
  if (typeof IS_LOGGED_IN === 'undefined' || !IS_LOGGED_IN) return;

  const badge   = document.getElementById('notifBadge');
  const list    = document.getElementById('notifList');
  const markAll = document.getElementById('notifMarkAllRead');
  if (!badge && !list) return;

  function setBadge(count) {
    if (!badge) return;
    if (count > 0) {
      badge.textContent = count > 9 ? '9+' : String(count);
      badge.style.display = '';
    } else {
      badge.style.display = 'none';
    }
  }

  // Mark a single notification read without blocking navigation to its link.
  if (list) {
    list.addEventListener('click', (e) => {
      const item = e.target.closest('.notif-item[data-notif-id]');
      if (!item || !item.classList.contains('unread')) return;
      item.classList.remove('unread');
      const payload = JSON.stringify({ action: 'mark_read', id: item.dataset.notifId, csrf_token: CSRF_TOKEN });
      navigator.sendBeacon(APP_URL + '/api/notifications.php', new Blob([payload], { type: 'application/json' }));
    });
  }

  // Mark every notification read in one go.
  if (markAll) {
    markAll.addEventListener('click', () => {
      fetch(APP_URL + '/api/notifications.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        body: JSON.stringify({ action: 'mark_all_read' }),
      }).then(() => {
        document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
        setBadge(0);
      }).catch(() => {});
    });
  }

  // Poll for new notifications so the badge updates without a page reload.
  setInterval(() => {
    fetch(APP_URL + '/api/notifications.php?action=unread_count')
      .then(r => r.json())
      .then(res => { if (res.success) setBadge(res.data.unread); })
      .catch(() => {});
  }, 45000);
}

// ── Push notifications (native OS/phone popup, works even tab/browser closed) ──
// Asks once per browser via a small dismissible prompt (never auto-prompts —
// browsers quietly block sites that ask for Notification permission without
// a user gesture). Skips entirely on unsupported browsers/insecure origins.
function initPushNotifications() {
  if (typeof IS_LOGGED_IN === 'undefined' || !IS_LOGGED_IN) return;
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || typeof Notification === 'undefined') return;
  if (typeof VAPID_PUBLIC_KEY === 'undefined' || !VAPID_PUBLIC_KEY) return;

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    const out = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; i++) out[i] = rawData.charCodeAt(i);
    return out;
  }

  function keyToB64Url(buf) {
    if (!buf) return '';
    const bytes = new Uint8Array(buf);
    let str = '';
    for (let i = 0; i < bytes.length; i++) str += String.fromCharCode(bytes[i]);
    return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function sendSub(sub, action) {
    const body = new URLSearchParams();
    body.append('action', action);
    body.append('csrf_token', CSRF_TOKEN);
    body.append('endpoint', sub.endpoint);
    body.append('p256dh', keyToB64Url(sub.getKey('p256dh')));
    body.append('auth', keyToB64Url(sub.getKey('auth')));
    fetch(APP_URL + '/api/push_subscribe.php', { method: 'POST', body, credentials: 'same-origin' }).catch(() => {});
  }

  function subscribe(reg) {
    reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
    }).then(sub => sendSub(sub, 'subscribe')).catch(() => {});
  }

  function showPrompt(reg) {
    if (localStorage.getItem('lh_push_prompt_dismissed')) return;

    const bar = document.createElement('div');
    bar.style.cssText = 'position:fixed;bottom:16px;left:16px;right:16px;max-width:380px;margin:0 auto;' +
      'background:var(--c-bg,#fff);color:var(--c-ink,#111);border:1px solid var(--c-border,#e5e5e5);' +
      'border-radius:14px;box-shadow:0 12px 32px rgba(0,0,0,.18);padding:16px;z-index:2000;font-family:inherit';
    bar.innerHTML =
      '<div style="font-weight:700;margin-bottom:4px">Turn on notifications?</div>' +
      '<div style="font-size:13px;opacity:.7;margin-bottom:12px">Get a popup for payments, results, and live classes — even when this tab is closed.</div>' +
      '<div style="display:flex;gap:8px">' +
      '<button type="button" id="baPushAllow" style="flex:1;padding:9px;border:none;border-radius:8px;background:var(--c-primary,#5B21B6);color:#fff;font-weight:700;cursor:pointer">Enable</button>' +
      '<button type="button" id="baPushDismiss" style="flex:1;padding:9px;border:1px solid var(--c-border,#ddd);border-radius:8px;background:transparent;color:inherit;cursor:pointer">Not now</button>' +
      '</div>';
    document.body.appendChild(bar);

    document.getElementById('baPushAllow').addEventListener('click', () => {
      bar.remove();
      Notification.requestPermission().then(perm => { if (perm === 'granted') subscribe(reg); });
    });
    document.getElementById('baPushDismiss').addEventListener('click', () => {
      bar.remove();
      localStorage.setItem('lh_push_prompt_dismissed', '1');
    });
  }

  navigator.serviceWorker.register(APP_URL + '/sw.js').then(reg => {
    reg.pushManager.getSubscription().then(existing => {
      if (existing) { sendSub(existing, 'subscribe'); return; } // keep DB row fresh (e.g. after a key rotation)
      if (Notification.permission === 'denied') return;
      if (Notification.permission === 'granted') { subscribe(reg); return; }
      showPrompt(reg);
    });
  }).catch(() => {});
}

// ── Mobile nav ────────────────────────────────────────────────────────────────
function initMobileNav() {
  const toggleBtn = document.getElementById('mobileMenuToggle');
  const mobileNav = document.getElementById('mobileNav');
  const overlay   = document.getElementById('overlay');

  if (toggleBtn) {
    toggleBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = mobileNav ? mobileNav.classList.toggle('open') : false;
      if (overlay) overlay.classList.toggle('show', !!open);
    });
  }
}

function closeMobileNav() {
  const nav = document.getElementById('mobileNav');
  if (nav) nav.classList.remove('open');
}

// ── Search ────────────────────────────────────────────────────────────────────
function initSearch() {
  const toggleBtn = document.getElementById('searchToggle');
  const wrap      = document.getElementById('searchBarWrap');
  const input     = document.getElementById('searchInput');
  const closeBtn  = document.getElementById('searchClose');

  if (toggleBtn) {
    toggleBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = wrap ? wrap.classList.toggle('open') : false;
      if (open && input) setTimeout(() => input.focus(), 200);
    });
  }
  if (closeBtn) closeBtn.addEventListener('click', closeSearch);
}

function closeSearch() {
  const wrap = document.getElementById('searchBarWrap');
  if (wrap) wrap.classList.remove('open');
}

// ── Flash auto-dismiss ────────────────────────────────────────────────────────
function initFlash() {
  const container = document.getElementById('flashContainer');
  if (!container) return;
  setTimeout(() => {
    container.querySelectorAll('.alert').forEach((a, i) => {
      setTimeout(() => a.style.opacity === '' && a.remove(), i * 200);
    });
  }, 4000);
}

// ── Animated counters ─────────────────────────────────────────────────────────
function initCounters() {
  const nums = document.querySelectorAll('[data-count]');
  if (!nums.length) return;
  const obs = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      const el  = entry.target;
      const end = parseInt(el.dataset.count, 10);
      const dur = 1600;
      const step = Math.ceil(end / (dur / 16));
      let cur = 0;
      const tick = setInterval(() => {
        cur = Math.min(cur + step, end);
        el.textContent = formatNum(cur);
        if (cur >= end) clearInterval(tick);
      }, 16);
      obs.unobserve(el);
    });
  }, { threshold: 0.5 });
  nums.forEach(el => obs.observe(el));
}

function formatNum(n) {
  if (n >= 1000) return (n / 1000).toFixed(1).replace('.0', '') + 'k+';
  return n.toString();
}

// ── Tabs ──────────────────────────────────────────────────────────────────────
function initTabs() {
  document.querySelectorAll('.tab-btn[data-tab]').forEach(btn => {
    btn.addEventListener('click', () => {
      const nav = btn.closest('[data-tabs]') || btn.parentElement;
      const root = nav.parentElement || document;
      const target = btn.dataset.tab;
      nav.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b === btn));
      root.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === target));
    });
  });
}

// ── Forms: password toggle, OTP, etc. ────────────────────────────────────────
function initForms() {
  // Password toggle
  document.querySelectorAll('[data-toggle-password]').forEach(btn => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.togglePassword);
      if (!input) return;
      const isText = input.type === 'text';
      input.type = isText ? 'password' : 'text';
      btn.querySelector('.material-icons-round').textContent = isText ? 'visibility_off' : 'visibility';
    });
  });

  // OTP auto-advance
  document.querySelectorAll('.otp-input').forEach((inp, i, all) => {
    inp.addEventListener('input', () => {
      inp.classList.toggle('filled', inp.value.length > 0);
      if (inp.value.length > 0 && i < all.length - 1) all[i + 1].focus();
    });
    inp.addEventListener('keydown', (e) => {
      if (e.key === 'Backspace' && !inp.value && i > 0) all[i - 1].focus();
    });
    inp.addEventListener('paste', (e) => {
      const data = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, all.length);
      e.preventDefault();
      [...data].forEach((char, j) => { if (all[j]) { all[j].value = char; all[j].classList.add('filled'); } });
      const target = all[Math.min(data.length, all.length - 1)];
      if (target) target.focus();
    });
  });

  // Form submit loading state
  document.querySelectorAll('form[data-loading]').forEach(form => {
    form.addEventListener('submit', () => {
      const btn = form.querySelector('[type="submit"]');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="material-icons-round spin" style="animation:spin .8s linear infinite">autorenew</span> ' + (btn.dataset.loading || 'Please wait...');
      }
    });
  });
}

// ── AJAX helper ──────────────────────────────────────────────────────────────
async function api(endpoint, data = {}, method = 'POST') {
  const res = await fetch(APP_URL + endpoint, {
    method,
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': CSRF_TOKEN,
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: method !== 'GET' ? JSON.stringify(data) : undefined,
  });
  return res.json();
}

// ── Toast notifications ───────────────────────────────────────────────────────
function toast(message, type = 'info', duration = 4000) {
  let container = document.getElementById('flashContainer');
  if (!container) {
    container = document.createElement('div');
    container.id = 'flashContainer';
    container.className = 'flash-container';
    document.body.appendChild(container);
  }
  const icons = { success: 'check_circle', error: 'error', warning: 'warning', info: 'info' };
  const el = document.createElement('div');
  el.className = `alert alert-${type}`;
  el.innerHTML = `<span class="material-icons-round">${icons[type] || 'info'}</span><span>${message}</span><button class="alert-close" onclick="this.parentElement.remove()">✕</button>`;
  container.appendChild(el);
  setTimeout(() => el.remove(), duration);
}

// ── Enroll sticky bar visibility ─────────────────────────────────────────────
(function () {
  const sticky = document.getElementById('enrollSticky');
  const target = document.getElementById('courseEnrollBox');
  if (!sticky || !target) return;
  const obs = new IntersectionObserver(([e]) => {
    sticky.style.display = e.isIntersecting ? 'none' : 'flex';
  }, { threshold: 0 });
  obs.observe(target);
})();

// ── Progress save (player) ────────────────────────────────────────────────────
function saveProgress(lessonId, position) {
  if (!IS_LOGGED_IN) return;
  navigator.sendBeacon(APP_URL + '/api/progress.php', JSON.stringify({ lesson_id: lessonId, position, csrf_token: CSRF_TOKEN }));
}

// ── Spin keyframe ─────────────────────────────────────────────────────────────
const _style = document.createElement('style');
_style.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
document.head.appendChild(_style);

// ── Scroll-reveal (IntersectionObserver) ──────────────────────────────────────
(function () {
  const obs = new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); } });
  }, { threshold: 0.10, rootMargin: '0px 0px -40px 0px' });
  document.querySelectorAll('.reveal').forEach(el => obs.observe(el));
})();

// ── Category dropdown (desktop navbar) ────────────────────────────────────────
(function () {
  const wrap = document.querySelector('.nav-cat-dropdown');
  if (!wrap) return;
  const drop = wrap.querySelector('.nav-categories-drop');
  if (!drop) return;
  wrap.addEventListener('mouseenter', () => { drop.style.display = 'block'; });
  wrap.addEventListener('mouseleave', () => { drop.style.display = 'none'; });
})();

// ── Glass nav color-shift on dark heroes ──────────────────────────────────────
(function () {
  const nav = document.getElementById('navbar');
  if (!nav) return;
  const hero = document.querySelector('[data-dark-hero]');
  if (!hero) return;
  const obs = new IntersectionObserver(([e]) => {
    nav.classList.toggle('on-dark', e.isIntersecting);
  }, { threshold: 0.1 });
  obs.observe(hero);
})();

// ── Animated floating orbs (homepage) ─────────────────────────────────────────
(function () {
  document.querySelectorAll('.blob-orb').forEach((orb, i) => {
    orb.style.animationDelay = `${i * 2.5}s`;
    orb.style.animationDuration = `${7 + i * 1.5}s`;
  });
})();

// ── Global exports ────────────────────────────────────────────────────────────
window.BAcademy = { api, toast, saveProgress };
