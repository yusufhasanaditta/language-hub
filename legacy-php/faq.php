<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lh_components.php';

session_start_secure();
send_security_headers();

require __DIR__ . '/includes/faqs_all.php';
$contactEmail = get_setting('contact_email', '');
$page_title = 'FAQ';
include __DIR__ . '/includes/header.php';
?>

<section class="lh-list-head lh-page-hero">
    <div class="lh-glow" aria-hidden="true"></div>
    <div class="container lh-narrow" data-reveal>
        <p class="lh-eyebrow"><?= t('প্রশ্নোত্তর', 'FAQ') ?></p>
        <h1 class="lh-list-title"><?= t('যা জানতে চান', 'Good questions') ?></h1>
    </div>
</section>

<section class="container lh-narrow lh-page">
    <div class="lh-faq" data-reveal-group>
        <?php foreach ($faqs as $i => [$qbn, $qen, $abn, $aen]): ?>
        <details <?= $i === 0 ? 'open' : '' ?>>
            <summary><?= t($qbn, $qen) ?><span class="material-icons-round" aria-hidden="true">add</span></summary>
            <div class="lh-faq-a"><p><?= t($abn, $aen) ?></p></div>
        </details>
        <?php endforeach; ?>
    </div>
    <div class="lh-c-card lh-page-ask" data-reveal>
        <span class="material-icons-round" aria-hidden="true">support_agent</span>
        <div><h2><?= t('উত্তর পাননি?', 'Still have a question?') ?></h2><p class="lh-muted"><?= t('আমাদের লিখুন — দ্রুত উত্তর দেব।', 'Write to us and we will reply quickly.') ?></p></div>
        <a href="<?= $contactEmail ? 'mailto:' . e($contactEmail) : APP_URL . '/contact.php' ?>" class="btn btn-primary"><?= t('যোগাযোগ', 'Contact') ?></a>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
