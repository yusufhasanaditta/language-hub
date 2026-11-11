<?php
defined('B_ACADEMY') or die('Direct access forbidden.');
track_page_view();
$_user      = current_user();
if ($_user) { process_due_schedule_reminders((int)$_user['id']); }
$_flashMsgs = get_flash();
$_notifCount = $_user ? (int)db_val("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL", [$_user['id']]) : 0;
?>
<!DOCTYPE html>
<html lang="bn" data-lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        // Apply saved theme + language before first paint (no flash).
        (function () {
            var d = document.documentElement, s = {}; d.classList.add('lh-js');
            try { s.t = localStorage.getItem('lh_theme'); s.l = localStorage.getItem('lh_lang'); } catch (e) {}
            var dark = s.t ? s.t === 'dark' : (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
            d.setAttribute('data-theme', dark ? 'dark' : 'light');
            if (s.l === 'en' || s.l === 'bn') { d.setAttribute('data-lang', s.l); d.lang = s.l; }
        })();
    </script>
    <meta name="description" content="<?= e($page_desc ?? get_setting('site_description')) ?>">
    <meta name="theme-color" content="#5B21B6">
    <title><?= e($page_title ?? APP_NAME) ?> <?= isset($page_title) ? '— ' . APP_NAME : '' ?></title>

    <!-- Favicon -->
    <link rel="icon" href="<?= APP_URL ?>/image/language-hub-logo-192.png" type="image/png">

    <!-- Social share preview (Open Graph / Twitter Card) — every page gets a
         preview image when its link is shared; pages with their own feature
         image (a course thumbnail, a teacher's photo, etc.) set $page_image
         before including this header, otherwise it falls back to our logo. -->
    <?php
        $_shareTitle = $page_title ?? APP_NAME;
        $_shareDesc  = $page_desc ?? get_setting('site_description');
        $_shareImage = (!empty($page_image) && is_own_asset_url($page_image)) ? $page_image : default_share_image();
        $_shareUrl   = APP_URL . ($_SERVER['REQUEST_URI'] ?? '/');
    ?>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e(APP_NAME) ?>">
    <meta property="og:title" content="<?= e($_shareTitle) ?>">
    <meta property="og:description" content="<?= e($_shareDesc) ?>">
    <meta property="og:image" content="<?= e($_shareImage) ?>">
    <meta property="og:image:secure_url" content="<?= e($_shareImage) ?>">
    <meta property="og:url" content="<?= e($_shareUrl) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($_shareTitle) ?>">
    <meta name="twitter:description" content="<?= e($_shareDesc) ?>">
    <meta name="twitter:image" content="<?= e($_shareImage) ?>">

    <!-- Preconnect -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- LCP image: hero slide 1 loads eager, so warn the browser about it before CSS/fonts are even parsed -->
    <?php if (!empty($featuredCourses[0])): ?>
    <link rel="preload" as="image" href="<?= e(course_thumbnail_url($featuredCourses[0]['thumbnail'] ?? '', $featuredCourses[0]['title_bn'] ?? '', '480x270', '7C3AED')) ?>" fetchpriority="high">
    <?php endif; ?>

    <!-- Main CSS: render-blocking, kept minimal + first (needed for above-the-fold layout) -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
    <!-- App layer: responsive shell + new components (must load after main.css) -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
    <!-- Language Hub design layer: tokens, dark mode, nav, home (loads last) -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/lh.css?v=<?= filemtime(APP_ROOT . '/assets/css/lh.css') ?>">

    <!-- Google Fonts + Material Icons: loaded non-render-blocking (preload + swap on load).
         These were blocking first paint on every page for a webfont that isn't needed
         to render the initial layout — noscript fallback keeps them working with JS off. -->
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@400;500;600;700;800&family=Hind+Siliguri:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" onload="this.onload=null;this.rel='stylesheet'">
    <link rel="preload" as="style" href="https://fonts.googleapis.com/icon?family=Material+Icons+Round&display=swap" onload="this.onload=null;this.rel='stylesheet'">
    <noscript>
        <link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@400;500;600;700;800&family=Hind+Siliguri:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round&display=swap" rel="stylesheet">
    </noscript>

    <?= $extra_css ?? '' ?>

    <script>
        const APP_URL    = '<?= APP_URL ?>';
        const CSRF_TOKEN = '<?= csrf_token() ?>';
        const IS_LOGGED_IN = <?= is_logged_in() ? 'true' : 'false' ?>;
        const VAPID_PUBLIC_KEY = '<?= defined('VAPID_PUBLIC_KEY') ? VAPID_PUBLIC_KEY : '' ?>';
    </script>
    <?php if (APP_ENV !== 'production'): ?>
    <script>
        // Dev-only error reporting. In production this stayed on and fired a
        // network beacon for EVERY console.log — a real cause of page stutter.
        window.onerror = function(message, source, lineno, colno, error) {
            navigator.sendBeacon(APP_URL + '/api/log_error.php', JSON.stringify({
                type: 'error',
                message: message,
                source: source,
                lineno: lineno,
                colno: colno,
                error: error ? error.stack : ''
            }));
        };
        const _error = console.error;
        console.error = function(...args) {
            _error.apply(console, args);
            navigator.sendBeacon(APP_URL + '/api/log_error.php', JSON.stringify({
                type: 'console_error',
                message: args.map(a => typeof a === 'object' ? JSON.stringify(a) : String(a)).join(' ')
            }));
        };
    </script>
    <?php endif; ?>

    <?php
    // Tracking IDs come from Admin → Settings (meta_pixel_id, ga4_id); nothing
    // loads until they are set. (The copied B Academy pixel ID was removed.)
    $_pixelId = preg_replace('/\D/', '', (string) get_setting('meta_pixel_id', ''));
    $_ga4Id   = preg_replace('/[^A-Z0-9-]/', '', strtoupper((string) get_setting('ga4_id', '')));
    ?>
    <?php if ($_ga4Id): ?>
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= $_ga4Id ?>"></script>
    <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?= $_ga4Id ?>');</script>
    <?php endif; ?>
    <?php if ($_pixelId): ?>
    <!-- Meta Pixel Code -->
    <script>
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '<?= $_pixelId ?>');
    fbq('track', 'PageView');
    </script>
    <noscript><img height="1" width="1" style="display:none"
    src="https://www.facebook.com/tr?id=<?= $_pixelId ?>&ev=PageView&noscript=1"
    /></noscript>
    <!-- End Meta Pixel Code -->
    <?php endif; ?>
</head>
<body class="<?= e($body_class ?? '') ?>">

<!-- Flash Messages -->
<?php if (!empty($_flashMsgs)): ?>
<div class="flash-container" id="flashContainer">
    <?php foreach ($_flashMsgs as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>" role="alert">
        <span class="material-icons-round">
            <?= match($f['type']) { 'success' => 'check_circle', 'error' => 'error', 'warning' => 'warning', default => 'info' } ?>
        </span>
        <span><?= e($f['message']) ?></span>
        <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Close">✕</button>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Navigation -->
<nav class="navbar" id="navbar">
    <div class="container navbar-inner">

        <!-- Logo -->
        <a href="<?= APP_URL ?>/" class="navbar-brand lh-brand">
            <img src="<?= APP_URL ?>/image/language-hub-logo.png" alt="" width="40" height="40">
            <span class="brand-text">
                <span class="brand-name">Language Hub</span>
                <span class="brand-tagline"><?= e(APP_TAGLINE) ?></span>
            </span>
        </a>

        <!-- Primary Nav (desktop) -->
        <?php
        $cats = db_query("SELECT * FROM categories ORDER BY sort_order");
        $_glyph = ['chinese' => '中', 'japanese' => 'あ', 'korean' => '한', 'english' => 'A'];
        ?>
        <div class="navbar-categories lh-nav">
            <a href="<?= APP_URL ?>/courses" class="nav-cat"><?= t('কোর্স', 'Courses') ?></a>
            <div class="nav-cat-dropdown">
                <button class="nav-cat" type="button">
                    <?= t('ভাষা', 'Languages') ?> <span class="material-icons-round" style="font-size:16px">expand_more</span>
                </button>
                <div class="dropdown-menu nav-categories-drop lh-lang-drop" style="display:none">
                    <?php foreach ($cats as $cat): ?>
                    <a href="<?= APP_URL ?>/category/<?= e($cat['slug']) ?>" class="dropdown-item" style="--lang:<?= e($cat['color']) ?>">
                        <span class="lh-glyph"><?= e($_glyph[$cat['slug']] ?? mb_substr($cat['name_en'] ?? 'L', 0, 1)) ?></span>
                        <?= t($cat['name_bn'], $cat['name_en'] ?: $cat['name_bn']) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <a href="<?= APP_URL ?>/courses?type=live" class="nav-cat"><?= t('লাইভ ব্যাচ', 'Live batches') ?></a>
            <a href="<?= APP_URL ?>/courses?free=1" class="nav-cat"><?= t('ফ্রি', 'Free') ?></a>
            <a href="<?= APP_URL ?>/teachers" class="nav-cat"><?= t('শিক্ষক', 'Teachers') ?></a>
        </div>

        <!-- Right side -->
        <div class="navbar-right">
            <button class="icon-btn lh-toggle" type="button" data-lh-lang aria-label="বাংলা / English">
                <span class="lh-toggle-txt"><span class="i18n" lang="bn">EN</span><span class="i18n" lang="en">বাং</span></span>
            </button>
            <button class="icon-btn lh-toggle" type="button" data-lh-theme aria-label="Dark mode">
                <span class="material-icons-round lh-ico-dark">dark_mode</span>
                <span class="material-icons-round lh-ico-light">light_mode</span>
            </button>
            <!-- Search toggle -->
            <button class="icon-btn d-none d-md-flex" id="searchToggle" aria-label="Search">
                <span class="material-icons-round">search</span>
            </button>

            <?php if ($_user): ?>
            <!-- Notifications -->
            <div class="notif-wrap">
                <button class="icon-btn" id="notifBtn" aria-label="Notifications">
                    <span class="material-icons-round">notifications</span>
                    <span class="badge badge-notif" id="notifBadge" <?= $_notifCount > 0 ? '' : 'style="display:none"' ?>><?= $_notifCount > 9 ? '9+' : $_notifCount ?></span>
                </button>
                <div class="dropdown-menu notif-dropdown" id="notifDropdown">
                    <div class="dropdown-header" style="flex-direction:row;align-items:center;justify-content:space-between">
                        <span>Notifications</span>
                        <button type="button" id="notifMarkAllRead" style="background:none;border:none;color:var(--c-primary);font-size:var(--text-xs);font-weight:700;cursor:pointer;padding:0">Mark all read</button>
                    </div>
                    <div id="notifList">
                    <?php
                    $notifs = db_query("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 8", [$_user['id']]);
                    if (empty($notifs)):
                    ?>
                    <div class="dropdown-empty">No notifications</div>
                    <?php else: foreach ($notifs as $n): ?>
                    <a href="<?= e($n['action_url'] ?? '#') ?>" class="notif-item <?= $n['read_at'] ? '' : 'unread' ?>" data-notif-id="<?= (int) $n['id'] ?>">
                        <span class="notif-title"><?= e($n['title']) ?></span>
                        <span class="notif-time"><?= time_ago($n['created_at']) ?></span>
                    </a>
                    <?php endforeach; endif; ?>
                    </div>
                    <div style="border-top:1px solid var(--c-border);padding:var(--sp-2)">
                        <a href="<?= APP_URL ?>/notifications.php" class="dropdown-item" style="justify-content:center;font-weight:700;color:var(--c-primary);font-size:var(--text-sm)">
                            <span class="material-icons-round" style="font-size:16px">notifications</span> View All Notifications
                        </a>
                    </div>
                </div>
            </div>

            <!-- User menu -->
            <div class="user-menu-wrap">
                <button class="user-menu-btn" id="userMenuBtn">
                    <img src="<?= e(avatar_url($_user['avatar'], $_user['name'])) ?>" alt="<?= e($_user['name']) ?>" class="user-avatar-sm">
                    <span class="user-name-sm d-none d-md-inline"><?= e(explode(' ', $_user['name'])[0] ?? $_user['name']) ?></span>
                    <span class="material-icons-round">expand_more</span>
                </button>
                <div class="dropdown-menu user-dropdown" id="userDropdown" style="max-height: 80vh; overflow-y: auto;">
                    <div class="dropdown-header">
                        <strong><?= e($_user['name']) ?></strong>
                        <small><?= e($_user['email'] ?? $_user['phone']) ?></small>
                    </div>
                    <a href="<?= APP_URL ?>/dashboard.php" class="dropdown-item">
                        <span class="material-icons-round">person</span> <?= t('আমার প্রোফাইল', 'My profile') ?>
                    </a>
                    <a href="<?= APP_URL ?>/my-courses.php" class="dropdown-item">
                        <span class="material-icons-round">play_circle</span> <?= t('আমার কোর্স', 'My courses') ?>
                    </a>
                    <a href="<?= APP_URL ?>/orders.php" class="dropdown-item">
                        <span class="material-icons-round">receipt_long</span> <?= t('পেমেন্ট ও ইনভয়েস', 'Payments & invoices') ?>
                    </a>
                    <a href="<?= APP_URL ?>/certificates.php" class="dropdown-item">
                        <span class="material-icons-round">workspace_premium</span> <?= t('সার্টিফিকেট', 'Certificates') ?>
                    </a>
                    <a href="<?= APP_URL ?>/notifications.php" class="dropdown-item">
                        <span class="material-icons-round">notifications</span> <?= t('নোটিফিকেশন', 'Notifications') ?>
                    </a>
                    <a href="<?= APP_URL ?>/profile.php#edit-profile" class="dropdown-item">
                        <span class="material-icons-round">manage_accounts</span> <?= t('প্রোফাইল এডিট', 'Edit profile') ?>
                    </a>
                    <?php if (is_admin()): ?>
                    <a href="<?= APP_URL ?>/admin/" class="dropdown-item">
                        <span class="material-icons-round">admin_panel_settings</span> Admin Panel
                    </a>
                    <?php endif; ?>
                    <?php if (is_teacher()): ?>
                    <a href="<?= APP_URL ?>/teacher/dashboard" class="dropdown-item">
                        <span class="material-icons-round">cast_for_education</span> Teacher Panel
                    </a>
                    <?php endif; ?>
                    <div class="dropdown-divider"></div>
                    <a href="<?= APP_URL ?>/help" class="dropdown-item">
                        <span class="material-icons-round">help</span> Help Center
                    </a>
                    <a href="<?= APP_URL ?>/logout?token=<?= csrf_token() ?>" class="dropdown-item text-danger">
                        <span class="material-icons-round">logout</span> Logout
                    </a>
                </div>
            </div>

            <?php else: ?>
            <a href="<?= APP_URL ?>/login.php" class="btn btn-primary btn-sm lh-nav-cta"><?= t('শুরু করুন', 'Get started') ?></a>
            <?php endif; ?>
            <!-- Mobile menu toggle (every visitor gets it — students used to have
                 no menu at all on phones, only the 3-item bottom bar) -->
            <button class="icon-btn d-md-none" id="mobileMenuToggle" aria-label="Menu" aria-controls="mobileNav" aria-expanded="false" style="display:none !important">
                <span class="material-icons-round">menu</span>
            </button>
        </div>
    </div>

    <!-- Search bar (expandable) -->
    <div class="search-bar-wrap" id="searchBarWrap">
        <div class="container">
            <form action="<?= APP_URL ?>/courses" method="GET" class="search-bar-form">
                <span class="material-icons-round search-icon">search</span>
                <input type="search" name="q" placeholder="Search courses, teachers, or subjects..." class="search-input" id="searchInput" autocomplete="off">
                <button type="button" class="icon-btn" id="searchClose">
                    <span class="material-icons-round">close</span>
                </button>
            </form>
        </div>
    </div>

    <!-- Mobile nav drawer -->
    <div class="mobile-nav" id="mobileNav" aria-hidden="true">
        <div class="drawer-head">
            <span class="drawer-title"><?= $_user ? e(explode(' ', $_user['name'])[0]) : 'Menu' ?></span>
            <button class="icon-btn drawer-close" aria-label="Close menu">
                <span class="material-icons-round">close</span>
            </button>
        </div>
        <div class="mobile-nav-inner">
            <a href="<?= APP_URL ?>/" class="mobile-nav-link">
                <span class="material-icons-round">home</span> Home
            </a>
            <a href="<?= APP_URL ?>/notices" class="mobile-nav-link">
                <span class="material-icons-round">campaign</span> Notice Board
            </a>
            <a href="<?= APP_URL ?>/courses" class="mobile-nav-link">
                <span class="material-icons-round">school</span> All Courses
            </a>
            <a href="<?= APP_URL ?>/courses?free=1" class="mobile-nav-link">
                <span class="material-icons-round">card_giftcard</span> Free Courses
            </a>
            <a href="<?= APP_URL ?>/courses?type=live" class="mobile-nav-link">
                <span class="material-icons-round">videocam</span> Live Batches
            </a>
            <div class="mobile-nav-divider"></div>
            <?php foreach ($cats as $cat): ?>
            <a href="<?= APP_URL ?>/category/<?= e($cat['slug']) ?>" class="mobile-nav-link" style="--lang:<?= e($cat['color']) ?>">
                <span class="lh-glyph"><?= e($_glyph[$cat['slug']] ?? mb_substr($cat['name_en'] ?? 'L', 0, 1)) ?></span>
                <?= t($cat['name_bn'], $cat['name_en'] ?: $cat['name_bn']) ?>
            </a>
            <?php endforeach; ?>
            <div class="mobile-nav-divider"></div>
            <?php if ($_user): ?>
            <a href="<?= APP_URL ?>/dashboard.php" class="mobile-nav-link">
                <span class="material-icons-round">person</span> <?= t('আমার প্রোফাইল', 'My profile') ?>
            </a>
            <a href="<?= APP_URL ?>/my-courses.php" class="mobile-nav-link">
                <span class="material-icons-round">play_circle</span> <?= t('আমার কোর্স', 'My courses') ?>
            </a>
            <a href="<?= APP_URL ?>/orders.php" class="mobile-nav-link">
                <span class="material-icons-round">receipt_long</span> <?= t('পেমেন্ট ও ইনভয়েস', 'Payments & invoices') ?>
            </a>
            <a href="<?= APP_URL ?>/certificates.php" class="mobile-nav-link">
                <span class="material-icons-round">workspace_premium</span> <?= t('সার্টিফিকেট', 'Certificates') ?>
            </a>
            <a href="<?= APP_URL ?>/notifications.php" class="mobile-nav-link">
                <span class="material-icons-round">notifications</span> <?= t('নোটিফিকেশন', 'Notifications') ?>
            </a>
            <a href="<?= APP_URL ?>/profile.php#edit-profile" class="mobile-nav-link">
                <span class="material-icons-round">manage_accounts</span> <?= t('প্রোফাইল এডিট', 'Edit profile') ?>
            </a>
            <?php if (is_teacher()): ?>
            <a href="<?= APP_URL ?>/teacher/dashboard" class="mobile-nav-link">
                <span class="material-icons-round">cast_for_education</span> Teacher Panel
            </a>
            <?php endif; ?>
            <?php if (is_admin()): ?>
            <a href="<?= APP_URL ?>/admin/" class="mobile-nav-link">
                <span class="material-icons-round">admin_panel_settings</span> Admin Panel
            </a>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/help" class="mobile-nav-link">
                <span class="material-icons-round">help</span> Help Center
            </a>
            <a href="<?= APP_URL ?>/logout?token=<?= csrf_token() ?>" class="mobile-nav-link text-danger">
                <span class="material-icons-round">logout</span> Logout
            </a>
            <?php else: ?>
            <a href="<?= APP_URL ?>/login" class="mobile-nav-link">
                <span class="material-icons-round">login</span> Login
            </a>
            <a href="<?= APP_URL ?>/register" class="mobile-nav-link" style="font-weight:700">
                <span class="material-icons-round">person_add</span> Sign Up
            </a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<!-- Overlay for dropdowns/drawers -->
<div class="overlay" id="overlay"></div>

<!-- Main content starts -->
<main class="main-content" id="mainContent">
<?php

// Guarded: includes/notice_lib.php defines the same helper for AJAX endpoints
// that never load this header.
if (!function_exists('time_ago')) {
    function time_ago(string $datetime): string {
        $diff = time() - strtotime($datetime);
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return intdiv($diff, 60) . ' min ago';
        if ($diff < 86400) return intdiv($diff, 3600) . ' hr ago';
        if ($diff < 604800) return intdiv($diff, 86400) . ' days ago';
        return date('d M Y', strtotime($datetime));
    }
}
?>
