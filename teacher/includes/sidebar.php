<?php
defined('B_ACADEMY') or die('Direct access forbidden.');
$_tp_user = current_user();
$_active  = basename($_SERVER['PHP_SELF']);
?>
<!-- Mobile top bar (sidebar is hidden under 1024px) -->
<header class="panel-topbar">
    <button class="icon-btn" id="panelNavToggle" aria-label="Menu" aria-controls="teacherSidebar">
        <span class="material-icons-round">menu</span>
    </button>
    <a href="<?= APP_URL ?>/teacher/dashboard.php" class="panel-topbar-brand">
        <img src="<?= APP_URL ?>/image/language-hub-logo.png" alt="">
        <span>Teacher</span>
    </a>
    <a href="<?= APP_URL ?>/notices.php" class="icon-btn" aria-label="Notice board">
        <span class="material-icons-round">campaign</span>
    </a>
</header>

<aside class="admin-sidebar" id="teacherSidebar">
    <div class="admin-logo">
        <a href="<?= APP_URL ?>/teacher/dashboard.php" style="display:flex;align-items:center;gap:10px;text-decoration:none">
            <img src="<?= APP_URL ?>/image/language-hub-logo.png" alt="Logo" style="height:32px; width:auto; object-fit:contain; filter:drop-shadow(0 2px 4px rgba(0,0,0,0.1));">
            <div>
                <div style="font-family:var(--font-display);font-weight:800;color:#fff;font-size:1.2rem;background:linear-gradient(135deg, #fff, #ddd);-webkit-background-clip:text;-webkit-text-fill-color:transparent;">Language Hub</div>
                <div style="font-size:10px;color:rgba(255,255,255,.5);letter-spacing:.05em">Teacher Panel</div>
            </div>
        </a>
    </div>

    <!-- Teacher avatar / profile dropdown — mirrors the nav items below -->
    <div class="user-menu-wrap" style="padding:var(--sp-4) var(--sp-6);border-bottom:1px solid rgba(255,255,255,.08);margin-bottom:var(--sp-2)">
        <button class="user-menu-btn" id="userMenuBtn" style="width:100%;border:none;padding:0;background:transparent">
            <img src="<?= e(avatar_url($_tp_user['avatar'], $_tp_user['name'])) ?>"
                 alt="" style="width:40px;height:40px;border-radius:50%;border:2px solid rgba(255,255,255,.3)">
            <div style="flex:1;text-align:left;overflow:hidden">
                <div style="font-weight:700;color:#fff;font-size:var(--text-sm);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($_tp_user['name']) ?></div>
                <div style="font-size:10px;color:rgba(255,255,255,.5)">Teacher</div>
            </div>
            <span class="material-icons-round" style="color:#fff">expand_more</span>
        </button>
        <div class="dropdown-menu sidebar-user-dropdown" id="userDropdown">
            <div class="dropdown-header">
                <strong><?= e($_tp_user['name']) ?></strong>
                <small><?= e($_tp_user['email'] ?? $_tp_user['phone'] ?? '') ?></small>
            </div>
            <a href="<?= APP_URL ?>/teacher/dashboard.php" class="dropdown-item"><span class="material-icons-round">dashboard</span> Dashboard</a>
            <a href="<?= APP_URL ?>/teacher/courses.php" class="dropdown-item"><span class="material-icons-round">play_circle</span> My Courses</a>
            <a href="<?= APP_URL ?>/teacher/schedules.php" class="dropdown-item"><span class="material-icons-round">event</span> Schedules</a>
            <a href="<?= APP_URL ?>/teacher/students.php" class="dropdown-item"><span class="material-icons-round">people</span> Students</a>
            <a href="<?= APP_URL ?>/teacher/discussions.php" class="dropdown-item"><span class="material-icons-round">forum</span> Q&amp;A</a>
            <a href="<?= APP_URL ?>/teacher/feedback.php" class="dropdown-item"><span class="material-icons-round">feedback</span> Send Feedback</a>
            <?php if (!empty($_SESSION['original_admin']) || (int)($_SESSION['user_role'] ?? 0) === ROLE_ADMIN): ?>
            <a href="<?= APP_URL ?>/teacher/analytics.php" class="dropdown-item"><span class="material-icons-round">bar_chart</span> Analytics</a>
            <a href="<?= APP_URL ?>/teacher/earnings.php" class="dropdown-item"><span class="material-icons-round">payments</span> Earnings</a>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/teacher/profile.php" class="dropdown-item"><span class="material-icons-round">person</span> Teacher Profile</a>
            <div class="dropdown-divider"></div>
            <a href="<?= APP_URL ?>/" class="dropdown-item"><span class="material-icons-round">home</span> Visit Site</a>
            <a href="<?= APP_URL ?>/logout.php?token=<?= csrf_token() ?>" class="dropdown-item text-danger"><span class="material-icons-round">logout</span> Logout</a>
        </div>
    </div>

    <div class="admin-nav-section">Main Menu</div>
    <a href="<?= APP_URL ?>/teacher/dashboard.php"
       class="admin-nav-item <?= $_active==='dashboard.php'?'active':'' ?>">
        <span class="material-icons-round">dashboard</span> Dashboard
    </a>

    <div class="admin-nav-section">Courses &amp; Lessons</div>
    <a href="<?= APP_URL ?>/teacher/courses.php"
       class="admin-nav-item <?= $_active==='courses.php'?'active':'' ?>">
        <span class="material-icons-round">play_circle</span> My Courses
    </a>
    <a href="<?= APP_URL ?>/teacher/schedules.php"
       class="admin-nav-item <?= $_active==='schedules.php'?'active':'' ?>">
        <span class="material-icons-round">event</span> Schedules
    </a>

    <div class="admin-nav-section">Students</div>
    <a href="<?= APP_URL ?>/teacher/students.php"
       class="admin-nav-item <?= $_active==='students.php'?'active':'' ?>">
        <span class="material-icons-round">people</span> Students
    </a>
    <a href="<?= APP_URL ?>/teacher/messages.php"
       class="admin-nav-item <?= $_active==='messages.php'?'active':'' ?>">
        <span class="material-icons-round">forum</span> Messages
    </a>
    <a href="<?= APP_URL ?>/teacher/discussions.php"
       class="admin-nav-item <?= $_active==='discussions.php'?'active':'' ?>">
        <span class="material-icons-round">forum</span> Q&amp;A
    </a>
    <a href="<?= APP_URL ?>/teacher/feedback.php"
       class="admin-nav-item <?= $_active==='feedback.php'?'active':'' ?>">
        <span class="material-icons-round">feedback</span> Send Feedback
    </a>

    <?php if (!empty($_SESSION['original_admin']) || (int)($_SESSION['user_role'] ?? 0) === ROLE_ADMIN): ?>
    <div class="admin-nav-section">Analytics (Admin View)</div>
    <a href="<?= APP_URL ?>/teacher/analytics.php"
       class="admin-nav-item <?= $_active==='analytics.php'?'active':'' ?>">
        <span class="material-icons-round">bar_chart</span> Analytics
    </a>
    <a href="<?= APP_URL ?>/teacher/earnings.php"
       class="admin-nav-item <?= $_active==='earnings.php'?'active':'' ?>">
        <span class="material-icons-round">payments</span> Earnings
    </a>
    <?php endif; ?>

    <div class="admin-nav-section">Profile</div>
    <a href="<?= APP_URL ?>/teacher/profile.php"
       class="admin-nav-item <?= $_active==='profile.php'?'active':'' ?>">
        <span class="material-icons-round">person</span> Teacher Profile
    </a>

    <div style="padding:var(--sp-4) var(--sp-6);margin-top:auto;border-top:1px solid rgba(255,255,255,.1)">
        <a href="<?= APP_URL ?>/" class="admin-nav-item" style="padding:0;margin-bottom:8px">
            <span class="material-icons-round">home</span> Visit Site
        </a>
        <a href="<?= APP_URL ?>/logout.php?token=<?= csrf_token() ?>"
           class="admin-nav-item" style="padding:0;color:var(--c-error)">
            <span class="material-icons-round">logout</span> Logout
        </a>
    </div>
    <?php if (!empty($_SESSION['original_admin'])): ?>
    <div style="background:#DC2626;padding:12px 16px;display:flex;align-items:center;gap:8px;font-size:13px;color:#fff">
        <span class="material-icons-round" style="font-size:18px">admin_panel_settings</span>
        <div style="flex:1;min-width:0">
            <div style="font-weight:700;font-size:12px">Logged in as Admin</div>
            <div style="font-size:11px;opacity:.8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($_SESSION['original_admin']['name'] ?? '') ?></div>
        </div>
        <a href="<?= APP_URL ?>/admin/return-to-admin.php" style="background:rgba(255,255,255,.2);color:#fff;border-radius:6px;padding:4px 10px;font-size:12px;font-weight:700;white-space:nowrap;text-decoration:none">Return</a>
    </div>
    <?php endif; ?>
</aside>
