<?php
// Minimal shell for the login / sign-up pages: logo, one centered card.
defined('B_ACADEMY') or die('Direct access forbidden.');

function auth_open(string $title): void { ?>
<!DOCTYPE html>
<html lang="bn" data-lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= e($title) ?> — <?= APP_NAME ?></title>
    <script>
        (function () {
            var d = document.documentElement, t, l;
            try { t = localStorage.getItem('lh_theme'); l = localStorage.getItem('lh_lang'); } catch (e) {}
            var dark = t ? t === 'dark' : (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
            d.setAttribute('data-theme', dark ? 'dark' : 'light');
            if (l === 'en' || l === 'bn') { d.setAttribute('data-lang', l); d.lang = l; }
        })();
    </script>
    <link rel="icon" href="<?= APP_URL ?>/image/language-hub-logo-192.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@600;700;800&family=Hind+Siliguri:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/lh.css?v=<?= filemtime(APP_ROOT . '/assets/css/lh.css') ?>">
</head>
<body class="lh-auth-body">
<main class="lh-auth">
    <a href="<?= APP_URL ?>/" class="lh-brand lh-auth-brand">
        <img src="<?= APP_URL ?>/image/language-hub-logo.png" alt="" width="40" height="40">
        <span class="brand-name">Language Hub</span>
    </a>
    <div class="lh-auth-card">
<?php }

function auth_close(): void { ?>
    </div>
    <div class="lh-auth-tools">
        <button type="button" class="lh-toggle" data-lh-lang aria-label="বাংলা / English"><span class="lh-toggle-txt"><span class="i18n" lang="bn">English</span><span class="i18n" lang="en">বাংলা</span></span></button>
        <span aria-hidden="true">·</span>
        <button type="button" class="lh-toggle" data-lh-theme aria-label="Dark mode">
            <span class="material-icons-round lh-ico-dark">dark_mode</span><span class="material-icons-round lh-ico-light">light_mode</span>
        </button>
    </div>
</main>
<script>
(function () {
  // Code boxes mirror one real input (keeps paste + SMS/email autofill working)
  var code = document.getElementById("code");
  if (code) {
    var boxes = document.querySelectorAll(".lh-code-boxes span");
    var paint = function () {
      code.value = code.value.replace(/\D/g, "").slice(0, boxes.length);
      boxes.forEach(function (b, i) {
        b.textContent = code.value[i] || "";
        b.classList.toggle("is-filled", i < code.value.length);
        b.classList.toggle("is-active", document.activeElement === code && i === Math.min(code.value.length, boxes.length - 1));
      });
      if (code.value.length === boxes.length) document.getElementById("codeForm").requestSubmit();
    };
    ["input", "focus", "blur"].forEach(function (ev) { code.addEventListener(ev, paint); });
    paint();
  }
  // Resend countdown
  var rb = document.getElementById("resendBtn");
  if (rb) {
    var label = rb.innerHTML, left = +rb.getAttribute("data-wait");
    rb.disabled = true;
    var t = setInterval(function () {
      left--; rb.textContent = (document.documentElement.lang === "en" ? "Resend in " : "আবার পাঠান ") + left + "s";
      if (left <= 0) { clearInterval(t); rb.disabled = false; rb.innerHTML = label; }
    }, 1000);
  }
  // Busy state on submit
  document.querySelectorAll("form").forEach(function (f) {
    f.addEventListener("submit", function () {
      var b = f.querySelector("[data-busy]"); if (b) { b.classList.add("is-busy"); setTimeout(function(){ b.disabled = true; }, 0); }
    });
  });
})();
</script>
<script src="<?= APP_URL ?>/assets/js/lh.js?v=<?= filemtime(APP_ROOT . '/assets/js/lh.js') ?>" defer></script>
</body>
</html>
<?php }

