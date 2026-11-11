<?php defined('B_ACADEMY') or die('Direct access forbidden.'); ?>
</main><!-- /main-content -->

<!-- Footer -->
<footer class="footer lh-footer">
    <div class="container">
        <div class="lh-footer-cta">
            <div>
                <p class="lh-eyebrow"><?= t("আজই শুরু করুন", "Start today") ?></p>
                <h2><?= t("প্রথম ক্লাস ফ্রি — নতুন ভাষা, নতুন সুযোগ।", "First class is free — new language, new chances.") ?></h2>
            </div>
            <a href="<?= APP_URL ?>/courses?free=1" class="btn btn-accent btn-lg"><?= t("ফ্রি ক্লাস দেখুন", "Watch a free class") ?> <span class="material-icons-round">arrow_forward</span></a>
        </div>

        <div class="footer-grid">
            <div class="footer-col footer-brand-col">
                <a href="<?= APP_URL ?>/" class="footer-logo lh-brand">
                    <img src="<?= APP_URL ?>/image/language-hub-logo.png" alt="" width="36" height="36">
                    <span class="brand-name">Language Hub</span>
                </a>
                <p class="footer-desc"><?= t("বাংলায় বিদেশি ভাষা শেখার অনলাইন স্কুল — চাইনিজ, জাপানিজ, কোরিয়ান ও ইংলিশ।", "An online school for learning foreign languages in Bangla — Chinese, Japanese, Korean and English.") ?></p>
                <div class="footer-socials">
                    <?php foreach (["facebook_url" => "Facebook", "youtube_url" => "YouTube", "whatsapp_url" => "WhatsApp"] as $_k => $_label): $_u = get_setting($_k, ""); if (!$_u) continue; ?>
                    <a href="<?= e($_u) ?>" class="social-link" target="_blank" rel="noopener" aria-label="<?= $_label ?>"><?= e(mb_substr($_label, 0, 1)) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="footer-col">
                <h4 class="footer-heading"><?= t("ভাষা", "Languages") ?></h4>
                <ul class="footer-links">
                    <?php foreach (db_query("SELECT slug, name_bn, name_en FROM categories ORDER BY sort_order") as $_c): ?>
                    <li><a href="<?= APP_URL ?>/category/<?= e($_c["slug"]) ?>"><?= t($_c["name_bn"], $_c["name_en"] ?: $_c["name_bn"]) ?></a></li>
                    <?php endforeach; ?>
                    <li><a href="<?= APP_URL ?>/courses?free=1"><?= t("ফ্রি কোর্স", "Free courses") ?></a></li>
                </ul>
            </div>

            <div class="footer-col">
                <h4 class="footer-heading"><?= t("প্রতিষ্ঠান", "Company") ?></h4>
                <ul class="footer-links">
                    <li><a href="<?= APP_URL ?>/about.php"><?= t("আমাদের সম্পর্কে", "About us") ?></a></li>
                    <li><a href="<?= APP_URL ?>/teachers.php"><?= t("শিক্ষকবৃন্দ", "Teachers") ?></a></li>
                    <li><a href="<?= APP_URL ?>/careers.php"><?= t("ক্যারিয়ার", "Careers") ?></a></li>
                    <li><a href="<?= APP_URL ?>/submit_testimonial.php"><?= t("আপনার গল্প লিখুন", "Share your story") ?></a></li>
                </ul>
            </div>

            <div class="footer-col">
                <h4 class="footer-heading"><?= t("সহায়তা", "Help") ?></h4>
                <ul class="footer-links">
                    <li><a href="<?= APP_URL ?>/faq.php"><?= t("প্রশ্নোত্তর", "FAQ") ?></a></li>
                    <li><a href="<?= APP_URL ?>/refund.php"><?= t("রিফান্ড নীতি", "Refund policy") ?></a></li>
                    <li><a href="<?= APP_URL ?>/privacy.php"><?= t("গোপনীয়তা নীতি", "Privacy policy") ?></a></li>
                    <li><a href="<?= APP_URL ?>/terms.php"><?= t("শর্তাবলি", "Terms") ?></a></li>
                </ul>
                <?php $_footerPhone = get_setting("contact_phone", ""); $_footerEmail = get_setting("contact_email", ""); ?>
                <?php if ($_footerPhone): ?>
                <p class="footer-contact"><span class="material-icons-round">call</span> <a href="tel:<?= e(preg_replace("/[^0-9+]/", "", $_footerPhone)) ?>"><?= e($_footerPhone) ?></a></p>
                <?php endif; ?>
                <?php if ($_footerEmail): ?>
                <p class="footer-contact"><span class="material-icons-round">mail</span> <a href="mailto:<?= e($_footerEmail) ?>"><?= e($_footerEmail) ?></a></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="footer-bottom">
            <p>© <?= date("Y") ?> Language Hub</p>
            <p class="footer-made"><?= t("বাংলাদেশে তৈরি", "Made in Bangladesh") ?></p>
        </div>
    </div>
</footer>

<!-- Bottom nav (mobile) -->
<?php
$_cur = basename($_SERVER['PHP_SELF']);
$_tabs = [
    ["index.php", APP_URL . "/", "home", t("হোম", "Home")],
    ["courses.php", APP_URL . "/courses.php", "explore", t("কোর্স", "Courses")],
    ["live", APP_URL . "/courses.php?type=live", "videocam", t("লাইভ", "Live")],
    ["free", APP_URL . "/courses.php?free=1", "redeem", t("ফ্রি", "Free")],
];
$_tabs[] = is_logged_in()
    ? ["dashboard.php", APP_URL . "/dashboard.php", "person", t("আমি", "Me")]
    : ["login.php", APP_URL . "/login.php", "login", t("লগইন", "Log in")];
// "Courses" tab should not light up when a Live/Free filter is open
if ($_cur === "courses.php" && (isset($_GET["type"]) && $_GET["type"] === "live")) $_cur = "live";
elseif ($_cur === "courses.php" && !empty($_GET["free"])) $_cur = "free";
?>
<nav class="bottom-nav" aria-label="Primary">
    <?php foreach ($_tabs as [$file, $href, $icon, $label]): ?>
        <a href="<?= $href ?>" class="bottom-nav-item <?= $_cur === $file ? 'active' : '' ?>">
            <span class="material-icons-round"><?= $icon ?></span>
            <span><?= $label ?></span>
            <?php if ($file === 'notices.php' && !empty($_noticeUnread)): ?>
                <span class="tab-badge"><?= $_noticeUnread > 9 ? '9+' : (int) $_noticeUnread ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<!-- Video lightbox — any element with data-video plays inside the site -->
<div class="video-modal" id="videoModal" hidden>
    <div class="video-modal-inner">
        <button type="button" class="video-modal-close" aria-label="Close video">
            <span class="material-icons-round">close</span>
        </button>
        <div class="video-modal-frame">
            <iframe id="videoModalFrame" title="Video" allowfullscreen
                allow="autoplay; encrypted-media; picture-in-picture"></iframe>
        </div>
    </div>
</div>

<!-- Main JS -->
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/lh.js?v=<?= filemtime(APP_ROOT . "/assets/js/lh.js") ?>" defer></script>
<?= $extra_js ?? '' ?>
</body>

</html>