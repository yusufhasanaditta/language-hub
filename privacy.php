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
    'overview'       => 'Overview',
    'info-we-collect' => 'Information We Collect',
    'how-we-use'     => 'How We Use Your Information',
    'payments'       => 'Payment Information',
    'cookies'        => 'Cookies & Local Storage',
    'notifications'  => 'Notifications',
    'third-parties'  => 'Third-Party Services',
    'data-sharing'   => 'Data Sharing',
    'data-retention' => 'Data Retention',
    'your-rights'    => 'Your Rights & Choices',
    'children'       => "Children's Privacy",
    'security'       => 'Security',
    'changes'        => 'Changes to This Policy',
    'contact'        => 'Contact Us',
];

$page_title = 'Privacy Policy';
$page_desc  = 'How ' . APP_NAME . ' collects, uses, and protects your personal information.';
include __DIR__ . '/includes/header.php';
?>

<?php include __DIR__ . '/includes/legal_page_head.php'; ?>

<section class="section">
    <div class="container">
        <div class="legal-layout">
            <?php include __DIR__ . '/includes/legal_toc.php'; ?>

            <article class="legal-content">

                <p class="legal-lead">
                    This policy explains what personal information <?= e(APP_NAME) ?> collects, why we
                    collect it, and how we protect it. By using <?= e(APP_NAME) ?>, you agree to the
                    practices described here.
                </p>

                <h2 id="overview">1. Overview</h2>
                <p>
                    <?= e(APP_NAME) ?> ("we", "us", "our") operates an online learning platform for
                    students in Bangladesh, offering recorded courses, live classes, model tests, and an
                    admission tracker. This policy applies to everyone who visits our website or creates
                    an account.
                </p>

                <h2 id="info-we-collect">2. Information We Collect</h2>
                <p>We collect the following categories of information:</p>
                <ul>
                    <li><strong>Account information</strong> — your name, phone number, email address, and password (stored securely hashed, never in plain text).</li>
                    <li><strong>Profile information</strong> — an optional avatar, bio, and other details you choose to add.</li>
                    <li><strong>Course activity</strong> — enrollments, lesson progress, exam attempts and scores, live class attendance, and messages/comments you post.</li>
                    <li><strong>Order information</strong> — invoices, order status, and coupon usage (see the Payment Information section below for how actual payment details are handled).</li>
                    <li><strong>Technical information</strong> — IP address, browser/device type, and pages visited, used for analytics and security (e.g. rate-limiting abusive requests).</li>
                    <li><strong>Contact form / support messages</strong> — whatever you submit through our Contact form or Help Center support tickets.</li>
                </ul>

                <h2 id="how-we-use">3. How We Use Your Information</h2>
                <ul>
                    <li>To create and manage your account and enrollments.</li>
                    <li>To deliver course content, live class access, exams, and certificates.</li>
                    <li>To process payments and issue invoices.</li>
                    <li>To send account-related notifications (payment confirmations, grading results, live class reminders, notices from your teachers).</li>
                    <li>To respond to support requests and contact form submissions.</li>
                    <li>To monitor site traffic and improve the platform (aggregated, non-identifying analytics).</li>
                    <li>To detect and prevent fraud, abuse, and security threats.</li>
                </ul>

                <h2 id="payments">4. Payment Information</h2>
                <p>
                    All payments are processed by <strong>SSLCommerz</strong>, a licensed third-party
                    payment gateway. When you pay by bKash, Nagad, Rocket, or card, your payment
                    credentials are entered directly on SSLCommerz's secure page — <?= e(APP_NAME) ?> never
                    receives or stores your card number, PIN, or mobile wallet password. We only receive
                    confirmation that a payment succeeded, along with a transaction reference, which we use
                    to activate your enrollment and generate your invoice.
                </p>

                <h2 id="cookies">5. Cookies & Local Storage</h2>
                <p>
                    We use a session cookie to keep you logged in and to protect the site against
                    cross-site request forgery (CSRF). We also use your browser's local storage for small
                    UI preferences, such as remembering that you dismissed the notification permission
                    prompt. We do not use third-party advertising or tracking cookies.
                </p>

                <h2 id="notifications">6. Notifications</h2>
                <p>
                    If you allow browser/push notifications, we use that permission solely to notify you
                    about your own account activity — payments, grading results, live class schedules, and
                    notices relevant to you. You can revoke this permission at any time from your browser's
                    site settings.
                </p>

                <h2 id="third-parties">7. Third-Party Services</h2>
                <p>We rely on a small number of trusted third-party services to operate the platform:</p>
                <ul>
                    <li><strong>SSLCommerz</strong> — payment processing.</li>
                    <li><strong>Google Fonts</strong> — web fonts, loaded from Google's servers.</li>
                    <li><strong>Video/streaming providers</strong> — for course video playback, where used.</li>
                    <li><strong>Push notification services</strong> (e.g. browser vendors' push infrastructure) — to deliver notification popups you've opted into.</li>
                </ul>
                <p>Each of these providers has its own privacy practices governing the technical data needed to perform their function.</p>

                <h2 id="data-sharing">8. Data Sharing</h2>
                <p>
                    We do not sell your personal information. We share information only: with the teachers
                    of courses you're enrolled in (for grading and communication), with SSLCommerz to
                    process a payment, or when required by law.
                </p>

                <h2 id="data-retention">9. Data Retention</h2>
                <p>
                    We retain account and course activity data for as long as your account is active, and
                    for a reasonable period afterward to comply with financial record-keeping requirements
                    (e.g. invoices). You may request deletion at any time — see Your Rights below.
                </p>

                <h2 id="your-rights">10. Your Rights & Choices</h2>
                <ul>
                    <li>You can view and update most of your profile information directly from your account.</li>
                    <li>You can request a copy of the personal data we hold about you.</li>
                    <li>You can request that we delete your account and associated personal data, subject to any records we're legally required to keep (e.g. paid invoices).</li>
                    <li>You can revoke notification permissions at any time from your browser settings.</li>
                </ul>
                <p>To exercise any of these, contact us using the details below.</p>

                <h2 id="children">11. Children's Privacy</h2>
                <p>
                    <?= e(APP_NAME) ?> is intended for HSC and admission-test students. If you are under
                    18, please use the platform with a parent or guardian's awareness, particularly for
                    payments.
                </p>

                <h2 id="security">12. Security</h2>
                <p>
                    We use industry-standard practices to protect your data, including hashed passwords,
                    encrypted connections (HTTPS), CSRF protection, and rate-limiting against abuse. No
                    method of transmission or storage is 100% secure, but we work to protect your
                    information to the best of our ability.
                </p>

                <h2 id="changes">13. Changes to This Policy</h2>
                <p>
                    We may update this policy from time to time. Material changes will be reflected by
                    updating the "Last updated" date at the top of this page.
                </p>

                <h2 id="contact">14. Contact Us</h2>
                <p>
                    Questions about this policy or your data? Reach us at
                    <a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a>
                    or call <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $contactPhone)) ?>"><?= e($contactPhone) ?></a>.
                </p>

            </article>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
