<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lh_components.php';

session_start_secure();
send_security_headers();

$slug = sanitize_string($_GET['slug'] ?? '', 220);
if (!$slug) {
    http_response_code(404);
    die('Course not found.');
}

$course = db_row("
    SELECT c.*, cat.name_bn as cat_name, cat.name_en as cat_name_en, cat.color as cat_color, cat.slug as cat_slug,
           tp.id as tp_id, tp.designation, tp.expertise, tp.rating_avg as t_rating,
           tp.total_students as t_students, tp.total_courses as t_courses,
           u.id as teacher_uid, u.name as teacher_name, u.avatar as teacher_avatar, u.bio as teacher_bio,
           u.username as teacher_username
    FROM courses c
    JOIN categories cat ON cat.id = c.category_id
    JOIN teacher_profiles tp ON tp.id = c.teacher_id
    JOIN users u ON u.id = tp.user_id
    WHERE c.slug = ? AND c.status = 'published'
", [$slug]);

if (!$course) {
    http_response_code(404);
    die('Course not found.');
}

$cid = (int) $course['id'];
track_course_view($cid);
$enrolled = is_enrolled($cid);

// Curriculum
$modules = db_query("SELECT * FROM modules WHERE course_id=? ORDER BY sort_order", [$cid]);
foreach ($modules as &$mod) {
    $mod['lessons'] = db_query("SELECT * FROM lessons WHERE module_id=? AND is_published=1 ORDER BY sort_order", [$mod['id']]);
}
unset($mod);

// Reviews
$reviews = db_query("
    SELECT r.*, u.name, u.avatar
    FROM reviews r JOIN users u ON u.id = r.user_id
    WHERE r.course_id=? AND r.is_approved=1
    ORDER BY r.created_at DESC LIMIT 6
", [$cid]);

// Display price
$isFree = (int) $course['is_free'];
$hasDiscount = !$isFree && $course['sale_price'] > 0 && $course['sale_price'] < $course['price'];
$displayPrice = $hasDiscount ? $course['sale_price'] : $course['price'];

// All teachers assigned to this course (pivot table supports multiple teachers)
$courseTeachers = db_query("
    SELECT tp.id as tp_id, u.id as teacher_uid, u.name as teacher_name,
           u.avatar as teacher_avatar, u.username as teacher_username, tp.designation
    FROM course_teachers ct
    JOIN teacher_profiles tp ON tp.id = ct.teacher_id
    JOIN users u ON u.id = tp.user_id
    WHERE ct.course_id=?
    ORDER BY ct.is_primary DESC, ct.sort_order ASC
", [$cid]);
if (!$courseTeachers) {
    $courseTeachers = [[
        'tp_id' => $course['tp_id'],
        'teacher_uid' => $course['teacher_uid'],
        'teacher_name' => $course['teacher_name'],
        'teacher_avatar' => $course['teacher_avatar'],
        'teacher_username' => $course['teacher_username'],
        'designation' => $course['designation'] ?? null,
    ]];
}

$page_title = $course['title_bn'];
$page_desc = $course['short_desc'] ?? $course['description_bn'] ?? '';
$page_image = course_thumbnail_url($course['thumbnail'] ?? '', $course['title_bn']);
$body_class = 'lh-course';
$L = lh_lang($course['cat_slug']);
$isLive = $course['type'] === 'live';
$seatsLeft = ($isLive && $course['max_students'] > 0) ? max(0, $course['max_students'] - $course['enrolled_count']) : null;
$learn = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)$course['what_you_learn']))));
$reqs  = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)$course['requirements']))));
$lessonCount = array_sum(array_map(fn($m) => count($m['lessons']), $modules)) ?: (int)$course['total_lessons'];
$video = $course['intro_video_url'] ?: $course['trailer_url'];
$levelBn = ['beginner' => 'শুরু থেকে', 'intermediate' => 'মাঝারি', 'advanced' => 'অ্যাডভান্সড'];

