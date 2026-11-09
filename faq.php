<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();

$contactEmail = get_setting('contact_email', 'bacademy.class@gmail.com');
$contactPhone = get_setting('contact_phone', '+8801577588132');

$groups = [
    'Getting Started' => [
        ['q' => 'Do I need to create an account to browse courses?', 'a' => 'No — you can browse and search all courses without an account. You only need to register when you\'re ready to enroll.'],
        ['q' => 'Is Language Hub free to use?', 'a' => 'Creating an account is completely free, and we always have a selection of free courses available. Premium courses (live classes, full model test sets, etc.) require payment.'],
        ['q' => 'What devices can I use?', 'a' => 'Language Hub works in any modern browser on desktop, laptop, tablet, or phone — no separate app install required.'],
    ],
    'Payments & Enrollment' => [
        ['q' => 'How do I enroll in a paid course?', 'a' => 'Open the course page and tap Enroll Now. You\'ll be taken to a secure checkout page powered by SSLCommerz, where you can pay by bKash, Nagad, Rocket, or card.'],
        ['q' => 'Is it safe to pay on this site?', 'a' => 'Yes. All payments are processed by SSLCommerz, a licensed payment gateway — we never see or store your card, bKash, or bank details on our own servers.'],
        ['q' => 'Can I use a coupon code?', 'a' => 'Yes, valid coupon codes can be applied at checkout before you pay, and the discount is reflected in the final amount immediately.'],
        ['q' => 'I paid but the course isn\'t showing in "My Courses" yet.', 'a' => 'This is almost always a temporary session hiccup right after payment — log out and back in, then check My Courses again. If it\'s still missing, contact us with your payment reference and we\'ll resolve it right away, no charge lost.'],
        ['q' => 'Can I get an invoice for my payment?', 'a' => 'Yes — every paid order gets an invoice under Orders in your account menu, which you can view or download at any time.'],
        ['q' => 'Can I get a refund?', 'a' => 'In most cases, yes, within the window described on our Refund Policy page.'],
    ],
    'Courses & Live Classes' => [
        ['q' => 'How long do I have access to a course after enrolling?', 'a' => 'Once enrolled, you keep access to the course for as long as it remains available on Language Hub — there\'s no separate expiry timer on your enrollment.'],
        ['q' => 'How do I join a live class?', 'a' => 'Go to My Schedule or the course\'s Live Classes tab a few minutes before the scheduled time. A Join button appears automatically once the class goes live.'],
        ['q' => 'What if I miss a live class?', 'a' => 'Most live classes are recorded and added back to the course as a regular lesson, so you can catch up at your own pace.'],
        ['q' => 'Can I download videos to watch offline?', 'a' => 'This depends on the individual course — where offline download is enabled, you\'ll see a download option on the lesson player.'],
        ['q' => 'Can I ask the teacher questions?', 'a' => 'Yes — most courses have a discussion/Q&A section under each lesson where teachers and TAs answer student questions directly.'],
    ],
    'Exams & Results' => [
        ['q' => 'How do model tests work?', 'a' => 'Each exam has a fixed time limit and question set. Once you submit, your score is calculated instantly and compared against everyone else who has taken that exam.'],
        ['q' => 'Can I retake an exam?', 'a' => 'This depends on how the teacher configured that specific exam — some allow multiple attempts, others allow only one. The exam page will tell you before you start.'],
        ['q' => 'How is my rank calculated?', 'a' => 'Your rank on the leaderboard is based on your score relative to every other student who has attempted the same exam, updated automatically after each submission.'],
    ],
    'Account & Certificates' => [
        ['q' => 'I forgot my password. What do I do?', 'a' => 'On the Login page, tap "Forgot password?" and follow the instructions sent to your registered phone or email.'],
        ['q' => 'How do I update my profile information?', 'a' => 'Go to Profile from your account menu — you can update your name, avatar, and contact details there.'],
        ['q' => 'How do I get a certificate?', 'a' => 'Once you complete 100% of a course, a downloadable certificate automatically becomes available under Certificates in your account menu.'],
        ['q' => 'Can I delete my account?', 'a' => 'Yes — contact us and we\'ll process the deletion in line with our Privacy Policy.'],
    ],
];

$page_title = 'Frequently Asked Questions';
$page_desc  = 'Answers to common questions about ' . APP_NAME . ' — enrollment, payments, live classes, exams, and more.';
include __DIR__ . '/includes/header.php';
?>

<section class="section-sm" style="background:linear-gradient(135deg,#1A1033,#2A0E4A);padding:var(--sp-16) 0">
    <div class="container" style="text-align:center;color:#fff">
        <div class="section-label" style="background:rgba(255,255,255,.12);color:rgba(255,255,255,.9)">FAQ</div>
        <h1 style="font-size:clamp(1.8rem,4vw,3rem);color:#fff;margin:var(--sp-3) 0">Frequently Asked Questions</h1>
        <div style="max-width:520px;margin:var(--sp-6) auto 0">
            <div style="position:relative">
                <span class="material-icons-round" style="position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--c-ink-faint)">search</span>
                <input type="search" data-faq-search placeholder="Search all questions..."
                       style="width:100%;padding:14px 16px 14px 46px;border-radius:var(--r-lg);border:none;font-family:inherit;font-size:var(--text-base)">
            </div>
        </div>
    </div>
</section>

<?php $_gi = 0; foreach ($groups as $groupTitle => $items): $_gi++; ?>
<section class="cs-block <?= $_gi % 2 === 0 ? 'tint' : '' ?>">
    <div class="container">
        <h2 class="section-title" style="text-align:center;margin-bottom:var(--sp-8)"><?= e($groupTitle) ?></h2>
        <div class="cs-faq" data-faq-list>
            <?php foreach ($items as $i => $f): ?>
            <div class="cs-faq-item">
                <button type="button" class="cs-faq-q" aria-expanded="false">
                    <span><?= e($f['q']) ?></span>
                    <span class="material-icons-round">expand_more</span>
                </button>
                <div class="cs-faq-a"><?= nl2br(e($f['a'])) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="faq-empty">No matching questions in this section.</p>
    </div>
</section>
<?php endforeach; ?>

<section class="section">
    <div class="container" style="max-width:640px;text-align:center">
        <div class="section-label">Didn't Find Your Answer?</div>
        <h2 class="section-title">Talk to our support team</h2>
        <div style="display:flex;gap:var(--sp-4);justify-content:center;flex-wrap:wrap;margin-top:var(--sp-4)">
            <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $contactPhone)) ?>" class="btn btn-primary">
                <span class="material-icons-round">call</span> <?= e($contactPhone) ?>
            </a>
            <a href="mailto:<?= e($contactEmail) ?>" class="btn btn-ghost">
                <span class="material-icons-round">mail</span> Email Us
            </a>
            <a href="<?= APP_URL ?>/help.php" class="btn btn-ghost">
                <span class="material-icons-round">support_agent</span> Help Center
            </a>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
