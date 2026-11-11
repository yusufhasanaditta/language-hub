<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lh_components.php';

session_start_secure();
send_security_headers();
require_login();

$uid = (int)$_SESSION['user_id'];
$tab = in_array($_GET['tab'] ?? '', ['active', 'done'], true) ? $_GET['tab'] : 'all';
$courses = db_query("
    SELECT c.*, e.progress_pct, e.completed_at, cat.slug AS cat_slug, cat.name_bn AS cat_name, cat.name_en AS cat_name_en, u.name AS teacher_name
    FROM enrollments e
    JOIN courses c ON c.id = e.course_id
    JOIN categories cat ON cat.id = c.category_id
    JOIN teacher_profiles tp ON tp.id = c.teacher_id
    JOIN users u ON u.id = tp.user_id
    WHERE e.user_id = ? AND e.status = 'active'
    ORDER BY e.enrolled_at DESC", [$uid]);
$isDone = fn($c) => (int)$c['progress_pct'] >= 100;
$counts = ['all' => count($courses), 'active' => count(array_filter($courses, fn($c) => !$isDone($c))), 'done' => count(array_filter($courses, $isDone))];
if ($tab === 'active') $courses = array_filter($courses, fn($c) => !$isDone($c));
if ($tab === 'done')   $courses = array_filter($courses, $isDone);

$page_title = 'আমার কোর্স';
$body_class = 'lh-dash';
include __DIR__ . '/includes/header.php';
?>

<section class="lh-list-head">
    <div class="lh-glow" aria-hidden="true"></div>
    <div class="container" data-reveal>
        <a href="<?= APP_URL ?>/dashboard.php" class="lh-c-back"><span class="material-icons-round">arrow_back</span><?= t('প্রোফাইল', 'Profile') ?></a>
        <h1 class="lh-list-title"><?= t('আমার কোর্স', 'My courses') ?></h1>
    </div>
</section>
<div class="lh-filters">
    <div class="lh-chips">
        <?php foreach (['all' => ['সব', 'All'], 'active' => ['চলছে', 'In progress'], 'done' => ['সম্পন্ন', 'Completed']] as $k => [$bn, $en]): ?>
        <a href="<?= APP_URL ?>/my-courses.php<?= $k === 'all' ? '' : '?tab=' . $k ?>" class="lh-fchip <?= $tab === $k ? 'is-on' : '' ?>"><?= t($bn, $en) ?> <span class="lh-fchip-n"><?= t_num($counts[$k]) ?></span></a>
        <?php endforeach; ?>
    </div>
</div>

<section class="container lh-list-body">
    <?php if ($courses): ?>
    <div class="lh-mycourses" data-reveal-group>
        <?php foreach ($courses as $c): $L = lh_lang($c['cat_slug']); $p = (int)$c['progress_pct']; ?>
        <a class="lh-mycourse" href="<?= APP_URL ?>/learn.php?course=<?= (int)$c['id'] ?>" style="--lang:var(<?= $L['var'] ?>)">
            <span class="lh-co-glyph" aria-hidden="true"><?= e($L['glyph']) ?></span>
            <span class="lh-mycourse-body">
                <span class="lh-ticket-meta"><?= t($c['cat_name'], $c['cat_name_en']) ?> · <?= e($c['teacher_name']) ?></span>
                <b><?= t($c['title_bn'], $c['title_en'] ?: $c['title_bn']) ?></b>
                <span class="lh-progress"><span class="lh-progress-bar"><span style="width:<?= $p ?>%"></span></span><small><?= t_num($p) ?>%</small></span>
            </span>
            <span class="lh-ticket-go material-icons-round" aria-hidden="true"><?= $p >= 100 ? 'replay' : 'play_arrow' ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="lh-empty" data-reveal>
        <span class="material-icons-round" aria-hidden="true">school</span>
        <h2><?= $tab === 'all' ? t('এখনো কোনো কোর্সে ভর্তি হননি', 'No courses yet') : t('এখানে কিছু নেই', 'Nothing here') ?></h2>
        <a href="<?= APP_URL ?>/#courses" class="btn btn-primary"><?= t('কোর্স দেখুন', 'Browse courses') ?></a>
    </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
