<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_login();

$user = current_user();
$uid  = (int)$user['id'];

// Mark all as read on page visit
db_exec("UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL", [$uid]);

$page  = max(1, sanitize_int($_GET['page'] ?? 1));
$per   = 20;
$total = (int)db_val("SELECT COUNT(*) FROM notifications WHERE user_id=?", [$uid]);
$pages = max(1, (int)ceil($total / $per));
$offset = ($page - 1) * $per;

$notifs = db_query("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT $per OFFSET $offset", [$uid]);

$page_title = 'All Notifications';
include __DIR__ . '/includes/header.php';
?>

<div style="background:linear-gradient(135deg,#0F1A3E 0%,#1C0B52 100%);padding:var(--sp-10) 0">
    <div class="container">
        <h1 style="color:#fff;font-size:clamp(1.4rem,3vw,2rem);margin-bottom:4px">Notification Board</h1>
        <p style="color:rgba(255,255,255,.6);font-size:var(--text-sm)">All your notifications and updates</p>
    </div>
</div>

<div class="container" style="padding-top:var(--sp-8);padding-bottom:var(--sp-16);max-width:720px">

    <?php if (empty($notifs)): ?>
    <div style="text-align:center;padding:var(--sp-20);background:var(--c-surface);border-radius:var(--r-2xl);border:2px dashed var(--c-border)">
        <span class="material-icons-round" style="font-size:64px;color:var(--c-ink-faint);display:block;margin-bottom:16px">notifications_none</span>
        <h3 style="color:var(--c-ink-light)">No notifications</h3>
        <p style="color:var(--c-ink-muted)">Notifications about new courses, exams, or updates will show here.</p>
    </div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:var(--sp-2)">
        <?php foreach ($notifs as $n): ?>
        <a href="<?= e($n['action_url'] ?? '#') ?>"
           style="display:flex;gap:var(--sp-4);align-items:flex-start;padding:var(--sp-4) var(--sp-5);background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--r-lg);text-decoration:none;transition:box-shadow .2s"
           onmouseover="this.style.boxShadow='var(--shadow-sm)'" onmouseout="this.style.boxShadow=''">
            <span class="material-icons-round" style="font-size:22px;color:var(--c-primary);flex-shrink:0;margin-top:2px">
                <?= match($n['kind'] ?? 'general') {
                    'exam'    => 'quiz',
                    'course'  => 'school',
                    'message' => 'chat',
                    'payment' => 'receipt',
                    default   => 'notifications'
                } ?>
            </span>
            <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:var(--text-sm);color:var(--c-ink);margin-bottom:2px"><?= e($n['title']) ?></div>
                <?php if (!empty($n['body'])): ?>
                <div style="font-size:var(--text-xs);color:var(--c-ink-muted);line-height:1.6"><?= e(mb_substr($n['body'], 0, 150, 'UTF-8')) ?></div>
                <?php endif; ?>
                <div style="font-size:11px;color:var(--c-ink-faint);margin-top:4px"><?= time_ago($n['created_at']) ?></div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
    <div style="display:flex;justify-content:center;gap:var(--sp-2);margin-top:var(--sp-8);flex-wrap:wrap">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a href="?page=<?= $i ?>"
           style="width:40px;height:40px;display:flex;align-items:center;justify-content:center;border-radius:var(--r-md);font-weight:700;text-decoration:none;font-family:var(--font-latin);<?= $i === $page ? 'background:var(--c-primary);color:#fff' : 'background:var(--c-surface-2);color:var(--c-ink-muted)' ?>">
            <?= $i ?>
        </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
