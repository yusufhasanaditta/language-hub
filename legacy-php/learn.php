<?php
// Classroom: lesson player + live classes for one enrolled course.
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lh_components.php';

session_start_secure();
send_security_headers();
require_login();

$cid  = sanitize_int($_GET['course'] ?? 0);
$user = current_user();
$course = db_row("
    SELECT c.*, cat.slug AS cat_slug, cat.name_bn AS cat_name, cat.name_en AS cat_name_en, u.name AS teacher_name
    FROM courses c
    JOIN categories cat ON cat.id = c.category_id
    JOIN teacher_profiles tp ON tp.id = c.teacher_id
    JOIN users u ON u.id = tp.user_id
    WHERE c.id = ?", [$cid]);
if (!$course) { flash('error', 'কোর্সটি পাওয়া যায়নি।'); redirect(APP_URL . '/my-courses.php'); }

$enrollment = db_row("SELECT * FROM enrollments WHERE user_id=? AND course_id=? AND status='active'", [(int)$user['id'], $cid]);
$staff = is_admin() || is_teacher();
if (!$enrollment && !$staff) {
    flash('info', 'ক্লাসরুমে ঢুকতে আগে কোর্সে ভর্তি হোন।');
    redirect(course_url($course));
}

// Curriculum + this student's progress
$modules = db_query("SELECT * FROM modules WHERE course_id=? ORDER BY sort_order, id", [$cid]);
$done = $enrollment
    ? array_flip(array_column(db_query("SELECT lesson_id FROM lesson_progress WHERE enrollment_id=? AND completed=1", [(int)$enrollment['id']]), 'lesson_id'))
    : [];
$flat = [];
foreach ($modules as &$m) {
    $m['lessons'] = db_query("SELECT * FROM lessons WHERE module_id=? AND is_published=1 ORDER BY sort_order, id", [$m['id']]);
    foreach ($m['lessons'] as $ls) $flat[] = $ls;
}
unset($m);

// Current lesson: ?lesson=…, else the first one not yet done
$lessonId = sanitize_int($_GET['lesson'] ?? 0);
$current = null;
foreach ($flat as $i => $ls) { if ((int)$ls['id'] === $lessonId) { $current = $i; break; } }
if ($current === null) { foreach ($flat as $i => $ls) { if (!isset($done[$ls['id']])) { $current = $i; break; } } }
if ($current === null && $flat) $current = 0;
$lesson = $current !== null ? $flat[$current] : null;
$prev   = $current ? $flat[$current - 1] : null;
$next   = ($current !== null && isset($flat[$current + 1])) ? $flat[$current + 1] : null;
$total  = count($flat);
$pct    = $total ? (int)round(count(array_intersect_key($done, array_column($flat, null, 'id'))) / $total * 100) : 0;

// Live classes
$liveNext = db_row("SELECT * FROM live_sessions WHERE course_id=? AND status IN ('scheduled','live')
                    AND scheduled_at >= NOW() - INTERVAL COALESCE(duration_minutes, 90) MINUTE
                    ORDER BY scheduled_at LIMIT 1", [$cid]);
$recordings = db_query("SELECT id, title, recording_url, scheduled_at FROM live_sessions
                        WHERE course_id=? AND recording_url IS NOT NULL AND recording_url <> '' ORDER BY scheduled_at DESC", [$cid]);
$classrooms = db_query("SELECT id, title FROM virtual_classrooms WHERE course_id=? ORDER BY sort_order, id", [$cid]);

// Video source for the current lesson
$embed = '';
if ($lesson) {
    $src = trim((string)($lesson['video_embed'] ?: $lesson['video_url']));
    if ($src !== '') {
        if ($yt = extract_youtube_id($src)) {
            $embed = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($yt) . '?rel=0&modestbranding=1&playsinline=1';
        } elseif (preg_match('#vimeo\.com/(?:video/)?(\d+)#', $src, $vm)) {
            $embed = 'https://player.vimeo.com/video/' . $vm[1];
        } elseif (preg_match('#^https://#', $src)) {
            $embed = $src;
        }
    }
}
// Watermark: who is watching (discourages screen recording)
$mark = $user['phone'] ?: ($user['email'] ?: $user['name']);

$L = lh_lang($course['cat_slug']);
$page_title = $course['title_bn'];
$body_class = 'lh-learn';
include __DIR__ . '/includes/header.php';
?>

<section class="lh-learn-head" style="--lang:var(<?= $L['var'] ?>)">
    <div class="container">
        <a href="<?= APP_URL ?>/my-courses.php" class="lh-c-back"><span class="material-icons-round">arrow_back</span><?= t('আমার কোর্স', 'My courses') ?></a>
        <h1 class="lh-learn-title"><?= t($course['title_bn'], $course['title_en'] ?: $course['title_bn']) ?></h1>
        <div class="lh-progress" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="lh-progress-bar"><span style="width:<?= $pct ?>%"></span></div>
            <b data-pct><?= t_num($pct) ?>%</b>
        </div>
    </div>
</section>

<?php if (!empty($_GET['welcome'])): ?>
<div class="container">
    <div class="lh-welcome" role="status" data-reveal>
        <div class="lh-confetti" aria-hidden="true"><?php for ($i = 0; $i < 18; $i++): ?><i style="--i:<?= $i ?>"></i><?php endfor; ?></div>
        <span class="material-icons-round" aria-hidden="true">celebration</span>
        <div>
            <h2><?= t('স্বাগতম! আপনি ভর্তি হয়েছেন', 'Welcome! You are enrolled') ?></h2>
            <p><?= $course['type'] === 'live'
                ? t('পরের লাইভ ক্লাসের সময় নিচে দেখুন। ক্লাসের ১০ মিনিট আগে "ক্লাসে যোগ দিন" বাটন চালু হবে।', 'See your next live class below. The Join button opens 10 minutes before class.')
                : t('প্রথম ক্লাস থেকেই শুরু করুন — নিজের গতিতে।', 'Start with the first lesson — at your own pace.') ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="container lh-learn">
    <div class="lh-learn-main">
        <?php if ($course['type'] === 'live' || $liveNext): ?>
        <!-- Next live class -->
        <section class="lh-live" data-reveal>
            <?php if ($liveNext):
                $start = strtotime($liveNext['scheduled_at']);
                $joinUrl = $liveNext['meeting_url'] ?: $liveNext['stream_url']; ?>
            <div class="lh-live-top">
                <span class="lh-chip lh-chip-live"><i></i><?= $liveNext['status'] === 'live' ? t('এখন লাইভ', 'Live now') : t('পরের লাইভ ক্লাস', 'Next live class') ?></span>
                <span class="lh-live-when"><?= lh_date(date('Y-m-d', $start)) ?> · <?= date('g:i A', $start) ?></span>
            </div>
            <h2><?= e($liveNext['title']) ?></h2>
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
            <p class="lh-live-note"><?= t('বাটনটি ক্লাস শুরুর ১০ মিনিট আগে চালু হবে।', 'The button opens 10 minutes before class.') ?></p>
            <?php else: ?>
            <p class="lh-live-note"><?= t('ক্লাসের লিংক শিক্ষক শীঘ্রই দেবেন।', 'Your teacher will add the class link soon.') ?></p>
            <?php endif; ?>
            <?php else: ?>
            <div class="lh-live-top"><span class="lh-chip"><?= t('লাইভ ক্লাস', 'Live classes') ?></span></div>
            <h2><?= $course['batch_start'] && strtotime($course['batch_start']) > time()
                ? t('ব্যাচ শুরু ' . bn_num(date('j', strtotime($course['batch_start']))) . ' তারিখে', 'Batch starts ' . date('j M', strtotime($course['batch_start'])))
                : t('পরের ক্লাসের সময় শীঘ্রই জানানো হবে', 'Next class time coming soon') ?></h2>
            <?php if ($course['batch_start'] && strtotime($course['batch_start']) > time()): ?>
            <div class="lh-pass-countdown lh-c-grid" data-countdown="<?= e(date('c', strtotime($course['batch_start'] . ' 00:00:00'))) ?>">
                <div><b data-d>--</b><small><?= t('দিন', 'days') ?></small></div>
                <div><b data-h>--</b><small><?= t('ঘণ্টা', 'hrs') ?></small></div>
                <div><b data-m>--</b><small><?= t('মিনিট', 'min') ?></small></div>
                <div><b data-s>--</b><small><?= t('সেকেন্ড', 'sec') ?></small></div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($course['fb_group_link']): ?>
            <a href="<?= e($course['fb_group_link']) ?>" target="_blank" rel="noopener" class="lh-link"><span class="material-icons-round">groups</span><?= t('ব্যাচের গ্রুপে যোগ দিন', 'Join the batch group') ?></a>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($lesson): ?>
        <!-- Lesson player -->
        <section class="lh-player-card" data-reveal>
            <div class="lh-player" style="--lang:var(<?= $L['var'] ?>)">
                <?php if ($lesson['kind'] === 'video' && $embed): ?>
                    <iframe src="<?= e($embed) ?>" title="<?= e($lesson['title']) ?>" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
                <?php elseif ($lesson['kind'] === 'pdf' && $lesson['pdf_url']): ?>
                    <div class="lh-player-empty"><span class="material-icons-round">description</span>
                        <a class="btn btn-primary" href="<?= e($lesson['pdf_url']) ?>" target="_blank" rel="noopener"><?= t('PDF খুলুন', 'Open PDF') ?></a></div>
                <?php elseif ($lesson['kind'] === 'text' && $lesson['content']): ?>
                    <div class="lh-player-text lh-prose"><?= nl2br(e(strip_tags($lesson['content']))) ?></div>
                <?php else: ?>
                    <div class="lh-player-empty"><span class="lh-ticket-glyph" aria-hidden="true"><?= e($L['glyph']) ?></span>
                        <p><?= t('এই ক্লাসের ভিডিও শীঘ্রই যোগ হবে।', 'The video for this lesson is coming soon.') ?></p></div>
                <?php endif; ?>
                <?php if ($lesson['kind'] !== 'text'): ?>
                <span class="lh-watermark" aria-hidden="true" data-watermark><?= e($mark) ?></span>
                <?php endif; ?>
            </div>
            <div class="lh-player-bar">
                <div>
                    <p class="lh-ticket-meta"><?= t('ক্লাস', 'Lesson') ?> <?= t_num($current + 1) ?>/<?= t_num($total) ?></p>
                    <h2><?= e($lesson['title']) ?></h2>
                </div>
                <?php if ($enrollment): ?>
                <button type="button" class="btn <?= isset($done[$lesson['id']]) ? 'btn-outline is-done' : 'btn-primary' ?> lh-done-btn" data-done="<?= (int)$lesson['id'] ?>"
                        data-next="<?= $next ? e(APP_URL . '/learn.php?course=' . $cid . '&lesson=' . $next['id']) : '' ?>">
                    <span class="material-icons-round"><?= isset($done[$lesson['id']]) ? 'check_circle' : 'task_alt' ?></span>
                    <span data-done-label><?= isset($done[$lesson['id']]) ? t('সম্পন্ন', 'Done') : t('সম্পন্ন করেছি', 'Mark as done') ?></span>
                </button>
                <?php endif; ?>
            </div>
            <div class="lh-player-nav">
                <?php if ($prev): ?><a class="btn btn-outline" href="<?= APP_URL ?>/learn.php?course=<?= $cid ?>&lesson=<?= (int)$prev['id'] ?>"><span class="material-icons-round">arrow_back</span><?= t('আগের', 'Previous') ?></a><?php else: ?><span></span><?php endif; ?>
                <?php if ($next): ?><a class="btn btn-outline" href="<?= APP_URL ?>/learn.php?course=<?= $cid ?>&lesson=<?= (int)$next['id'] ?>"><?= t('পরের', 'Next') ?><span class="material-icons-round">arrow_forward</span></a><?php endif; ?>
            </div>
        </section>
        <?php elseif ($course['type'] !== 'live'): ?>
        <section class="lh-c-card lh-empty" data-reveal>
            <span class="material-icons-round" aria-hidden="true">video_library</span>
            <h2><?= t('ক্লাসগুলো শীঘ্রই যোগ হবে', 'Lessons are coming soon') ?></h2>
            <p><?= t('শিক্ষক ক্লাস যোগ করলেই এখানে দেখতে পাবেন।', 'They appear here as soon as the teacher adds them.') ?></p>
        </section>
        <?php endif; ?>

        <?php if ($recordings): ?>
        <section class="lh-c-card" data-reveal>
            <h2><?= t('লাইভ ক্লাসের রেকর্ডিং', 'Live class recordings') ?></h2>
            <ul class="lh-rec-list">
                <?php foreach ($recordings as $r): ?>
                <li><a href="<?= e($r['recording_url']) ?>" data-video="<?= e($r['recording_url']) ?>">
                    <span class="material-icons-round">play_circle</span>
                    <span><b><?= e($r['title']) ?></b><small><?= lh_date(date('Y-m-d', strtotime($r['scheduled_at']))) ?></small></span>
                </a></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    </div>

    <aside class="lh-learn-side">
        <?php if ($modules): ?>
        <section class="lh-c-card lh-learn-list" data-reveal="right">
            <h2><?= t('সব ক্লাস', 'All lessons') ?> <small><?= t_num(count($done)) ?>/<?= t_num($total) ?></small></h2>
            <?php foreach ($modules as $mi => $m): if (!$m['lessons']) continue; ?>
            <p class="lh-learn-mod"><span class="lh-curr-n"><?= t_num($mi + 1) ?></span><?= e($m['title']) ?></p>
            <ol class="lh-curr-list">
                <?php foreach ($m['lessons'] as $ls): $isCur = $lesson && (int)$ls['id'] === (int)$lesson['id']; ?>
                <li class="<?= $isCur ? 'is-current' : '' ?> <?= isset($done[$ls['id']]) ? 'is-done' : '' ?>">
                    <a href="<?= APP_URL ?>/learn.php?course=<?= $cid ?>&lesson=<?= (int)$ls['id'] ?>" <?= $isCur ? 'aria-current="true"' : '' ?>>
                        <span class="material-icons-round" aria-hidden="true"><?= isset($done[$ls['id']]) ? 'check_circle' : ($isCur ? 'play_circle' : 'radio_button_unchecked') ?></span>
                        <span class="lh-curr-t"><?= e($ls['title']) ?></span>
                        <?php if ($ls['duration']): ?><small><?= t_num((int)$ls['duration']) ?>m</small><?php endif; ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ol>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <?php if ($classrooms): ?>
        <section class="lh-c-card" data-reveal="right">
            <h2><?= t('নোট ও ম্যাটেরিয়াল', 'Notes & materials') ?></h2>
            <ul class="lh-rec-list">
                <?php foreach ($classrooms as $vc): ?>
                <li><a href="<?= APP_URL ?>/student_classroom.php?id=<?= (int)$vc['id'] ?>"><span class="material-icons-round">folder_open</span><span><b><?= e($vc['title']) ?></b></span></a></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    </aside>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
