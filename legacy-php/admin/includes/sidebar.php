<?php
// Admin sidebar — included by admin pages
$currentPage = basename($_SERVER['PHP_SELF']);
$_sb_user = current_user();
$navItems = [
    ['url'=>APP_URL.'/admin/', 'icon'=>'dashboard', 'label'=>'Dashboard', 'file'=>'index.php'],
    ['url'=>APP_URL.'/admin/analytics.php', 'icon'=>'insights', 'label'=>'Site Analytics', 'file'=>'analytics.php'],
    null, // section
    ['url'=>APP_URL.'/admin/categories.php', 'icon'=>'category', 'label'=>'Categories', 'file'=>'categories.php'],
    ['url'=>APP_URL.'/admin/courses.php', 'icon'=>'play_circle', 'label'=>'Courses', 'file'=>'courses.php'],
    ['url'=>APP_URL.'/admin/course_sections.php', 'icon'=>'dashboard_customize', 'label'=>'Course Page Builder', 'file'=>'course_sections.php'],
    ['url'=>APP_URL.'/admin/exams.php', 'icon'=>'quiz', 'label'=>'Exams', 'file'=>'exams.php'],
    ['url'=>APP_URL.'/admin/schedules.php', 'icon'=>'event', 'label'=>'Schedules', 'file'=>'schedules.php'],
    ['url'=>APP_URL.'/admin/teachers.php', 'icon'=>'school', 'label'=>'Teachers', 'file'=>'teachers.php'],
    ['url'=>APP_URL.'/admin/universities.php', 'icon'=>'account_balance', 'label'=>'Admission Tracker', 'file'=>'universities.php'],
    null,
    ['url'=>APP_URL.'/admin/users.php', 'icon'=>'people', 'label'=>'Students', 'file'=>'users.php'],
    ['url'=>APP_URL.'/admin/enrollments.php', 'icon'=>'how_to_reg', 'label'=>'Enrollments', 'file'=>'enrollments.php'],
    null,
    ['url'=>APP_URL.'/admin/orders.php', 'icon'=>'receipt', 'label'=>'Orders', 'file'=>'orders.php'],
    ['url'=>APP_URL.'/admin/coupons.php', 'icon'=>'discount', 'label'=>'Coupons', 'file'=>'coupons.php'],
    null,
    ['url'=>APP_URL.'/notices.php', 'icon'=>'campaign', 'label'=>'Notice Board', 'file'=>'notices.php'],
    ['url'=>APP_URL.'/admin/stories.php', 'icon'=>'auto_stories', 'label'=>'Success Stories', 'file'=>'stories.php'],
    ['url'=>APP_URL.'/admin/testimonials.php', 'icon'=>'format_quote', 'label'=>'Home Testimonials', 'file'=>'testimonials.php'],
    ['url'=>APP_URL.'/admin/messages.php', 'icon'=>'forum', 'label'=>'Message Monitor', 'file'=>'messages.php'],
    ['url'=>APP_URL.'/admin/support.php', 'icon'=>'support_agent', 'label'=>'Support Tickets', 'file'=>'support.php'],
    ['url'=>APP_URL.'/admin/contact_messages.php', 'icon'=>'mail', 'label'=>'Contact Messages', 'file'=>'contact_messages.php'],
    null,
    ['url'=>APP_URL.'/admin/settings.php', 'icon'=>'settings', 'label'=>'Settings', 'file'=>'settings.php'],
];
?>
<!-- Mobile top bar: the sidebar is hidden under 1024px, which used to leave
     the admin panel with no navigation at all on phones/tablets. -->
<header class="panel-topbar">
    <button class="icon-btn" id="panelNavToggle" aria-label="Menu" aria-controls="adminSidebar">
        <span class="material-icons-round">menu</span>
    </button>
    <a href="<?= APP_URL ?>/admin/" class="panel-topbar-brand">
        <img src="<?= APP_URL ?>/image/language-hub-logo.png" alt="">
        <span>Admin</span>
    </a>
    <a href="<?= APP_URL ?>/" class="icon-btn" aria-label="Visit site">
        <span class="material-icons-round">open_in_new</span>
    </a>
