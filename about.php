<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lh_components.php';

session_start_secure();
send_security_headers();

$langs = db_query("SELECT cat.*, COUNT(c.id) n FROM categories cat LEFT JOIN courses c ON c.category_id=cat.id AND c.status='published' GROUP BY cat.id ORDER BY cat.sort_order");
$page_title = 'About';
$page_desc  = 'Language Hub — বাংলায় চাইনিজ, জাপানিজ, কোরিয়ান ও ইংলিশ শেখার অনলাইন স্কুল।';
include __DIR__ . '/includes/header.php';
?>

<section class="lh-list-head lh-page-hero">
    <div class="lh-glow" aria-hidden="true"></div>
    <div class="container lh-narrow" data-reveal>
        <p class="lh-eyebrow"><?= t('আমাদের সম্পর্কে', 'About us') ?></p>
        <h1 class="lh-list-title"><?= t('নতুন ভাষা, নতুন সুযোগ', 'New language, new chances') ?></h1>
        <p class="lh-lead"><?= t('Language Hub একটি অনলাইন স্কুল, যেখানে বাংলায় চাইনিজ, জাপানিজ, কোরিয়ান ও ইংলিশ শেখানো হয় — একদম শুরু থেকে পরীক্ষা পর্যন্ত।',
            'Language Hub is an online school that teaches Chinese, Japanese, Korean and English in Bangla — from the very first word to exam day.') ?></p>
    </div>
</section>

<section class="container lh-narrow lh-page">
    <div class="lh-c-card" data-reveal>
        <h2><?= t('কেন শুরু করলাম', 'Why we started') ?></h2>
        <div class="lh-prose"><p><?= t('বিদেশে পড়াশোনা, চাকরি বা নিজের আগ্রহে অনেকেই নতুন ভাষা শিখতে চান — কিন্তু ভালো ক্লাস প্রায়ই ইংরেজিতে, দামি, আর ঢাকার বাইরে পাওয়া কঠিন। আমরা চাই যেকোনো জায়গা থেকে, ফোন দিয়েই, বাংলায় বুঝে বুঝে ভাষা শেখা যাক।',
            'Many people want a new language for study abroad, work or simply love of it — but good classes are often in English, expensive and hard to find outside Dhaka. We want anyone, anywhere, to learn on their phone, with everything explained in Bangla.') ?></p></div>
    </div>

    <div class="lh-quick lh-values" data-reveal-group>
        <?php foreach ([
            ['translate', t('বাংলায় শেখানো', 'Taught in Bangla'), t('প্রতিটি নিয়ম, প্রতিটি শব্দ বাংলায় বোঝানো হয়।', 'Every rule and word is explained in Bangla.')],
            ['groups', t('ছোট ব্যাচ', 'Small batches'), t('লাইভ ব্যাচে সর্বোচ্চ ১৫ জন — সবাই কথা বলার সুযোগ পায়।', 'At most 15 per live batch, so everyone gets to speak.')],
            ['smartphone', t('ফোনেই সব', 'All on your phone'), t('ক্লাস, রেকর্ডিং, পেমেন্ট — সব মোবাইলে।', 'Classes, recordings and payment — all on mobile.')],
        ] as [$icon, $title, $desc]): ?>
        <div class="lh-value"><span class="material-icons-round" aria-hidden="true"><?= $icon ?></span><b><?= $title ?></b><p><?= $desc ?></p></div>
        <?php endforeach; ?>
    </div>

    <div class="lh-c-card" data-reveal>
        <h2><?= t('যে ভাষাগুলো শেখাই', 'Languages we teach') ?></h2>
        <div class="lh-mycourses">
            <?php foreach ($langs as $cat): $L = lh_lang($cat['slug']); ?>
            <a class="lh-mycourse" href="<?= APP_URL ?>/category/<?= e($cat['slug']) ?>" style="--lang:var(<?= $L['var'] ?>)">
                <span class="lh-co-glyph" aria-hidden="true"><?= e($L['glyph']) ?></span>
                <span class="lh-mycourse-body"><b><?= t($cat['name_bn'], $cat['name_en']) ?></b><span class="lh-ticket-meta"><?= e($L['hello']) ?> · <?= $L['exam'] ? e($L['exam']) . ' · ' : '' ?><?= t_num((int)$cat['n']) ?> <?= t('কোর্স', 'courses') ?></span></span>
                <span class="lh-ticket-go material-icons-round" aria-hidden="true">arrow_forward</span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="lh-page-cta" data-reveal>
        <a href="<?= APP_URL ?>/#courses" class="btn btn-primary"><?= t('কোর্স দেখুন', 'Browse courses') ?></a>
        <a href="<?= APP_URL ?>/contact.php" class="btn btn-outline"><?= t('যোগাযোগ', 'Contact us') ?></a>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
