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
$lastUpdated  = '5 August 2026';

$sections = [
    'acceptance'    => 'Acceptance of Terms',
    'accounts'      => 'Accounts',
    'courses'       => 'Courses & Content License',
    'payments'      => 'Payments & Pricing',
    'refunds'       => 'Refunds',
    'live-classes'  => 'Live Classes & Exams',
    'conduct'       => 'Acceptable Use',
    'ip'            => 'Intellectual Property',
    'teachers'      => 'Teacher Accounts',
    'termination'   => 'Suspension & Termination',
    'disclaimers'   => 'Disclaimers',
    'liability'     => 'Limitation of Liability',
    'law'           => 'Governing Law',
    'changes'       => 'Changes to These Terms',
    'contact'       => 'Contact Us',
];

$page_title = 'Terms & Conditions';
$page_desc  = 'The terms and conditions for using ' . APP_NAME . '.';
include __DIR__ . '/includes/header.php';
?>

<?php include __DIR__ . '/includes/legal_page_head.php'; ?>

<section class="section">
    <div class="container">
        <div class="legal-layout">
            <?php include __DIR__ . '/includes/legal_toc.php'; ?>

            <article class="legal-content">

                <p class="legal-lead">
                    These Terms & Conditions govern your use of <?= e(APP_NAME) ?>. By creating an account
                    or using the platform, you agree to these terms.
                </p>

                <h2 id="acceptance">1. Acceptance of Terms</h2>
                <p>
                    By accessing or using <?= e(APP_NAME) ?>, you confirm that you accept these terms and
                    agree to comply with them. If you do not agree, please do not use the platform.
                </p>

                <h2 id="accounts">2. Accounts</h2>
                <ul>
                    <li>You must provide accurate information when registering, and keep your password confidential.</li>
                    <li>You are responsible for all activity that happens under your account.</li>
                    <li>One account is intended for one individual student, teacher, or staff member — accounts may not be shared or resold.</li>
                    <li>You must notify us immediately if you suspect unauthorized access to your account.</li>
                </ul>

                <h2 id="courses">3. Courses & Content License</h2>
                <p>
                    When you enroll in a course, we grant you a personal, non-transferable, non-exclusive
                    license to access that course's videos, materials, and exams for your own learning.
                    You may not:
                </p>
                <ul>
                    <li>Share your login, download links, or course materials with anyone who has not enrolled.</li>
                    <li>Re-upload, resell, broadcast, or publicly share course videos or materials in any form.</li>
                    <li>Use automated tools to scrape, download in bulk, or bypass our video protection.</li>
                </ul>
                <p>Violating this may result in suspension of your account without a refund.</p>

                <h2 id="payments">4. Payments & Pricing</h2>
                <p>
                    Course prices are shown on each course page before you enroll. Payments are processed
                    securely through SSLCommerz (bKash, Nagad, Rocket, and card). Prices may change at any
                    time for future purchases, but will never change retroactively for a course you've
                    already paid for.
                </p>

                <h2 id="refunds">5. Refunds</h2>
                <p>
                    Refund eligibility is governed by our
                    <a href="<?= APP_URL ?>/refund.php">Refund Policy</a>, which forms part of these terms.
                </p>

                <h2 id="live-classes">6. Live Classes & Exams</h2>
                <ul>
                    <li>Live class schedules are set by the course's teacher and may occasionally change; we'll notify enrolled students of any change.</li>
                    <li>Recordings of live classes, where available, are added to the course for enrolled students to review.</li>
                    <li>Exams and model tests must be completed honestly and individually. Impersonation or sharing answers during a timed exam may result in disqualification from the leaderboard and/or account suspension.</li>
                </ul>

                <h2 id="conduct">7. Acceptable Use</h2>
                <p>When using <?= e(APP_NAME) ?>, you agree not to:</p>
                <ul>
                    <li>Post abusive, harassing, or discriminatory content in comments, notices, or messages.</li>
                    <li>Attempt to gain unauthorized access to any part of the platform, other accounts, or our systems.</li>
                    <li>Upload viruses, malware, or attempt to disrupt the service (e.g. spamming forms, automated bot traffic).</li>
                    <li>Impersonate another person or misrepresent your affiliation with any person or entity.</li>
                </ul>
                <p>We may remove content or suspend accounts that violate this section.</p>

                <h2 id="ip">8. Intellectual Property</h2>
                <p>
                    All course content, the <?= e(APP_NAME) ?> name, logo, website design, and underlying
                    software are the property of <?= e(APP_NAME) ?> or its content creators (including
                    individual teachers), and are protected by applicable copyright and trademark laws.
                    Nothing in these terms transfers ownership of that content to you.
                </p>

                <h2 id="teachers">9. Teacher Accounts</h2>
                <p>
                    Teachers who publish courses on <?= e(APP_NAME) ?> retain authorship credit for their
                    content, and are responsible for the accuracy of the material they upload, the fairness
                    of exams they grade, and their conduct in live classes, notices, and student
                    communication. <?= e(APP_NAME) ?> reserves the right to remove content or suspend a
                    teacher account that violates these terms or platform policies.
                </p>

                <h2 id="termination">10. Suspension & Termination</h2>
                <p>
                    We may suspend or terminate an account that violates these terms, engages in fraud
                    (including payment fraud or chargebacks made in bad faith), or misuses the platform. You
                    may also close your own account at any time by contacting us.
                </p>

                <h2 id="disclaimers">11. Disclaimers</h2>
                <p>
                    <?= e(APP_NAME) ?> is provided "as is." While we work hard to keep the platform
                    accurate and available, we do not guarantee that course content is error-free, that
                    exam scores or rankings are free of technical glitches, or that the service will be
                    uninterrupted at all times.
                </p>

                <h2 id="liability">12. Limitation of Liability</h2>
                <p>
                    To the maximum extent permitted by law, <?= e(APP_NAME) ?> is not liable for any
                    indirect, incidental, or consequential damages arising from your use of the platform.
                    Our total liability for any claim relating to a specific course is limited to the
                    amount you paid for that course.
                </p>

                <h2 id="law">13. Governing Law</h2>
                <p>
                    These terms are governed by the laws of Bangladesh. Any disputes will be subject to the
                    jurisdiction of the courts of Bangladesh.
                </p>

                <h2 id="changes">14. Changes to These Terms</h2>
                <p>
                    We may update these terms from time to time. Continued use of <?= e(APP_NAME) ?> after
                    a change means you accept the updated terms. Material changes will be reflected by
                    updating the "Last updated" date at the top of this page.
                </p>

                <h2 id="contact">15. Contact Us</h2>
                <p>
                    Questions about these terms? Reach us at
                    <a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a>
                    or call <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $contactPhone)) ?>"><?= e($contactPhone) ?></a>.
                </p>

            </article>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
