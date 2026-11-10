<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lh_components.php';

session_start_secure();
send_security_headers();

$page_title = APP_NAME . ' — ' . APP_TAGLINE;
$page_desc  = 'বাংলায় চাইনিজ, জাপানিজ, কোরিয়ান ও ইংলিশ শিখুন — লাইভ ব্যাচ (সর্বোচ্চ ১৫ জন) ও রেকর্ডেড কোর্স।';
$body_class = 'lh-home';

$courseSelect = "
    SELECT c.*, cat.slug AS cat_slug, cat.name_bn AS cat_name, cat.name_en AS cat_name_en,
           u.name AS teacher_name
    FROM courses c
    JOIN categories cat ON cat.id = c.category_id
    JOIN teacher_profiles tp ON tp.id = c.teacher_id
    JOIN users u ON u.id = tp.user_id
    WHERE c.status = 'published'";

$home = cache_get('lh_home_v4');
if (!$home) {
    $home = [
        // Soonest live batch that still has seats → boarding pass
        'next' => db_row($courseSelect . "
            AND c.type = 'live' AND c.batch_start >= CURDATE()
            AND (c.max_students = 0 OR c.enrolled_count < c.max_students)
            ORDER BY c.batch_start ASC LIMIT 1"),
        // Every published course: featured first, then live batches, then most taken
        'all' => db_query($courseSelect . " ORDER BY c.is_featured DESC, c.type = 'live' DESC, c.enrolled_count DESC"),
        'langs'   => db_query("
            SELECT cat.*, COUNT(c.id) AS n
            FROM categories cat
            LEFT JOIN courses c ON c.category_id = cat.id AND c.status = 'published'
            GROUP BY cat.id ORDER BY cat.sort_order"),
    ];
    cache_set('lh_home_v4', $home, 300);
}
$next = $home['next'] ?: null;

$faqs = [
    ['ক্লাসগুলো কি বাংলায় হয়?', 'Are classes taught in Bangla?',
     'হ্যাঁ। প্রতিটি ক্লাস বাংলায় বোঝানো হয়, যাতে একদম নতুনরাও সহজে শিখতে পারেন।', 'Yes. Every class is explained in Bangla, so complete beginners can follow along.'],
    ['লাইভ ব্যাচে কতজন থাকে?', 'How many students are in a live batch?',
     'প্রতি ব্যাচে সর্বোচ্চ ১৫ জন — যাতে শিক্ষক প্রত্যেককে আলাদা করে সময় দিতে পারেন।', 'At most 15 per batch, so the teacher can give everyone individual attention.'],
    ['লাইভ ক্লাস মিস করলে কী হবে?', 'What if I miss a live class?',
     'প্রতিটি লাইভ ক্লাসের রেকর্ডিং আপনার ক্লাসরুমে পাওয়া যাবে।', 'Every live class is recorded and appears in your classroom.'],
    ['কীভাবে পেমেন্ট করব?', 'How do I pay?',
     'বিকাশ, নগদ, রকেট বা কার্ড — SSLCommerz-এর মাধ্যমে নিরাপদে পেমেন্ট করুন।', 'bKash, Nagad, Rocket or card — paid securely through SSLCommerz.'],
    ['কীভাবে লগইন করব?', 'How do I log in?',
     'শুধু ইমেইল দিন — আপনার ইমেইলে একটি কোড যাবে। কোনো পাসওয়ার্ড লাগবে না। একটি অ্যাকাউন্ট সর্বোচ্চ ২টি ডিভাইসে চলে।',
     'Just enter your email and we send you a code — no password. One account works on up to 2 devices.'],
];

require_once __DIR__ . '/includes/header.php';
?>

<!-- ═══ 1. HERO: departure board + language picker (first screen) ═══════════
     The flip-board word, the hero tint and the centred card stay in sync:
     swipe a card and the board flips to that language. -->
<?php
$flapBn = array_map(fn($c) => $c['name_bn'], $home['langs']);
$flapEn = array_map(fn($c) => $c['name_en'], $home['langs']);
$flapColors = array_map(fn($c) => 'var(' . lh_lang($c['slug'])['var'] . ')', $home['langs']);
$skyGlyphs = [['中', 8, 14, 1.0, 7], ['あ', 84, 10, 0.6, 5.5], ['한', 76, 58, 1.3, 6], ['A', 14, 62, 0.8, 5],
              ['文', 46, 6, 0.4, 3.2], ['カ', 92, 34, 1.6, 3.4], ['글', 4, 38, 1.5, 3], ['Z', 60, 40, 0.5, 2.6]];
?>
<section class="lh-pick lh-hero2" data-hero style="--lang:<?= e($flapColors[0] ?? 'var(--lh-brand)') ?>">
    <div class="lh-glow" aria-hidden="true"></div>
    <div class="lh-sky" aria-hidden="true">
        <?php foreach ($skyGlyphs as $i => [$g, $x, $y, $depth, $size]): ?>
        <span style="--x:<?= $x ?>%;--y:<?= $y ?>%;--d:<?= $depth ?>;--fs:<?= $size ?>rem;--dl:<?= $i * -1.7 ?>s"><?= $g ?></span>
        <?php endforeach; ?>
    </div>
    <svg class="lh-route" viewBox="0 0 400 160" preserveAspectRatio="none" aria-hidden="true">
        <path id="lhRoute" d="M-10 140 C 90 30, 250 10, 410 70" fill="none"/>
        <g class="lh-plane">
            <path d="M-7 -1.6 L4 -1.6 L9 0 L4 1.6 L-7 1.6 Z M-2 -1.6 L1 -7 L3 -7 L2 -1.6 Z M-2 1.6 L1 7 L3 7 L2 1.6 Z M-7 -1.6 L-8 -4 L-6 -4 L-4.5 -1.6 Z M-7 1.6 L-8 4 L-6 4 L-4.5 1.6 Z"/>
            <animateMotion dur="9s" repeatCount="indefinite" rotate="auto" keyPoints="0;1" keyTimes="0;1" calcMode="spline" keySplines=".45 0 .55 1"><mpath href="#lhRoute"/></animateMotion>
        </g>
    </svg>

    <div class="container lh-pick-head" data-hero-fade>
        <p class="lh-stamp"><span><?= t('ভর্তি চলছে', 'Now boarding') ?></span></p>
        <h1 class="lh-pick-title">
            <span class="lh-h-line"><?= t('বাংলায় শিখুন', 'Learn in Bangla') ?></span>
            <span class="sr-only"><?= e(implode(', ', $flapBn)) ?></span>
            <span class="lh-flap" data-flap aria-hidden="true"
                  data-bn="<?= e(implode('|', $flapBn)) ?>" data-en="<?= e(implode('|', $flapEn)) ?>"
                  data-colors="<?= e(implode('|', $flapColors)) ?>">
                <span class="lh-flap-size"><?php foreach (array_merge($flapBn, $flapEn) as $w): ?><span><?= e($w) ?></span><?php endforeach; ?></span>
                <span class="lh-flap-face lh-flap-top"></span>
                <span class="lh-flap-face lh-flap-bot"></span>
                <span class="lh-flap-face lh-flap-top lh-flap-fly-top"></span>
                <span class="lh-flap-face lh-flap-bot lh-flap-fly-bot"></span>
            </span>
        </h1>
        <p class="lh-pick-sub"><?= t('শুরু থেকে পরীক্ষা পর্যন্ত — আপনার ভাষার যাত্রা এখান থেকেই।', 'From day one to exam day — your language journey starts here.') ?></p>
    </div>

    <div class="lh-carousel" data-carousel>
        <div class="lh-carousel-track" data-track>
            <?php foreach ($home['langs'] as $cat): $L = lh_lang($cat['slug']); ?>
            <a href="<?= APP_URL ?>/category/<?= e($cat['slug']) ?>" class="lh-lcard" style="--lang:var(<?= $L['var'] ?>)" data-slide>
                <span class="lh-lcard-shine" aria-hidden="true"></span>
                <span class="lh-lcard-glyph" aria-hidden="true"><?= e($L['glyph']) ?></span>
                <span class="lh-lcard-top">
                    <?php if ($L['exam']): ?><span class="lh-lcard-exam"><?= e($L['exam']) ?></span><?php endif; ?>
                    <span class="lh-lcard-count"><?= t_num((int)$cat['n']) ?> <?= t('কোর্স', 'courses') ?></span>
                </span>
                <span class="lh-lcard-bottom">
                    <span class="lh-lcard-hello"><?= e($L['hello']) ?></span>
                    <span class="lh-lcard-name"><?= t($cat['name_bn'], $cat['name_en']) ?></span>
                    <span class="lh-lcard-cta"><?= t('কোর্স দেখুন', 'See courses') ?> <span class="material-icons-round">arrow_forward</span></span>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="lh-dots" data-dots aria-hidden="true"></div>
    </div>
</section>

<?php require __DIR__ . '/includes/home_all_courses.php'; ?>

<!-- ═══ 2. NEXT LIVE BATCH ════════════════════════════════════════════════ -->
<?php if ($next): $L = lh_lang($next['cat_slug']); [$now, $was] = lh_price($next);
    $seatsLeft = $next['max_students'] > 0 ? max(0, $next['max_students'] - $next['enrolled_count']) : null; ?>
<section class="lh-section lh-section-pass">
    <div class="container lh-pass-wrap">
        <div class="lh-section-head" data-reveal>
            <p class="lh-eyebrow"><?= t('পরবর্তী লাইভ ব্যাচ', 'Next live batch') ?></p>
            <h2><?= t('সিট সীমিত — মাত্র ১৫ জন', 'Only 15 seats per batch') ?></h2>
            <p class="lh-muted"><?= t('শিক্ষকের সাথে সরাসরি ক্লাস, প্রতিটি ক্লাসের রেকর্ডিংসহ।', 'Learn live with a teacher — every class is recorded too.') ?></p>
        </div>
        <div class="lh-tilt-stage" data-reveal="right">
            <article class="lh-pass" style="--lang:var(<?= $L['var'] ?>)" data-tilt>
                <header class="lh-pass-head">
                    <span><?= t('বোর্ডিং পাস', 'Boarding pass') ?></span>
                    <span class="lh-chip lh-chip-live"><i></i><?= t('লাইভ', 'Live') ?></span>
                </header>
                <div class="lh-pass-route">
                    <div><small><?= t('থেকে', 'From') ?></small><b>বাংলা</b></div>
                    <span class="lh-pass-plane material-icons-round" aria-hidden="true">flight_takeoff</span>
                    <div class="lh-pass-to"><small><?= t('গন্তব্য', 'To') ?></small><b><?= e($L['native']) ?></b></div>
                </div>
                <h3 class="lh-pass-title"><?= t($next['title_bn'], $next['title_en'] ?: $next['title_bn']) ?></h3>
                <dl class="lh-pass-grid">
                    <div><dt><?= t('শুরু', 'Starts') ?></dt><dd><?= lh_date($next['batch_start']) ?></dd></div>
                    <div><dt><?= t('ক্লাস', 'Classes') ?></dt><dd><?= t_num((int)$next['total_lessons']) ?></dd></div>
                    <div><dt><?= t('সিট বাকি', 'Seats left') ?></dt><dd class="<?= $seatsLeft !== null && $seatsLeft <= 5 ? 'lh-hot' : '' ?>"><?= $seatsLeft === null ? '—' : t_num($seatsLeft) . '/' . t_num((int)$next['max_students']) ?></dd></div>
                </dl>
                <div class="lh-pass-countdown" data-countdown="<?= e(date('c', strtotime($next['batch_start'] . ' 00:00:00'))) ?>">
                    <div><b data-d>--</b><small><?= t('দিন', 'days') ?></small></div>
                    <div><b data-h>--</b><small><?= t('ঘণ্টা', 'hrs') ?></small></div>
                    <div><b data-m>--</b><small><?= t('মিনিট', 'min') ?></small></div>
                    <div><b data-s>--</b><small><?= t('সেকেন্ড', 'sec') ?></small></div>
                </div>
                <div class="lh-pass-perf" aria-hidden="true"></div>
                <footer class="lh-pass-foot">
                    <div>
                        <span class="lh-price">৳<?= t_num(format_price($now)) ?></span>
                        <?php if ($was): ?><s>৳<?= t_num(format_price($was)) ?></s><?php endif; ?>
                    </div>
                    <a href="<?= e(course_url($next)) ?>" class="btn btn-accent"><?= t('সিট বুক করুন', 'Book a seat') ?></a>
                </footer>
            </article>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ═══ 5. LIVE vs RECORDED (sliding switch) ══════════════════════════════ -->
<section class="lh-section lh-section-tint">
    <div class="container lh-narrow">
        <div class="lh-section-head lh-center" data-reveal>
            <p class="lh-eyebrow"><?= t('দুইভাবে শিখুন', 'Two ways to learn') ?></p>
            <h2><?= t('আপনার জন্য কোনটি?', 'Which one fits you?') ?></h2>
        </div>
        <div class="lh-switch" data-switch data-reveal>
            <div class="lh-seg" role="tablist">
                <span class="lh-seg-thumb" aria-hidden="true"></span>
                <button type="button" role="tab" aria-selected="true" data-tab="0"><span class="lh-chip-dot"></span><?= t('লাইভ ব্যাচ', 'Live batch') ?></button>
                <button type="button" role="tab" aria-selected="false" data-tab="1"><?= t('রেকর্ডেড', 'Recorded') ?></button>
            </div>
            <div class="lh-panes">
                <div class="lh-panes-track">
                    <article class="lh-plan lh-plan-live" role="tabpanel">
                        <h3><?= t('শিক্ষকের সাথে সরাসরি', 'Face-to-face with a teacher') ?></h3>
                        <ul>
                            <li><?= t('ব্যাচে সর্বোচ্চ ১৫ জন', 'At most 15 students per batch') ?></li>
                            <li><?= t('সরাসরি প্রশ্ন ও স্পিকিং প্র্যাকটিস', 'Ask questions, practise speaking live') ?></li>
                            <li><?= t('প্রতিটি ক্লাসের রেকর্ডিং', 'Recording of every class') ?></li>
                            <li><?= t('নির্দিষ্ট রুটিন ও পরীক্ষার প্রস্তুতি', 'Fixed schedule and exam preparation') ?></li>
                        </ul>
                        <a href="<?= APP_URL ?>/courses?type=live" class="btn btn-primary lh-btn-block"><?= t('লাইভ ব্যাচ দেখুন', 'See live batches') ?></a>
                    </article>
                    <article class="lh-plan" role="tabpanel">
                        <h3><?= t('নিজের সময়ে, নিজের গতিতে', 'Your time, your pace') ?></h3>
                        <ul>
                            <li><?= t('যখন খুশি, যতবার খুশি দেখুন', 'Watch any time, as often as you like') ?></li>
                            <li><?= t('ছোট ছোট ভিডিও লেসন', 'Short, focused video lessons') ?></li>
                            <li><?= t('কম খরচে শুরু', 'Lower price to get started') ?></li>
                            <li><?= t('মোবাইলে দেখার জন্য তৈরি', 'Made for watching on your phone') ?></li>
                        </ul>
                        <a href="<?= APP_URL ?>/courses?type=recorded" class="btn btn-outline lh-btn-block"><?= t('রেকর্ডেড কোর্স দেখুন', 'See recorded courses') ?></a>
                    </article>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ═══ 6. FAQ ═════════════════════════════════════════════════════════════ -->
<section class="lh-section">
    <div class="container lh-narrow">
        <div class="lh-section-head lh-center" data-reveal>
            <p class="lh-eyebrow"><?= t('প্রশ্নোত্তর', 'FAQ') ?></p>
            <h2><?= t('যা জানতে চান', 'Good questions') ?></h2>
        </div>
        <div class="lh-faq" data-reveal-group>
            <?php foreach ($faqs as [$qbn, $qen, $abn, $aen]): ?>
            <details>
                <summary><?= t($qbn, $qen) ?><span class="material-icons-round" aria-hidden="true">add</span></summary>
                <div class="lh-faq-a"><p><?= t($abn, $aen) ?></p></div>
            </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
