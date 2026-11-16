// Language Hub UI: toggles, 3D language carousel, tilt, sliding switch,
// scroll reveal, bottom-tab indicator, countdown. No dependencies.
(function () {
  'use strict';
  var root = document.documentElement;
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fine = window.matchMedia && matchMedia('(hover: hover) and (pointer: fine)').matches;
  function save(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function lang() { return root.getAttribute('data-lang') === 'en' ? 'en' : 'bn'; }
  function $$(s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); }

  // ── Theme + language toggles ──────────────────────────────────────────────
  $$('[data-lh-theme]').forEach(function (b) {
    b.addEventListener('click', function () {
      var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next); save('lh_theme', next);
    });
  });
  $$('[data-lh-lang]').forEach(function (b) {
    b.addEventListener('click', function () {
      var next = lang() === 'en' ? 'bn' : 'en';
      root.setAttribute('data-lang', next); root.lang = next; save('lh_lang', next);
      drawCountdowns();
    });
  });

  // ── Scroll reveal: slide sections in once ─────────────────────────────────
  var revealEls = $$('[data-reveal], [data-reveal-group]');
  $$('[data-reveal-group]').forEach(function (g) {
    Array.prototype.forEach.call(g.children, function (c, i) { c.style.setProperty('--i', Math.min(i, 6)); });
  });
  if ('IntersectionObserver' in window && !reduce) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); } });
    }, { threshold: 0.12, rootMargin: '0px 0px -30px 0px' });
    revealEls.forEach(function (el) { io.observe(el); });
  } else {
    revealEls.forEach(function (el) { el.classList.add('is-in'); });
  }

  // ── Language carousel: 3D coverflow driven by scroll position ─────────────
  $$('[data-carousel]').forEach(function (car) {
    var track = car.querySelector('[data-track]');
    var slides = $$('[data-slide]', track);
    var dotsBox = car.querySelector('[data-dots]');
    if (!track || !slides.length) return;
    var dots = slides.map(function (s, i) {
      var d = document.createElement('button');
      d.type = 'button'; d.setAttribute('aria-label', String(i + 1));
      d.addEventListener('click', function () { center(i, true); });
      dotsBox && dotsBox.appendChild(d);
      return d;
    });
    function center(i, smooth) {
      var s = slides[i];
      track.scrollTo({ left: s.offsetLeft - (track.clientWidth - s.offsetWidth) / 2, behavior: smooth && !reduce ? 'smooth' : 'auto' });
    }
    var raf = 0, idle;
    function paint() {
      raf = 0;
      var mid = track.scrollLeft + track.clientWidth / 2, best = 0, bestD = Infinity;
      var scrollable = track.scrollWidth > track.clientWidth + 2;
      slides.forEach(function (s, i) {
        var c = s.offsetLeft + s.offsetWidth / 2;
        var d = (c - mid) / s.offsetWidth;               // -1 … 0 … 1 (slide widths from centre)
        if (Math.abs(d) < bestD) { bestD = Math.abs(d); best = i; }
        if (!scrollable || reduce) { s.style.removeProperty('--ry'); s.style.removeProperty('--s'); s.style.removeProperty('--o'); return; }
        var k = Math.max(-1.5, Math.min(1.5, d));
        s.style.setProperty('--ry', (k * -28).toFixed(2) + 'deg');
        s.style.setProperty('--s', (1 - Math.min(Math.abs(k), 1) * 0.12).toFixed(3));
        s.style.setProperty('--o', (1 - Math.min(Math.abs(k), 1) * 0.35).toFixed(3));
      });
      if (!scrollable) best = car._focus || 0;   // desktop: highlighted card follows the board
      slides.forEach(function (s, i) { s.classList.toggle('is-center', i === best); });
      dots.forEach(function (d, i) { d.classList.toggle('is-on', i === best); });
      if (best !== car._best) {
        car._best = best;
        car.dispatchEvent(new CustomEvent('lh:center', { detail: { index: best } }));
      }
    }
    car._count = slides.length;
    car._goto = function (i) {
      i = (i + slides.length) % slides.length;
      if (track.scrollWidth > track.clientWidth + 2) center(i, true);
      else { car._focus = i; paint(); }
    };
    // Any touch / wheel / key on the carousel means the visitor took over
    ['pointerdown', 'wheel', 'keydown', 'touchstart'].forEach(function (ev) {
      track.addEventListener(ev, function () { car._userTook = true; }, { passive: true });
    });
    track.addEventListener('scroll', function () {
      car.classList.add('is-scrolling');
      clearTimeout(idle); idle = setTimeout(function () { car.classList.remove('is-scrolling'); }, 120);
      if (!raf) raf = requestAnimationFrame(paint);
    }, { passive: true });
    window.addEventListener('resize', function () { if (!raf) raf = requestAnimationFrame(paint); });
    // Start on the second card so a neighbour peeks on both sides (it says "swipe me")
    requestAnimationFrame(function () { center(slides.length > 2 ? 1 : 0, false); paint(); });
  });

  // ── Hero: split-flap departure board synced with the carousel ─────────────
  $$('[data-hero]').forEach(function (hero) {
    var flap = hero.querySelector('[data-flap]');
    var car = hero.querySelector('[data-carousel]');
    if (!flap) return;
    var faces = {
      top: flap.querySelector('.lh-flap-top:not(.lh-flap-fly-top)'),
      bot: flap.querySelector('.lh-flap-bot:not(.lh-flap-fly-bot)'),
      flyTop: flap.querySelector('.lh-flap-fly-top'),
      flyBot: flap.querySelector('.lh-flap-fly-bot')
    };
    var colors = (flap.getAttribute('data-colors') || '').split('|');
    var cur = 0, busy = false, queued = null;
    function words() { return (flap.getAttribute('data-' + lang()) || '').split('|'); }
    function setFace(el, i) { el.textContent = words()[i] || ''; el.style.setProperty('--c', colors[i] || 'var(--lh-brand)'); }
    function tint(i) { hero.style.setProperty('--lang', colors[i] || 'var(--lh-brand)'); }
    function show(i) { [faces.top, faces.bot].forEach(function (f) { setFace(f, i); }); tint(i); }
    show(0);

    function flipTo(i) {
      if (i === cur) return;
      if (busy) { queued = i; return; }
      if (reduce) { cur = i; show(i); return; }
      busy = true;
      setFace(faces.flyTop, cur);   // old top half falls…
      setFace(faces.top, i);        // …revealing the new top half behind it
      setFace(faces.flyBot, i);     // new bottom half swings down over the old one
      flap.classList.remove('is-flipping'); void flap.offsetWidth; flap.classList.add('is-flipping');
      tint(i);
      setTimeout(function () {
        setFace(faces.bot, i);
        flap.classList.remove('is-flipping');
        cur = i; busy = false;
        if (queued !== null) { var q = queued; queued = null; flipTo(q); }
      }, 620);
    }
    // Re-label on language switch
    $$('[data-lh-lang]').forEach(function (b) { b.addEventListener('click', function () { show(cur); }); });

    if (car) {
      car.addEventListener('lh:center', function (e) { flipTo(e.detail.index); });
      // Auto-advance until the visitor touches the cards; pauses when hidden.
      if (!reduce) {
        setInterval(function () {
          if (car._userTook || document.hidden || window.scrollY > hero.offsetHeight) return;
          car._goto((car._best || 0) + 1);
        }, 3200);
      }
    }

    // Scroll: headline lifts + fades, letters drift at different depths
    if (!reduce) {
      var ticking = false;
      var onScroll = function () {
        ticking = false;
        var p = Math.min(1, Math.max(0, window.scrollY / (hero.offsetHeight * 0.8)));
        hero.style.setProperty('--p', p.toFixed(3));
      };
      window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(onScroll); } }, { passive: true });
      onScroll();
      // Desktop: letters lean toward the pointer a little
      if (fine) hero.addEventListener('pointermove', function (e) {
        var r = hero.getBoundingClientRect();
        hero.style.setProperty('--mx', ((e.clientX - r.left) / r.width - 0.5).toFixed(3));
        hero.style.setProperty('--my', ((e.clientY - r.top) / r.height - 0.5).toFixed(3));
      });
    }
  });

  // ── Homepage course filter: instant, no reload; "show more" after N ───────
  $$('[data-course-filter]').forEach(function (box) {
    var chips = $$('[data-filter]', box);
    var items = $$('[data-items] > *', box);
    var more = box.querySelector('[data-more]'), empty = box.querySelector('[data-empty]');
    var size = +box.getAttribute('data-page-size') || 12, limit = size, current = 'all';
    function match(el, f) {
      if (f === 'all') return true;
      if (f === 'free') return el.getAttribute('data-free') === '1';
      var p = f.split(':');
      return el.getAttribute('data-' + p[0]) === p[1];
    }
    function render(animate) {
      var shown = 0, total = 0;
      items.forEach(function (el) {
        var ok = match(el, current);
        if (ok) total++;
        var vis = ok && total <= limit;
        el.hidden = !vis;
        if (vis && animate && !reduce) {
          el.classList.remove('lh-pop'); void el.offsetWidth;
          el.style.setProperty('--i', Math.min(shown, 8));
          el.classList.add('lh-pop');
        }
        if (vis) shown++;
      });
      more.hidden = total <= limit;
      empty.hidden = total > 0;
    }
    chips.forEach(function (c) {
      c.addEventListener('click', function () {
        current = c.getAttribute('data-filter'); limit = size;
        chips.forEach(function (o) { var on = o === c; o.classList.toggle('is-on', on); o.setAttribute('aria-pressed', on); });
        c.scrollIntoView({ block: 'nearest', inline: 'center', behavior: reduce ? 'auto' : 'smooth' });
        render(true);
      });
    });
    more.addEventListener('click', function () { limit += size; render(false); });
    render(false);
  });

  // ── Classroom: mark lesson done → save → go to next ───────────────────────
  $$('[data-done]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (btn.classList.contains('is-done')) { if (btn.dataset.next) location.href = btn.dataset.next; return; }
      btn.classList.add('is-busy');
      fetch(APP_URL + '/api/progress.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: JSON.stringify({ lesson_id: +btn.dataset.done, completed: true })
      }).then(function (r) { return r.json(); }).then(function (j) {
        btn.classList.remove('is-busy');
        if (!j || j.success === false) throw new Error();
        btn.classList.remove('btn-primary'); btn.classList.add('btn-outline', 'is-done');
        btn.querySelector('.material-icons-round').textContent = 'check_circle';
        btn.querySelector('[data-done-label]').innerHTML = '<span class="i18n" lang="bn">সম্পন্ন</span><span class="i18n" lang="en">Done</span>';
        var li = document.querySelector('.lh-pl-item.is-current, .lh-curr-list li.is-current');
        if (li) { li.classList.add('is-done'); var ic = li.querySelector('[data-pl-icon]') || li.querySelector('.material-icons-round'); if (ic) ic.textContent = 'check_circle'; }
        if (btn.dataset.next) setTimeout(function () { location.href = btn.dataset.next; }, 700);
        else setTimeout(function () { location.reload(); }, 700);
      }).catch(function () {
        btn.classList.remove('is-busy');
        alert(lang() === 'en' ? 'Could not save. Please try again.' : 'সেভ করা যায়নি। আবার চেষ্টা করুন।');
      });
    });
  });

  // ── Photo picker: preview, and shrink big phone photos to 800px JPEG before upload ──
  $$('input[type=file][data-img-resize]').forEach(function (input) {
    var box = input.closest('.lh-photo-in');
    var preview = box && box.querySelector('[data-photo-preview]');
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      if (!file || !/^image\//.test(file.type)) return;
      var url = URL.createObjectURL(file);
      if (preview) preview.src = url;
      var rm = box && box.querySelector('input[type=checkbox]');
      if (rm) rm.checked = false;
      if (file.type === 'image/gif' || typeof DataTransfer === 'undefined') return;
      var img = new Image();
      img.onload = function () {
        var max = 800, w = img.naturalWidth, h = img.naturalHeight;
        if (w <= max && h <= max && file.size < 400 * 1024) return;
        var s = Math.min(1, max / Math.max(w, h));
        var c = document.createElement('canvas');
        c.width = Math.round(w * s); c.height = Math.round(h * s);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        c.toBlob(function (blob) {
          if (!blob) return;
          var dt = new DataTransfer();
          dt.items.add(new File([blob], (file.name || 'photo').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }));
          input.files = dt.files;
        }, 'image/jpeg', 0.86);
      };
      img.src = url;
    });
  });

  // ── Classroom playlist: bring the class on screen into view (inside the list only) ──
  $$('[data-pl-scroll]').forEach(function (box) {
    var cur = box.querySelector('.lh-pl-item.is-current');
    if (cur && box.scrollHeight > box.clientHeight) box.scrollTop = Math.max(0, cur.offsetTop - box.offsetTop - 80);
  });

  // ── Protected player (classroom) ─────────────────────────────────────────
  // Nothing playable is in the page: pressing play asks /api/player (login + course access).
  // YouTube runs without its own controls under a transparent shield, so its title, logo,
  // share and "Watch on YouTube" links can't be clicked and its right-click menu never opens.
  // Our own controls drive it through the IFrame API.
  (function () {
    var boxes = $$('[data-pp]');
    if (!boxes.length) return;
    var T = function (bn, en) { return lang() === 'en' ? en : bn; };
    var ICON = function (n) { return '<span class="material-icons-round" aria-hidden="true">' + n + '</span>'; };
    var ytReady = null;
    function loadYT() {
      if (ytReady) return ytReady;
      ytReady = new Promise(function (res, rej) {
        if (window.YT && window.YT.Player) return res();
        var prev = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = function () { if (prev) prev(); res(); };
        var s = document.createElement('script');
        s.src = 'https://www.youtube.com/iframe_api';
        s.onerror = function () { ytReady = null; rej(new Error('api')); };
        document.head.appendChild(s);
        setTimeout(function () { if (!(window.YT && window.YT.Player)) { ytReady = null; rej(new Error('api')); } }, 20000);
      });
      return ytReady;
    }
    function fmt(t) {
      t = Math.max(0, Math.floor(t || 0));
      var h = Math.floor(t / 3600), m = Math.floor(t % 3600 / 60), s = t % 60;
      return (h ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }
    function store(k, v) { try { if (v === undefined) return localStorage.getItem(k); if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) { return null; } }
    function block(e) { e.preventDefault(); }

    function message(box, text) {
      var m = box.querySelector('.lh-vp-msg') || box.appendChild(document.createElement('div'));
      m.className = 'lh-vp-msg'; m.setAttribute('role', 'alert'); m.textContent = text;
    }

    function controls(box) {
      var bar = document.createElement('div');
      bar.className = 'lh-vp-bar';
      bar.innerHTML =
        '<input type="range" class="lh-vp-seek" min="0" max="1000" value="0" step="1" aria-label="' + T('ভিডিওর অবস্থান', 'Seek') + '">' +
        '<div class="lh-vp-row">' +
          '<button type="button" data-a="play" aria-label="' + T('চালান / থামান', 'Play / pause') + '">' + ICON('play_arrow') + '</button>' +
          '<button type="button" data-a="back" aria-label="' + T('১০ সেকেন্ড পিছনে', 'Back 10 seconds') + '">' + ICON('replay_10') + '</button>' +
          '<button type="button" data-a="fwd" aria-label="' + T('১০ সেকেন্ড সামনে', 'Forward 10 seconds') + '">' + ICON('forward_10') + '</button>' +
          '<button type="button" data-a="mute" aria-label="' + T('শব্দ', 'Mute') + '">' + ICON('volume_up') + '</button>' +
          '<span class="lh-vp-time" aria-live="off">0:00 / 0:00</span>' +
          '<span class="lh-vp-gap"></span>' +
          '<select data-a="speed" aria-label="' + T('গতি', 'Speed') + '"><option value="0.75">0.75×</option><option value="1" selected>1×</option><option value="1.25">1.25×</option><option value="1.5">1.5×</option><option value="2">2×</option></select>' +
          '<button type="button" data-a="fs" aria-label="' + T('ফুল স্ক্রিন', 'Full screen') + '">' + ICON('fullscreen') + '</button>' +
        '</div>';
      return bar;
    }

    function fsElement() { return document.fullscreenElement || document.webkitFullscreenElement; }
    function toggleFs(box) {
      if (fsElement()) { (document.exitFullscreen || document.webkitExitFullscreen).call(document); return; }
      if (box.classList.contains('is-fs')) { box.classList.remove('is-fs'); document.body.classList.remove('lh-vp-lock'); return; }
      var req = box.requestFullscreen || box.webkitRequestFullscreen;
      if (req) {
        var p = req.call(box);
        if (p && p.then) p.then(function () { try { screen.orientation.lock('landscape'); } catch (e) {} }).catch(function () { box.classList.add('is-fs'); });
      } else { box.classList.add('is-fs'); document.body.classList.add('lh-vp-lock'); } // iPhone: fill the screen ourselves
    }

    function mount(box, req) {
      if (box._pp) box._pp.destroy();
      box.classList.remove('is-error', 'is-ended', 'is-frame');
      box.classList.add('is-loading');
      var frame = box.querySelector('[data-pp-frame]');
      frame.innerHTML = '';
      fetch('/api/player', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(req) })
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw new Error(j.error || String(r.status)); return j; }); })
        .then(function (j) {
          if (j.type === 'frame') {
            var f = document.createElement('iframe');
            f.src = j.src; f.allow = 'autoplay; fullscreen; picture-in-picture'; f.setAttribute('allowfullscreen', '');
            f.referrerPolicy = 'strict-origin-when-cross-origin';
            frame.appendChild(f);
            box.classList.remove('is-loading'); box.classList.add('is-frame', 'is-started');
            box._pp = { destroy: function () { frame.innerHTML = ''; } };
            return;
          }
          return loadYT().then(function () { build(box, frame, j.v, req); });
        })
        .catch(function (e) {
          box.classList.remove('is-loading'); box.classList.add('is-error');
          // Free classes need a sign-in (the watermark shows who is watching): go to login, then come back.
          if (e.message === 'login') { location.href = '/login?next=' + encodeURIComponent(location.pathname + location.search); return; }
          message(box, e.message === 'forbidden' ? T('এই ভিডিও দেখার অনুমতি আপনার নেই।', 'You do not have access to this video.')
            : T('ভিডিও লোড করা যায়নি। ইন্টারনেট দেখে পেজটি রিফ্রেশ করুন।', 'Could not load the video. Check your internet and refresh the page.'));
        });
    }

    function build(box, frame, videoId, req) {
      var holder = document.createElement('div');
      frame.appendChild(holder);
      var shield = document.createElement('div');
      shield.className = 'lh-vp-shield';
      var pause = document.createElement('div');
      pause.className = 'lh-vp-pause';
      pause.innerHTML = '<span class="lh-vp-big">' + ICON('play_arrow') + '</span>';
      var bar = controls(box);
      var sound = document.createElement('button');
      sound.type = 'button'; sound.className = 'lh-vp-unmute';
      sound.innerHTML = ICON('volume_up') + '<span>' + T('শব্দ চালু করতে ট্যাপ করুন', 'Tap for sound') + '</span>';
      box.appendChild(shield); box.appendChild(pause); box.appendChild(bar); box.appendChild(sound);
      var seek = bar.querySelector('.lh-vp-seek'), time = bar.querySelector('.lh-vp-time');
      var playBtn = bar.querySelector('[data-a=play]'), muteBtn = bar.querySelector('[data-a=mute]');
      var key = 'lh_pos_' + (req.lesson ? 'l' + req.lesson : 'r' + req.recording);
      var timer = null, seeking = false, started = false, hideT = null;

      var player = new window.YT.Player(holder, {
        videoId: videoId,
        host: 'https://www.youtube-nocookie.com',
        playerVars: { autoplay: 1, controls: 0, disablekb: 1, fs: 0, rel: 0, modestbranding: 1, iv_load_policy: 3, playsinline: 1, cc_load_policy: 0, origin: location.origin },
        events: {
          onReady: function () {
            var ifr = player.getIframe && player.getIframe();
            if (ifr) { ifr.setAttribute('tabindex', '-1'); ifr.setAttribute('title', ''); ifr.referrerPolicy = 'strict-origin-when-cross-origin'; }
            box.classList.remove('is-loading');
            var saved = Number(store(key)) || 0, d = player.getDuration() || 0;
            if (saved > 10 && (!d || saved < d - 15)) player.seekTo(saved, true);
            player.playVideo();
            // If the browser blocks sound, start muted (always allowed) with a "tap for sound" button —
            // so no click ever has to reach YouTube itself. Only a phone that refuses even that gets
            // one tap through to YouTube's centre button, with the title and logo edges still covered.
            setTimeout(function () {
              if (started) return;
              player.mute(); player.playVideo();
              box.classList.add('is-muted-start'); muteBtn.innerHTML = ICON('volume_off');
              setTimeout(function () { if (!started && window.matchMedia('(pointer: coarse)').matches) box.classList.add('is-tap'); }, 3000);
            }, 1500);
            timer = setInterval(tick, 250);
          },
          onStateChange: function (e) {
            var S = window.YT.PlayerState;
            if (e.data === S.PLAYING) { started = true; box.classList.remove('is-tap', 'is-paused', 'is-ended'); box.classList.add('is-started', 'is-playing'); playBtn.innerHTML = ICON('pause'); idle(); }
            else if (e.data === S.PAUSED) { box.classList.remove('is-playing'); if (started) box.classList.add('is-paused'); playBtn.innerHTML = ICON('play_arrow'); box.classList.remove('is-idle'); }
            else if (e.data === S.ENDED) { box.classList.remove('is-playing'); box.classList.add('is-ended'); playBtn.innerHTML = ICON('replay'); store(key, null); box.classList.remove('is-idle'); }
          },
          onError: function () { box.classList.add('is-error'); message(box, T('এই ভিডিওটি চালানো যাচ্ছে না। শিক্ষককে জানান।', 'This video cannot be played. Please tell your teacher.')); }
        }
      });

      function tick() {
        if (!player || !player.getDuration) return;
        var d = player.getDuration() || 0, t = player.getCurrentTime() || 0;
        if (!seeking && d) seek.value = Math.round(t / d * 1000);
        seek.style.setProperty('--p', (d ? t / d * 100 : 0) + '%');
        time.textContent = fmt(t) + ' / ' + fmt(d);
        if (t > 5 && Math.floor(t) % 5 === 0) store(key, String(Math.floor(t)));
      }
      function toggle() {
        var st = player.getPlayerState && player.getPlayerState();
        if (st === window.YT.PlayerState.PLAYING) player.pauseVideo();
        else { if (st === window.YT.PlayerState.ENDED) player.seekTo(0, true); player.playVideo(); }
      }
      function jump(s) { player.seekTo(Math.max(0, (player.getCurrentTime() || 0) + s), true); }
      function idle() { box.classList.remove('is-idle'); clearTimeout(hideT); hideT = setTimeout(function () { if (box.classList.contains('is-playing')) box.classList.add('is-idle'); }, 2600); }

      function unmute() { player.unMute(); player.setVolume(100); box.classList.remove('is-muted-start'); muteBtn.innerHTML = ICON('volume_up'); }
      sound.addEventListener('click', unmute);
      // First tap on a muted start turns the sound on instead of pausing.
      shield.addEventListener('click', function () { if (box.classList.contains('is-muted-start')) unmute(); else toggle(); });
      pause.addEventListener('click', toggle);
      shield.addEventListener('dblclick', function () { toggleFs(box); });
      box.addEventListener('mousemove', idle);
      box.addEventListener('touchstart', idle, { passive: true });
      seek.addEventListener('input', function () { seeking = true; var d = player.getDuration() || 0; time.textContent = fmt(seek.value / 1000 * d) + ' / ' + fmt(d); });
      seek.addEventListener('change', function () { player.seekTo(seek.value / 1000 * (player.getDuration() || 0), true); seeking = false; });
      bar.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-a]'); if (!b) return;
        var a = b.dataset.a;
        if (a === 'play') toggle();
        else if (a === 'back') jump(-10);
        else if (a === 'fwd') jump(10);
        else if (a === 'mute') { if (player.isMuted()) { unmute(); } else { player.mute(); b.innerHTML = ICON('volume_off'); } }
        else if (a === 'fs') toggleFs(box);
        idle();
      });
      bar.querySelector('[data-a=speed]').addEventListener('change', function (e) { player.setPlaybackRate(Number(e.target.value) || 1); });
      box.onkeydown = function (e) {
        if (e.target.closest('select, input')) return;
        var k = e.key;
        if (k === ' ' || k === 'k') { e.preventDefault(); toggle(); }
        else if (k === 'ArrowRight') { e.preventDefault(); jump(5); }
        else if (k === 'ArrowLeft') { e.preventDefault(); jump(-5); }
        else if (k === 'f') toggleFs(box);
        else if (k === 'm') muteBtn.click();
        else if (k === 'Escape' && box.classList.contains('is-fs')) toggleFs(box);
      };

      box._pp = {
        destroy: function () {
          clearInterval(timer); clearTimeout(hideT);
          try { player.destroy(); } catch (e) {}
          [shield, pause, bar, sound].forEach(function (n) { n.remove(); });
          frame.innerHTML = '';
          box.classList.remove('is-started', 'is-playing', 'is-paused', 'is-ended', 'is-tap', 'is-idle', 'is-fs', 'is-muted-start');
          box._pp = null;
        }
      };
    }

    boxes.forEach(function (box) {
      // No right-click menu ("Copy video URL"), no dragging the picture out.
      box.addEventListener('contextmenu', block);
      box.addEventListener('dragstart', block);
      var start = box.querySelector('[data-pp-start]');
      if (start && box.dataset.lesson) start.addEventListener('click', function () { box.classList.add('is-started'); mount(box, { lesson: Number(box.dataset.lesson) }); });
    });
    document.addEventListener('fullscreenchange', function () { if (!fsElement()) $$('.lh-vp').forEach(function (b) { b.classList.remove('is-fs'); }); });

    // Free classes, trailers and live-class recordings play in the same protected player, in a pop-up.
    var modal = document.querySelector('[data-pp-modal]');
    if (modal) {
      var mbox = modal.querySelector('[data-pp]');
      var close = function () { if (mbox._pp) mbox._pp.destroy(); modal.hidden = true; document.body.classList.remove('lh-vp-lock'); };
      document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-pp-open]');
        if (!b) return;
        e.preventDefault();
        var req = b.dataset.lesson ? { lesson: Number(b.dataset.lesson) } : b.dataset.recording ? { recording: Number(b.dataset.recording) } : { intro: Number(b.dataset.intro) };
        modal.hidden = false; document.body.classList.add('lh-vp-lock');
        mbox.classList.add('is-started');
        mount(mbox, req);
        mbox.focus();
      });
      modal.querySelector('[data-pp-close]').addEventListener('click', close);
      modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden && !fsElement()) close(); });
    }
  })();

  // ── Watermark: drifts to a new spot every few seconds ─────────────────────
  $$('[data-watermark]').forEach(function (w) {
    function move() {
      w.style.left = (8 + Math.random() * 60) + '%';
      w.style.top = (10 + Math.random() * 70) + '%';
    }
    move(); setInterval(move, 5000);
  });

  // ── Live class Join button: opens 10 minutes before start ────────────────
  $$('[data-join]').forEach(function (a) {
    var start = +a.dataset.start * 1000, label = a.querySelector('[data-join-label]'), orig = label.innerHTML;
    var locked = '<span class="i18n" lang="bn">ক্লাসের ১০ মিনিট আগে খুলবে</span><span class="i18n" lang="en">Opens 10 min before class</span>';
    function tick() {
      var open = Date.now() >= start - 10 * 60 * 1000;
      a.classList.toggle('is-locked', !open);
      a.setAttribute('aria-disabled', open ? 'false' : 'true');
      label.innerHTML = open ? orig : locked;
    }
    a.addEventListener('click', function (e) { if (a.classList.contains('is-locked')) e.preventDefault(); });
    tick(); setInterval(tick, 15000);
  });

  // ── 3D tilt (boarding pass): follows pointer on desktop, finger on phones ─
  $$('[data-tilt]').forEach(function (el) {
    if (reduce) return;
    function move(x, y) {
      var r = el.getBoundingClientRect();
      var px = (x - r.left) / r.width - 0.5, py = (y - r.top) / r.height - 0.5;
      el.classList.add('is-tilting');
      el.style.setProperty('--ry', (px * 14).toFixed(2) + 'deg');
      el.style.setProperty('--rx', (py * -12).toFixed(2) + 'deg');
    }
    function reset() { el.classList.remove('is-tilting'); el.style.setProperty('--ry', '0deg'); el.style.setProperty('--rx', '0deg'); }
    if (fine) {
      el.addEventListener('pointermove', function (e) { move(e.clientX, e.clientY); });
      el.addEventListener('pointerleave', reset);
    } else {
      el.addEventListener('touchmove', function (e) { var t = e.touches[0]; move(t.clientX, t.clientY); }, { passive: true });
      el.addEventListener('touchend', reset);
      // Gentle one-time "hello" tilt when it slides into view, so phones see the 3D
      if ('IntersectionObserver' in window) {
        var once = new IntersectionObserver(function (en) {
          if (!en[0].isIntersecting) return;
          once.disconnect();
          setTimeout(function () { el.style.setProperty('--ry', '-10deg'); el.style.setProperty('--rx', '6deg'); }, 500);
          setTimeout(reset, 1300);
        }, { threshold: 0.6 });
        once.observe(el);
      }
    }
  });

  // ── Menu dropdowns (Languages): click/tap toggles, hover opens on desktop ──
  $$('[data-lh-drop]').forEach(function (wrap) {
    var btn = wrap.querySelector('button'), menu = wrap.querySelector('.dropdown-menu'), timer, hoverAt = 0;
    if (!btn || !menu) return;
    function set(open) {
      clearTimeout(timer);
      menu.classList.toggle('open', open);
      wrap.classList.toggle('is-open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      // A click right after hover opened it should keep it open, not close it again.
      set(Date.now() - hoverAt < 600 || !menu.classList.contains('open'));
    });
    if (fine) {
      wrap.addEventListener('mouseenter', function () { hoverAt = Date.now(); set(true); });
      wrap.addEventListener('mouseleave', function () { timer = setTimeout(function () { set(false); }, 180); });
    }
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) set(false); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && menu.classList.contains('open')) { set(false); btn.focus(); }
    });
  });

  // ── Buying a course = printing a ticket ────────────────────────────────────
  // The form still submits normally; the printer scene only plays first (≈2.7 s).
  var printer = document.querySelector('[data-ticket-printer]');
  $$('form[data-ticket-buy]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (form.getAttribute('data-sent')) { e.preventDefault(); return; }   // no double payments
      form.setAttribute('data-sent', '1');
      var btn = form.querySelector('[type=submit]');
      if (btn) { btn.disabled = true; btn.classList.add('is-busy'); }
      if (reduce || !printer) return;
      e.preventDefault();
      printer.classList.toggle('is-manual', form.getAttribute('data-ticket-buy') === 'manual'); // bKash/Nagad: awaits approval
      printer.hidden = false;
      document.body.classList.add('no-scroll');
      requestAnimationFrame(function () { requestAnimationFrame(function () { printer.classList.add('is-printing'); }); });
      setTimeout(function () { printer.classList.add('is-booked'); }, 1500);
      setTimeout(function () { HTMLFormElement.prototype.submit.call(form); }, 2700);
    });
  });
  // Coming back with the browser's Back button: reset the scene and the buttons.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    $$('form[data-ticket-buy]').forEach(function (f) {
      f.removeAttribute('data-sent');
      var b = f.querySelector('[type=submit]'); if (b) { b.disabled = false; b.classList.remove('is-busy'); }
    });
    if (printer) { printer.hidden = true; printer.classList.remove('is-printing', 'is-booked'); document.body.classList.remove('no-scroll'); }
  });

  // ── Sliding switch (Live / Recorded): tap or swipe ────────────────────────
  $$('[data-switch]').forEach(function (sw) {
    var tabs = $$('[data-tab]', sw);
    function set(i) {
      sw.setAttribute('data-active', i);
      tabs.forEach(function (t, k) { t.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
    }
    tabs.forEach(function (t, i) { t.addEventListener('click', function () { set(i); }); });
    var panes = sw.querySelector('.lh-panes'), x0 = null, y0 = 0;
    if (panes) {
      panes.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; }, { passive: true });
      panes.addEventListener('touchend', function (e) {
        if (x0 === null) return;
        var dx = e.changedTouches[0].clientX - x0, dy = e.changedTouches[0].clientY - y0;
        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) set(dx < 0 ? 1 : 0);
        x0 = null;
      });
    }
    set(0);
  });

  // ── Bottom tab bar: sliding indicator under the active tab ────────────────
  var tabbar = document.querySelector('.bottom-nav');
  if (tabbar) {
    var ind = document.createElement('span'); ind.className = 'lh-tab-ind'; ind.setAttribute('aria-hidden', 'true');
    tabbar.appendChild(ind);
    var placeOn = function (a) {
      if (!a) { ind.style.width = '0'; return; }
      ind.style.width = a.offsetWidth + 'px';
      ind.style.transform = 'translateX(' + a.offsetLeft + 'px)';
    };
    var active = tabbar.querySelector('.bottom-nav-item.active');
    placeOn(active);
    window.addEventListener('resize', function () { placeOn(tabbar.querySelector('.bottom-nav-item.active')); });
    $$('.bottom-nav-item', tabbar).forEach(function (a) {
      a.addEventListener('click', function () { $$('.bottom-nav-item', tabbar).forEach(function (o) { o.classList.remove('active'); }); a.classList.add('active'); placeOn(a); });
    });
  }

  // ── Top bar hides while scrolling down on phones, returns when scrolling up
  var nav = document.getElementById('navbar'), lastY = window.scrollY;
  if (nav && !reduce) {
    window.addEventListener('scroll', function () {
      var y = window.scrollY;
      if (window.innerWidth < 768 && y > 120 && y > lastY + 6) nav.classList.add('lh-hide');
      else if (y < lastY - 6 || y < 120) nav.classList.remove('lh-hide');
      lastY = y;
    }, { passive: true });
  }

  // ── Countdown ─────────────────────────────────────────────────────────────
  var bnDigits = '০১২৩৪৫৬৭৮৯';
  function digits(n) {
    var s = String(n).padStart(2, '0');
    return lang() === 'bn' ? s.replace(/\d/g, function (d) { return bnDigits[d]; }) : s;
  }
  var cds = $$('[data-countdown]').map(function (el) {
    return { end: new Date(el.getAttribute('data-countdown')).getTime(),
             d: el.querySelector('[data-d]'), h: el.querySelector('[data-h]'), m: el.querySelector('[data-m]'), s: el.querySelector('[data-s]') };
  }).filter(function (c) { return !isNaN(c.end); });
  function drawCountdowns() {
    cds.forEach(function (c) {
      var t = Math.max(0, Math.floor((c.end - Date.now()) / 1000));
      c.d.textContent = digits(Math.floor(t / 86400));
      c.h.textContent = digits(Math.floor(t % 86400 / 3600));
      c.m.textContent = digits(Math.floor(t % 3600 / 60));
      c.s.textContent = digits(t % 60);
    });
  }
  if (cds.length) { drawCountdowns(); setInterval(drawCountdowns, 1000); }
})();