// The one main action: shown in the side card (desktop) and the sticky bar (phone)
ob_start();
if ($enrolled): ?>
    <a href="<?= APP_URL ?>/learn.php?course=<?= $cid ?>" class="btn btn-primary lh-btn-block"><span class="material-icons-round">play_circle</span><?= t('ক্লাসরুমে যান', 'Go to classroom') ?></a>
<?php elseif ($isFree): ?>
    <form method="POST" action="<?= APP_URL ?>/enroll.php">
        <?= csrf_field() ?><input type="hidden" name="course_id" value="<?= $cid ?>">
        <button type="submit" class="btn btn-primary lh-btn-block"><?= t('ফ্রি ভর্তি হোন', 'Enrol free') ?></button>
    </form>
<?php else: ?>
    <a href="<?= APP_URL ?>/checkout.php?course=<?= $cid ?>" class="btn btn-accent lh-btn-block"><?= $isLive ? t('সিট বুক করুন', 'Book a seat') : t('এখনই ভর্তি হোন', 'Enrol now') ?></a>
<?php endif;
$cta = ob_get_clean();

ob_start();
if ($isFree): ?><span class="lh-price lh-price-free"><?= t('ফ্রি', 'Free') ?></span><?php
else: ?><span class="lh-price">৳<?= t_num(format_price((float)$displayPrice)) ?></span><?php if ($hasDiscount): ?><s>৳<?= t_num(format_price((float)$course['price'])) ?></s><?php endif;
endif;
$priceHtml = ob_get_clean();

include __DIR__ . '/includes/header.php';
?>

<section class="lh-c-hero" style="--lang:var(<?= $L['var'] ?>)">
    <span class="lh-c-glyph" aria-hidden="true"><?= e($L['glyph']) ?></span>
    <div class="container lh-c-hero-in" data-reveal>
        <a href="<?= APP_URL ?>/category/<?= e($course['cat_slug']) ?>" class="lh-c-back"><span class="material-icons-round">arrow_back</span><?= t($course['cat_name'], $course['cat_name_en'] ?? $course['cat_name']) ?></a>
        <div class="lh-c-chips">
            <?php if ($isLive): ?><span class="lh-chip lh-chip-live"><i></i><?= t('লাইভ ব্যাচ', 'Live batch') ?></span>
            <?php else: ?><span class="lh-chip"><?= t('রেকর্ডেড', 'Recorded') ?></span><?php endif; ?>
            <span class="lh-chip"><?= t($levelBn[$course['level']] ?? 'শুরু থেকে', ucfirst($course['level'] ?? 'beginner')) ?></span>
            <?php if ($L['exam']): ?><span class="lh-chip"><?= e($L['exam']) ?></span><?php endif; ?>
        </div>
        <h1 class="lh-c-title"><?= t($course['title_bn'], $course['title_en'] ?: $course['title_bn']) ?></h1>
        <?php if ($course['short_desc']): ?><p class="lh-c-desc"><?= e($course['short_desc']) ?></p><?php endif; ?>
        <div class="lh-c-meta">
            <?php if ($course['rating_count'] > 0): ?><span><span class="material-icons-round lh-star">star</span><b><?= t_num(number_format((float)$course['rating_avg'], 1)) ?></b>&nbsp;(<?= t_num((int)$course['rating_count']) ?>)</span><?php endif; ?>
            <span><span class="material-icons-round">group</span><?= t_num(number_format((int)$course['enrolled_count'])) ?> <?= t('শিক্ষার্থী', 'learners') ?></span>
            <span><span class="material-icons-round">play_lesson</span><?= t_num($lessonCount) ?> <?= t('ক্লাস', 'lessons') ?></span>
        </div>
    </div>
</section>

