<?php
// Student home ("আমার প্রোফাইল"): next live class, continue learning, links, devices.
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lh_components.php';

session_start_secure();
send_security_headers();
require_login();

$user = current_user();
$uid  = (int)$user['id'];
$currentToken = $_SESSION['_session_token'] ?? '';

// Sign out another device
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && ($_POST['action'] ?? '') === 'kill_session') {
    $sid = sanitize_int($_POST['session_id'] ?? 0);
    $target = db_row("SELECT session_token FROM user_sessions WHERE id=? AND user_id=?", [$sid, $uid]);
    if ($target && $target['session_token'] !== $currentToken) {
        db_exec("DELETE FROM user_sessions WHERE id=? AND user_id=?", [$sid, $uid]);
        flash('success', 'ডিভাইসটি থেকে লগআউট করা হয়েছে।');
    }
    redirect(APP_URL . '/dashboard.php#devices');
}

$courses = db_query("
    SELECT c.*, e.progress_pct, e.enrolled_at, cat.slug AS cat_slug, cat.name_bn AS cat_name, cat.name_en AS cat_name_en
    FROM enrollments e
    JOIN courses c ON c.id = e.course_id
    JOIN categories cat ON cat.id = c.category_id
    WHERE e.user_id = ? AND e.status = 'active'
    ORDER BY e.enrolled_at DESC", [$uid]);
$courseIds = array_map(fn($c) => (int)$c['id'], $courses);

$nextLive = null;
if ($courseIds) {
    $in = implode(',', $courseIds);
    $nextLive = db_row("
        SELECT ls.*, c.title_bn AS course_title, c.title_en AS course_title_en, cat.slug AS cat_slug
        FROM live_sessions ls
        JOIN courses c ON c.id = ls.course_id
        JOIN categories cat ON cat.id = c.category_id
        WHERE ls.course_id IN ($in) AND ls.status IN ('scheduled','live')
          AND ls.scheduled_at >= NOW() - INTERVAL COALESCE(ls.duration_minutes, 90) MINUTE
        ORDER BY ls.scheduled_at LIMIT 1");
}
$devices = get_active_sessions($uid);
$unread = (int)db_val("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL", [$uid]);
$first = explode(' ', trim($user['name']))[0] ?: $user['name'];

$page_title = 'আমার প্রোফাইল';
$body_class = 'lh-dash';
include __DIR__ . '/includes/header.php';
?>

<section class="lh-dash-head">
    <div class="lh-glow" aria-hidden="true"></div>
    <div class="container lh-dash-me" data-reveal>
        <img src="<?= e(avatar_url($user['avatar'], $user['name'])) ?>" alt="" width="64" height="64">
        <div>
            <p class="lh-eyebrow"><?= t('স্বাগতম', 'Welcome back') ?></p>
            <h1><?= e($first) ?></h1>
            <p class="lh-muted"><?= e($user['email'] ?: $user['phone']) ?></p>
        </div>
        <a href="<?= APP_URL ?>/profile.php#edit-profile" class="lh-toggle" aria-label="Edit profile"><span class="material-icons-round">edit</span></a>
    </div>
</section>

<div class="container lh-dash">
    <!-- Next live class -->
    <?php if ($nextLive): $start = strtotime($nextLive['scheduled_at']); $joinUrl = $nextLive['meeting_url'] ?: $nextLive['stream_url']; ?>
    <section class="lh-live" data-reveal>
        <div class="lh-live-top">
            <span class="lh-chip lh-chip-live"><i></i><?= $nextLive['status'] === 'live' ? t('এখন লাইভ', 'Live now') : t('পরের লাইভ ক্লাস', 'Next live class') ?></span>
            <span class="lh-live-when"><?= lh_date(date('Y-m-d', $start)) ?> · <?= date('g:i A', $start) ?></span>
        </div>
        <h2><?= e($nextLive['title']) ?></h2>
        <p class="lh-live-course"><?= t($nextLive['course_title'], $nextLive['course_title_en'] ?: $nextLive['course_title']) ?></p>
        <div class="lh-pass-countdown lh-c-grid" data-countdown="<?= e(date('c', $start)) ?>">
            <div><b data-d>--</b><small><?= t('দিন', 'days') ?></small></div>
            <div><b data-h>--</b><small><?= t('ঘণ্টা', 'hrs') ?></small></div>
            <div><b data-m>--</b><small><?= t('মিনিট', 'min') ?></small></div>
            <div><b data-s>--</b><small><?= t('সেকেন্ড', 'sec') ?></small></div>
        </div>
        <?php if ($joinUrl): ?>
        <a href="<?= e($joinUrl) ?>" target="_blank" rel="noopener" class="btn btn-primary lh-btn-block" data-join data-start="<?= $start ?>">
            <span class="material-icons-round">videocam</span><span data-join-label><?= t('ক্লাসে যোগ দিন', 'Join class') ?></span>
        </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/learn.php?course=<?= (int)$nextLive['course_id'] ?>" class="lh-link"><?= t('ক্লাসরুমে যান', 'Open classroom') ?> <span class="material-icons-round">arrow_forward</span></a>
    </section>
    <?php endif; ?>

    <!-- Continue learning -->
    <section>
        <div class="lh-section-head lh-section-head-row" data-reveal>
            <div><h2><?= t('আমার কোর্স', 'My courses') ?></h2></div>
            <?php if ($courses): ?><a href="<?= APP_URL ?>/my-courses.php" class="lh-link"><?= t('সব', 'All') ?> <span class="material-icons-round">arrow_forward</span></a><?php endif; ?>
        </div>
        <?php if ($courses): ?>
        <div class="lh-mycourses" data-reveal-group>
            <?php foreach ($courses as $c): $L = lh_lang($c['cat_slug']); $p = (int)$c['progress_pct']; ?>
            <a class="lh-mycourse" href="<?= APP_URL ?>/learn.php?course=<?= (int)$c['id'] ?>" style="--lang:var(<?= $L['var'] ?>)">
                <span class="lh-co-glyph" aria-hidden="true"><?= e($L['glyph']) ?></span>
                <span class="lh-mycourse-body">
                    <span class="lh-ticket-meta"><?= t($c['cat_name'], $c['cat_name_en']) ?> · <?= $c['type'] === 'live' ? t('লাইভ', 'Live') : t('রেকর্ডেড', 'Recorded') ?></span>
                    <b><?= t($c['title_bn'], $c['title_en'] ?: $c['title_bn']) ?></b>
                    <span class="lh-progress"><span class="lh-progress-bar"><span style="width:<?= $p ?>%"></span></span><small><?= t_num($p) ?>%</small></span>
                </span>
                <span class="lh-ticket-go material-icons-round" aria-hidden="true"><?= $p > 0 ? 'play_arrow' : 'arrow_forward' ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="lh-c-card lh-empty" data-reveal>
            <span class="material-icons-round" aria-hidden="true">school</span>
            <h2><?= t('এখনো কোনো কোর্সে ভর্তি হননি', 'No courses yet') ?></h2>
            <p><?= t('একটি ফ্রি ক্লাস দিয়ে শুরু করুন।', 'Start with a free class.') ?></p>
            <a href="<?= APP_URL ?>/#courses" class="btn btn-primary"><?= t('কোর্স দেখুন', 'Browse courses') ?></a>
        </div>
        <?php endif; ?>
    </section>

    <!-- Quick links -->
    <nav class="lh-quick" aria-label="Account" data-reveal-group>
        <?php foreach ([
            ['my-courses.php', 'play_circle', t('আমার কোর্স', 'My courses')],
            ['orders.php', 'receipt_long', t('পেমেন্ট ও ইনভয়েস', 'Payments & invoices')],
            ['certificates.php', 'workspace_premium', t('সার্টিফিকেট', 'Certificates')],
            ['notifications.php', 'notifications', t('নোটিফিকেশন', 'Notifications') . ($unread ? ' <span class="lh-fchip-n">' . t_num($unread) . '</span>' : '')],
            ['profile.php#edit-profile', 'manage_accounts', t('প্রোফাইল এডিট', 'Edit profile')],
            ['help.php', 'support_agent', t('সাহায্য', 'Help')],
        ] as [$href, $icon, $label]): ?>
        <a href="<?= APP_URL . '/' . $href ?>"><span class="material-icons-round" aria-hidden="true"><?= $icon ?></span><span><?= $label ?></span></a>
        <?php endforeach; ?>
    </nav>

    <!-- Devices -->
    <section class="lh-c-card" id="devices" data-reveal>
        <h2><?= t('লগইন করা ডিভাইস', 'Signed-in devices') ?> <small><?= t_num(count($devices)) ?>/<?= t_num(MAX_DEVICE_SESSIONS) ?></small></h2>
        <div class="lh-devices">
            <?php foreach ($devices as $d): $isThis = (int)$d['id'] === (int)db_val("SELECT id FROM user_sessions WHERE session_token=?", [$currentToken]); ?>
            <form method="POST" class="lh-device">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="kill_session">
                <input type="hidden" name="session_id" value="<?= (int)$d['id'] ?>">
                <span class="material-icons-round" aria-hidden="true">smartphone</span>
                <span class="lh-device-name"><?= e($d['device_name'] ?: 'Device') ?><small><?= $isThis ? t('এই ডিভাইস', 'This device') : date('d M, g:i A', strtotime($d['last_seen_at'])) ?></small></span>
                <?php if (!$isThis): ?><button type="submit" class="lh-btn-quiet"><?= t('লগআউট', 'Sign out') ?></button><?php endif; ?>
            </form>
            <?php endforeach; ?>
        </div>
        <a href="<?= APP_URL ?>/logout?token=<?= csrf_token() ?>" class="lh-link lh-logout"><span class="material-icons-round">logout</span><?= t('এই ডিভাইস থেকে লগআউট', 'Log out of this device') ?></a>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
