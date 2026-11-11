/**
 * University Admission Tracker — live countdown timers.
 * Each .uni-countdown[data-countdown] element counts down to data-target
 * (an ISO 8601 datetime with an explicit +06:00 offset, see to_dhaka_iso()
 * in includes/functions.php), rendered against the visitor's own local
 * clock so the remaining time is always correct regardless of timezone.
 */
'use strict';

(function () {
  function formatRemaining(ms) {
    const totalSeconds = Math.floor(ms / 1000);
    const days    = Math.floor(totalSeconds / 86400);
    const hours   = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    if (days > 0) return `${days}d ${hours}h ${minutes}m left`;
    if (hours > 0) return `${hours}h ${minutes}m ${seconds}s left`;
    if (minutes > 0) return `${minutes}m ${seconds}s left`;
    return `${seconds}s left`;
  }

  function tick(el) {
    const targetStr = el.dataset.target;
    const timerEl = el.querySelector('.uni-countdown-timer');
    if (!targetStr || !timerEl) return;

    const target = new Date(targetStr).getTime();
    const now = Date.now();
    const diff = target - now;

    if (isNaN(target)) { timerEl.textContent = ''; return; }

    if (diff <= 0) {
      timerEl.textContent = 'Deadline passed';
      el.classList.remove('is-urgent');
      el.classList.add('is-expired');
      return false; // signal: stop ticking this one
    }

    timerEl.textContent = formatRemaining(diff);
    el.classList.toggle('is-urgent', diff <= 24 * 3600 * 1000);
    return true;
  }

  function init() {
    const elements = Array.from(document.querySelectorAll('.uni-countdown[data-countdown]'));
    if (!elements.length) return;

    let active = elements.filter(tick);

    const interval = setInterval(() => {
      active = active.filter(tick);
      if (!active.length) clearInterval(interval);
    }, 1000);
  }

  // "Share" button — copies a deep link straight to this university's card
  // (#university-<id>) so it works for guests with no account to save to.
  function initShare() {
    document.addEventListener('click', function (e) {
      const btn = e.target.closest('.uni-share-btn');
      if (!btn) return;
      const url = btn.dataset.url;
      if (!url) return;

      const showCopied = () => {
        const original = btn.innerHTML;
        btn.innerHTML = '<span class="material-icons-round" style="font-size:16px">check</span> Copied!';
        btn.disabled = true;
        setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 1800);
      };

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(showCopied).catch(() => {});
      } else {
        const tmp = document.createElement('input');
        tmp.value = url;
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand('copy');
        document.body.removeChild(tmp);
        showCopied();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
  initShare();
})();