</header>

<aside class="admin-sidebar" id="adminSidebar">
    <div class="admin-logo">
        <a href="<?= APP_URL ?>/admin/" style="display:flex;align-items:center;gap:10px;text-decoration:none">
            <img src="<?= APP_URL ?>/image/language-hub-logo.png" alt="Logo" style="height:32px; width:auto; object-fit:contain; filter:drop-shadow(0 2px 4px rgba(0,0,0,0.1));">
            <span style="font-family:var(--font-display);font-weight:800;color:#fff;font-size:1.2rem;background:linear-gradient(135deg, #fff, #ddd);-webkit-background-clip:text;-webkit-text-fill-color:transparent;">Language Hub</span>
        </a>
    </div>

    <!-- Profile dropdown — mirrors the nav items above -->
    <div class="user-menu-wrap" style="padding:0 var(--sp-6) var(--sp-4);border-bottom:1px solid rgba(255,255,255,.08);margin-bottom:var(--sp-4)">
        <button class="user-menu-btn" id="userMenuBtn" style="width:100%;border-color:rgba(255,255,255,.2);color:#fff">
            <img src="<?= e(avatar_url($_sb_user['avatar'] ?? null, $_sb_user['name'] ?? 'Admin')) ?>" alt="" class="user-avatar-sm">
            <span class="user-name-sm" style="color:#fff;flex:1;text-align:left;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($_sb_user['name'] ?? 'Admin') ?></span>
            <span class="material-icons-round">expand_more</span>
        </button>
        <div class="dropdown-menu sidebar-user-dropdown" id="userDropdown">
            <div class="dropdown-header">
                <strong><?= e($_sb_user['name'] ?? 'Admin') ?></strong>
                <small><?= e($_sb_user['email'] ?? $_sb_user['phone'] ?? '') ?></small>
            </div>
            <?php foreach ($navItems as $item): if ($item === null) continue; ?>
            <a href="<?= e($item['url']) ?>" class="dropdown-item">
                <span class="material-icons-round"><?= e($item['icon']) ?></span> <?= e($item['label']) ?>
            </a>
            <?php endforeach; ?>
            <div class="dropdown-divider"></div>
            <a href="<?= APP_URL ?>/" class="dropdown-item">
                <span class="material-icons-round">home</span> Visit Site
            </a>
            <a href="<?= APP_URL ?>/logout.php?token=<?= csrf_token() ?>" class="dropdown-item text-danger">
                <span class="material-icons-round">logout</span> Log Out
            </a>
        </div>
    </div>

    <?php foreach ($navItems as $item):
        if ($item === null): ?>
    <div class="admin-nav-section" style="height:16px"></div>
    <?php else: ?>
    <a href="<?= e($item['url']) ?>"
       class="admin-nav-item <?= $currentPage === $item['file'] ? 'active' : '' ?>">
        <span class="material-icons-round"><?= e($item['icon']) ?></span>
        <?= e($item['label']) ?>
    </a>
    <?php endif; endforeach; ?>
    <div style="padding:var(--sp-4) var(--sp-6);margin-top:32px;border-top:1px solid rgba(255,255,255,.1)">
        <a href="<?= APP_URL ?>/" style="display:flex;align-items:center;gap:8px;color:rgba(255,255,255,.6);font-size:var(--text-sm);text-decoration:none;margin-bottom:8px">
            <span class="material-icons-round" style="font-size:18px">home</span> Visit Site
        </a>
        <a href="<?= APP_URL ?>/logout.php?token=<?= csrf_token() ?>"
           style="display:flex;align-items:center;gap:8px;color:var(--c-error);font-size:var(--text-sm);text-decoration:none">
            <span class="material-icons-round" style="font-size:18px">logout</span> Log Out
        </a>
    </div>
</aside>