<div class="container lh-c-layout">
    <div class="lh-c-main">
        <div class="lh-c-media" style="--lang:var(<?= $L['var'] ?>)" data-reveal>
            <?php if (!empty($course['thumbnail']) && is_file(APP_ROOT . '/' . ltrim($course['thumbnail'], '/'))): ?>
                <img src="<?= e(APP_URL . '/' . ltrim($course['thumbnail'], '/')) ?>" alt="">
            <?php else: ?>
                <span class="lh-ticket-glyph" aria-hidden="true"><?= e($L['glyph']) ?></span>
                <span class="lh-c-media-hello" aria-hidden="true"><?= e($L['hello']) ?></span>
            <?php endif; ?>
            <?php if ($video): ?>
            <button type="button" class="lh-play" data-video="<?= e($video) ?>" aria-label="Play trailer"><span class="material-icons-round">play_arrow</span></button>
            <?php endif; ?>
        </div>

        <?php if ($isLive && $course['batch_start']): ?>
        <section class="lh-c-card" data-reveal>
            <h2><?= t('ব্যাচের তথ্য', 'Batch details') ?></h2>
            <dl class="lh-pass-grid lh-c-grid">
                <div><dt><?= t('শুরু', 'Starts') ?></dt><dd><?= lh_date($course['batch_start']) ?></dd></div>
                <div><dt><?= t('ক্লাস', 'Classes') ?></dt><dd><?= t_num($lessonCount) ?></dd></div>
                <div><dt><?= t('সিট বাকি', 'Seats left') ?></dt><dd class="<?= $seatsLeft !== null && $seatsLeft <= 5 ? 'lh-hot' : '' ?>"><?= $seatsLeft === null ? '—' : t_num($seatsLeft) . '/' . t_num((int)$course['max_students']) ?></dd></div>
            </dl>
            <?php if (strtotime($course['batch_start']) > time()): ?>
            <div class="lh-pass-countdown lh-c-grid" data-countdown="<?= e(date('c', strtotime($course['batch_start'] . ' 00:00:00'))) ?>">
                <div><b data-d>--</b><small><?= t('দিন', 'days') ?></small></div>
                <div><b data-h>--</b><small><?= t('ঘণ্টা', 'hrs') ?></small></div>
                <div><b data-m>--</b><small><?= t('মিনিট', 'min') ?></small></div>
                <div><b data-s>--</b><small><?= t('সেকেন্ড', 'sec') ?></small></div>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($learn): ?>
        <section class="lh-c-card" data-reveal>
            <h2><?= t('যা শিখবেন', 'What you will learn') ?></h2>
            <ul class="lh-ticks"><?php foreach ($learn as $li): ?><li><?= e(ltrim($li, "-•* ")) ?></li><?php endforeach; ?></ul>
        </section>
        <?php endif; ?>

        <?php if ($modules): ?>
        <section class="lh-c-card" data-reveal>
            <h2><?= t('কোর্স কারিকুলাম', 'Curriculum') ?> <small><?= t_num(count($modules)) ?> <?= t('অধ্যায়', 'modules') ?> · <?= t_num($lessonCount) ?> <?= t('ক্লাস', 'lessons') ?></small></h2>
            <div class="lh-faq lh-curr">
                <?php foreach ($modules as $i => $m): ?>
                <details <?= $i === 0 ? 'open' : '' ?>>
                    <summary><span><span class="lh-curr-n"><?= t_num($i + 1) ?></span><?= e($m['title'] ?? '') ?></span><span class="material-icons-round" aria-hidden="true">add</span></summary>
                    <ol class="lh-curr-list lh-faq-a">
                        <?php foreach ($m['lessons'] as $ls): ?>
                        <li>
                            <span class="material-icons-round" aria-hidden="true"><?= ($ls['kind'] ?? '') === 'pdf' ? 'description' : 'play_circle' ?></span>
                            <span class="lh-curr-t"><?= e($ls['title']) ?></span>
                            <?php if ((int)$ls['is_free'] === 1): ?><span class="lh-chip lh-chip-free"><?= t('ফ্রি', 'Free') ?></span>
                            <?php elseif (!$enrolled): ?><span class="material-icons-round lh-lock" aria-label="Locked">lock</span><?php endif; ?>
                            <?php if ($ls['duration']): ?><small><?= t_num((int)$ls['duration']) ?>m</small><?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ol>
                </details>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="lh-c-card" data-reveal>
            <h2><?= count($courseTeachers) > 1 ? t('শিক্ষকবৃন্দ', 'Teachers') : t('শিক্ষক', 'Teacher') ?></h2>
            <div class="lh-teachers">
                <?php foreach ($courseTeachers as $tch): ?>
                <a class="lh-teacher" href="<?= $tch['teacher_username'] ? APP_URL . '/t/' . e($tch['teacher_username']) : '#' ?>">
                    <img src="<?= e(avatar_url($tch['teacher_avatar'], $tch['teacher_name'])) ?>" alt="" width="52" height="52" loading="lazy">
                    <span><b><?= e($tch['teacher_name']) ?></b><small><?= e($tch['designation'] ?? '') ?></small></span>
                </a>
                <?php endforeach; ?>
            </div>
        </section>

        <?php if ($course['description_bn']): ?>
        <section class="lh-c-card" data-reveal>
            <h2><?= t('কোর্স সম্পর্কে', 'About this course') ?></h2>
            <div class="lh-prose"><?= nl2br(e(strip_tags($course['description_bn']))) ?></div>
        </section>
        <?php endif; ?>

        <?php if ($reqs): ?>
        <section class="lh-c-card" data-reveal>
            <h2><?= t('যা লাগবে', 'Requirements') ?></h2>
            <ul class="lh-ticks lh-ticks-muted"><?php foreach ($reqs as $r): ?><li><?= e(ltrim($r, "-•* ")) ?></li><?php endforeach; ?></ul>
        </section>
        <?php endif; ?>

        <?php if ($reviews): ?>
        <section class="lh-c-card" data-reveal>
            <h2><?= t('শিক্ষার্থীদের মতামত', 'What learners say') ?></h2>
            <div class="lh-reviews">
                <?php foreach ($reviews as $rv): $stars = max(0, min(5, (int)($rv['rating'] ?? 5))); ?>
                <figure class="lh-review">
                    <div class="lh-review-stars" aria-label="<?= $stars ?>/5"><?= str_repeat('★', $stars) ?><span><?= str_repeat('★', 5 - $stars) ?></span></div>
                    <blockquote><?= e($rv['comment'] ?? $rv['body'] ?? '') ?></blockquote>
                    <figcaption><img src="<?= e(avatar_url($rv['avatar'], $rv['name'])) ?>" alt="" width="28" height="28" loading="lazy"><?= e($rv['name']) ?></figcaption>
                </figure>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </div>

    <aside class="lh-c-side">
        <div class="lh-c-buy" data-reveal="right">
            <div class="lh-c-buy-price"><?= $priceHtml ?></div>
            <?php if ($hasDiscount): ?><p class="lh-c-save"><?= t('সাশ্রয়', 'You save') ?> ৳<?= t_num(format_price((float)$course['price'] - (float)$displayPrice)) ?></p><?php endif; ?>
            <?= $cta ?>
            <ul class="lh-c-perks">
                <li><span class="material-icons-round">translate</span><?= t('সব ক্লাস বাংলায়', 'All classes in Bangla') ?></li>
                <?php if ($isLive): ?>
                <li><span class="material-icons-round">groups</span><?= t('ব্যাচে সর্বোচ্চ ১৫ জন', 'Max 15 per batch') ?></li>
                <li><span class="material-icons-round">videocam</span><?= t('প্রতিটি ক্লাসের রেকর্ডিং', 'Every class recorded') ?></li>
                <?php else: ?>
                <li><span class="material-icons-round">all_inclusive</span><?= t('যখন খুশি দেখুন', 'Watch any time') ?></li>
                <?php endif; ?>
                <li><span class="material-icons-round">devices</span><?= t('২টি ডিভাইসে চলবে', 'Works on 2 devices') ?></li>
            </ul>
        </div>
    </aside>
</div>

<!-- Phone: sticky bar with the price and the one main action -->
<div class="lh-buybar">
    <div class="lh-buybar-price"><?= $priceHtml ?></div>
    <div class="lh-buybar-cta"><?= $cta ?></div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
