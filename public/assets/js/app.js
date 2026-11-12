/* =============================================================================
   Language Hub — app layer JS
   Drawer, teacher marquee, FAQ accordions, notice feed (reactions + comments).
   Loads after main.js. No dependencies.
   ============================================================================= */
(function () {
  'use strict';

  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ── Mobile drawer ─────────────────────────────────────────────────────── */
  function initDrawer() {
    var nav = $('#mobileNav');
    var toggle = $('#siteMenuBtn');
    var overlay = $('#overlay');
    if (!nav) return;

    function open() {
      nav.classList.add('open');
      if (overlay) overlay.classList.add('show');
      document.body.classList.add('no-scroll');
      nav.setAttribute('aria-hidden', 'false');
    }
    function close() {
      nav.classList.remove('open');
      if (overlay) overlay.classList.remove('show');
      document.body.classList.remove('no-scroll');
      nav.setAttribute('aria-hidden', 'true');
    }

    if (toggle) {
      toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        nav.classList.contains('open') ? close() : open();
      });
    }
    if (overlay) overlay.addEventListener('click', close);
    document.addEventListener('click', function (e) {
      if (nav.classList.contains('open') && !nav.contains(e.target) && e.target !== toggle) close();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    $$('.drawer-close', nav).forEach(function (b) { b.addEventListener('click', close); });
    // Closing on resize to desktop prevents a stuck no-scroll body
    window.addEventListener('resize', function () { if (window.innerWidth >= 960) close(); });
  }

  /* ── Admin/teacher panel sidebar drawer ────────────────────────────────── */
  function initPanelNav() {
    var toggle = $('#panelNavToggle');
    var side = $('#adminSidebar') || $('#teacherSidebar') || $('.admin-sidebar');
    if (!toggle || !side) return;

    var scrim = document.createElement('div');
    scrim.className = 'overlay';
    scrim.style.zIndex = '1550';
    document.body.appendChild(scrim);

    function close() {
      side.classList.remove('open');
      scrim.classList.remove('show');
      document.body.classList.remove('no-scroll');
    }
    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = side.classList.toggle('open');
      scrim.classList.toggle('show', open);
      document.body.classList.toggle('no-scroll', open);
    });
    scrim.addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    window.addEventListener('resize', function () { if (window.innerWidth >= 1024) close(); });
  }

  /* ── Teacher marquee: duplicate track, drag to scrub ────────────────────── */
  function initMarquee() {
    $$('.marquee').forEach(function (m) {
      var track = $('.marquee-track', m);
      if (!track || track.dataset.cloned === '1') return;

      var cards = $$('.marquee-card', track);
      if (!cards.length) return;

      // Too few cards to fill the viewport twice → pad until it does, so the
      // -50% keyframe always lands on an identical frame (seamless loop).
      var guard = 0;
      while (track.scrollWidth < m.offsetWidth * 2 && guard < 40) {
        cards.forEach(function (c) { track.appendChild(c.cloneNode(true)); });
        guard++;
      }
      // Duplicate the whole set once more: the animation translates exactly 50%
      var half = track.innerHTML;
      track.innerHTML = half + half;
      track.dataset.cloned = '1';

      // Speed scales with content so long lists don't crawl
      var perCard = 3.4; // seconds per card
      var total = $$('.marquee-card', track).length / 2;
      m.style.setProperty('--speed', Math.max(18, Math.round(total * perCard)) + 's');

      // Drag / swipe to scrub
      var down = false, startX = 0, scrollStart = 0;
      m.addEventListener('pointerdown', function (e) {
        down = true; startX = e.clientX; scrollStart = m.scrollLeft;
        m.classList.add('is-dragging');
      });
      m.addEventListener('pointermove', function (e) {
        if (!down) return;
        m.scrollLeft = scrollStart - (e.clientX - startX);
      });
      ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (ev) {
        m.addEventListener(ev, function () { down = false; m.classList.remove('is-dragging'); });
      });
    });
  }

  /* ── FAQ accordions (course sections) ──────────────────────────────────── */
  function initFaq() {
    document.addEventListener('click', function (e) {
      var q = e.target.closest('.cs-faq-q');
      if (!q) return;
      var item = q.closest('.cs-faq-item');
      var wasOpen = item.classList.contains('open');
      var group = item.parentElement;
      $$('.cs-faq-item.open', group).forEach(function (i) { i.classList.remove('open'); });
      if (!wasOpen) item.classList.add('open');
      q.setAttribute('aria-expanded', String(!wasOpen));
    });
  }

  /* ── Notice feed ───────────────────────────────────────────────────────── */
  var REACTIONS = {
    like:  { emoji: '👍', label: 'Like' },
    love:  { emoji: '❤️', label: 'Love' },
    care:  { emoji: '🤗', label: 'Care' },
    wow:   { emoji: '😮', label: 'Wow' },
    sad:   { emoji: '😢', label: 'Sad' },
    angry: { emoji: '😡', label: 'Angry' }
  };

  function api(action, payload) {
    var body = new FormData();
    body.append('action', action);
    body.append('csrf_token', CSRF_TOKEN || '');
    Object.keys(payload || {}).forEach(function (k) { body.append(k, payload[k]); });
    return fetch(APP_URL + '/api/notice.php', {
      method: 'POST', body: body, credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }

  function requireLogin() {
    if (IS_LOGGED_IN) return true;
    window.location.href = APP_URL + '/login.php';
    return false;
  }

  function initFeed() {
    var feed = $('#noticeFeed');
    if (!feed) return;

    /* Reaction picker: hover on desktop, long-press/tap on touch */
    feed.addEventListener('click', function (e) {
      var pickerBtn = e.target.closest('[data-react-toggle]');
      if (pickerBtn) {
        e.preventDefault();
        if (!requireLogin()) return;
        var wrap = pickerBtn.closest('.react-wrap');
        var picker = $('.react-picker', wrap);
        var isOpen = picker.classList.contains('open');
        $$('.react-picker.open', feed).forEach(function (p) { p.classList.remove('open'); });
        if (isOpen) {
          // second tap on an open picker = quick "like" toggle
          sendReaction(pickerBtn.closest('.notice-card'), pickerBtn.dataset.current === 'like' ? '' : 'like');
        } else {
          picker.classList.add('open');
        }
        return;
      }

      var opt = e.target.closest('.react-opt');
      if (opt) {
        e.preventDefault();
        if (!requireLogin()) return;
        var card = opt.closest('.notice-card');
        var current = $('[data-react-toggle]', card).dataset.current || '';
        $$('.react-picker.open', feed).forEach(function (p) { p.classList.remove('open'); });
        sendReaction(card, current === opt.dataset.reaction ? '' : opt.dataset.reaction);
        return;
      }

      var cmtBtn = e.target.closest('[data-comment-toggle]');
      if (cmtBtn) {
        var box = $('.notice-comments', cmtBtn.closest('.notice-card'));
        if (box) {
          box.hidden = !box.hidden;
          if (!box.hidden) { var ta = $('.comment-input', box); if (ta) ta.focus(); }
        }
        return;
      }

      var del = e.target.closest('[data-delete-comment]');
      if (del) {
        if (!window.confirm('Delete this comment?')) return;
        var row = del.closest('.comment');
        api('delete_comment', { comment_id: del.dataset.deleteComment }).then(function (res) {
          if (res.success) { row.remove(); } else { alert(res.message || 'Could not delete.'); }
        });
        return;
      }

      if (!e.target.closest('.react-wrap')) {
        $$('.react-picker.open', feed).forEach(function (p) { p.classList.remove('open'); });
      }
    });

    function sendReaction(card, reaction) {
      var btn = $('[data-react-toggle]', card);
      var noticeId = card.dataset.noticeId;
      btn.disabled = true;
      api('react', { notice_id: noticeId, reaction: reaction }).then(function (res) {
        btn.disabled = false;
        if (!res.success) { alert(res.message || 'Could not save reaction.'); return; }
        btn.dataset.current = res.my_reaction || '';
        btn.classList.toggle('reacted', !!res.my_reaction);
        var meta = REACTIONS[res.my_reaction];
        $('.react-label', btn).textContent = meta ? meta.label : 'React';
        $('.react-icon', btn).textContent = meta ? meta.emoji : '👍';
        renderReactionSummary(card, res.counts, res.total);
      }).catch(function () { btn.disabled = false; });
    }

    function renderReactionSummary(card, counts, total) {
      var wrap = $('.reaction-bubbles', card);
      var label = $('.reaction-total', card);
      if (!wrap) return;
      var top = Object.keys(counts || {})
        .filter(function (k) { return counts[k] > 0; })
        .sort(function (a, b) { return counts[b] - counts[a]; })
        .slice(0, 3);
      wrap.innerHTML = top.map(function (k) {
        return '<span title="' + REACTIONS[k].label + '">' + REACTIONS[k].emoji + '</span>';
      }).join('');
      if (label) label.textContent = total > 0 ? total : '';
    }

    /* Comment submit */
    feed.addEventListener('submit', function (e) {
      var form = e.target.closest('.comment-form');
      if (!form) return;
      e.preventDefault();
      if (!requireLogin()) return;

      var card = form.closest('.notice-card');
      var input = $('.comment-input', form);
      var text = input.value.trim();
      if (!text) return;

      var send = $('.comment-send', form);
      send.disabled = true;
      api('comment', { notice_id: card.dataset.noticeId, body: text }).then(function (res) {
        send.disabled = false;
        if (!res.success) { alert(res.message || 'Could not post comment.'); return; }
        input.value = '';
        input.style.height = 'auto';
        form.insertAdjacentHTML('beforebegin', res.html);
        var countEl = $('.comment-count', card);
        if (countEl) countEl.textContent = res.count + (res.count === 1 ? ' comment' : ' comments');
      }).catch(function () { send.disabled = false; });
    });

    /* Auto-grow comment boxes */
    feed.addEventListener('input', function (e) {
      if (!e.target.classList.contains('comment-input')) return;
      e.target.style.height = 'auto';
      e.target.style.height = Math.min(e.target.scrollHeight, 120) + 'px';
      var send = $('.comment-send', e.target.closest('.comment-form'));
      if (send) send.disabled = !e.target.value.trim();
    });

    /* Enter sends, Shift+Enter newlines */
    feed.addEventListener('keydown', function (e) {
      if (!e.target.classList.contains('comment-input')) return;
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        var form = e.target.closest('.comment-form');
        if (form) form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
      }
    });
  }

  /* ── Video lightbox: play course videos inside the site ────────────────── */
  function initVideoModal() {
    var modal = $('#videoModal');
    if (!modal) return;
    var frame = $('#videoModalFrame', modal);

    function open(src) {
      // autoplay=1 only works with the embed URL, which PHP already normalised
      frame.src = src + (src.indexOf('?') > -1 ? '&' : '?') + 'autoplay=1&rel=0';
      modal.hidden = false;
      requestAnimationFrame(function () { modal.classList.add('open'); });
      document.body.classList.add('no-scroll');
    }
    function close() {
      modal.classList.remove('open');
      document.body.classList.remove('no-scroll');
      frame.src = '';                       // stops playback
      setTimeout(function () { modal.hidden = true; }, 220);
    }

    document.addEventListener('click', function (e) {
      var trigger = e.target.closest('[data-video]');
      if (trigger && trigger.dataset.video) {
        e.preventDefault();
        open(trigger.dataset.video);
        return;
      }
      if (e.target.closest('.video-modal-close') || e.target === modal) close();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) close();
    });
  }

  /* ── FAQ block search filter ───────────────────────────────────────────── */
  function initFaqSearch() {
    $$('[data-faq-search]').forEach(function (input) {
      // Search the whole document by default so one search box (e.g. in a
      // page hero) can filter several category sections below it, each with
      // its own [data-faq-list]; a search box placed inside a .cs-block only
      // filters that block's own list (course-page FAQ blocks).
      var scope = input.closest('.cs-block') || document;
      var lists = $$('[data-faq-list]', scope);
      if (!lists.length) return;

      input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        var totalShown = 0;

        lists.forEach(function (list) {
          var items = $$('.cs-faq-item', list);
          var empty = $('.faq-empty', list.parentElement) || $('.faq-empty', list);
          var shown = 0;
          items.forEach(function (item) {
            var hit = !q || item.textContent.toLowerCase().indexOf(q) > -1;
            item.hidden = !hit;
            if (hit) shown++;
            // Auto-open the single remaining match so the answer is visible
            item.classList.toggle('open', !!q && hit && shown === 1);
          });
          totalShown += shown;
          if (empty) empty.style.display = shown ? 'none' : 'block';

          // Hide the whole category section when nothing in it matches
          var section = list.closest('section');
          if (section) section.style.display = shown ? '' : 'none';
        });
      });
    });
  }

  /* ── Course page segmented tabs ────────────────────────────────────────── */
  function initCourseTabs() {
    var bar = $('[data-cx-tabs]');
    if (!bar) return;
    var tabs = $$('.cx-tab', bar);
    var panels = $$('.cx-panel');
    if (!tabs.length) return;

    function activate(name, push) {
      var found = false;
      tabs.forEach(function (t) {
        var on = t.dataset.tab === name;
        t.classList.toggle('active', on);
        t.setAttribute('aria-selected', String(on));
        if (on) {
          found = true;
          // keep the active pill in view on narrow screens
          if (t.scrollIntoView) t.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' });
        }
      });
      if (!found) return;
      panels.forEach(function (p) { p.classList.toggle('active', p.id === name); });
      if (push && history.replaceState) history.replaceState(null, '', '#' + name);
    }

    bar.addEventListener('click', function (e) {
      var tab = e.target.closest('.cx-tab');
      if (!tab) return;
      activate(tab.dataset.tab, true);
      // Scroll so the panel starts just under the sticky tab bar
      var top = bar.getBoundingClientRect().top + window.scrollY - 4;
      if (window.scrollY > top) window.scrollTo({ top: top, behavior: 'smooth' });
    });

    // Arrow-key navigation between pills
    bar.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      var i = tabs.indexOf(document.activeElement);
      if (i < 0) return;
      e.preventDefault();
      var next = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
      next.focus();
      activate(next.dataset.tab, true);
    });

    // Deep links: /course.php?slug=…#curriculum
    var hash = window.location.hash.replace('#', '');
    if (hash) activate(hash, false);
  }

  /* ── Sticky purchase bar: reveal once the hero enroll card scrolls away ── */
  function initBuyBar() {
    var bar = $('#buyBar');
    if (!bar) return;
    var anchor = $('#enrollTarget');

    // No anchor (or no IntersectionObserver) → just show it after a short scroll
    if (!anchor || !('IntersectionObserver' in window)) {
      var onScroll = function () { bar.classList.toggle('show', window.scrollY > 320); };
      window.addEventListener('scroll', onScroll, { passive: true });
      onScroll();
      return;
    }

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        // Hidden while the desktop enroll card is on screen, shown otherwise
        bar.classList.toggle('show', !entry.isIntersecting);
      });
    }, { rootMargin: '-80px 0px 0px 0px', threshold: 0 });
    io.observe(anchor);
  }

  /* ── Image skeletons: drop the shimmer once decoded ─────────────────────── */
  function initImages() {
    $$('img.skeleton').forEach(function (img) {
      if (img.complete) { img.classList.remove('skeleton'); return; }
      img.addEventListener('load', function () { img.classList.remove('skeleton'); });
      img.addEventListener('error', function () { img.classList.remove('skeleton'); });
    });
  }

  function boot() {
    initDrawer();
    initPanelNav();
    initMarquee();
    initFaq();
    initFaqSearch();
    initFeed();
    initCourseTabs();
    initBuyBar();
    initVideoModal();
    initImages();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
