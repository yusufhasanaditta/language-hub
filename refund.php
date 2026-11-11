<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();

$contactEmail = get_setting('contact_email', '');
$contactPhone = get_setting('contact_phone', '+8801577588132');
$lastUpdated  = '5 August 2026';

$sections = [
    'eligibility'  => 'Refund Eligibility',
    'window'       => 'Refund Window',
    'non-refundable' => 'Non-Refundable Cases',
    'live-courses' => 'Live Class Courses',
    'how-to-request' => 'How to Request a Refund',
    'processing'   => 'Processing Time & Method',
    'cancellations' => 'Order Cancellations',
    'contact'      => 'Contact Us',
];

$page_title = 'Refund Policy';
$page_desc  = 'Read ' . APP_NAME . '\'s refund and cancellation policy for course purchases.';
include __DIR__ . '/includes/header.php';
?>

<?php include __DIR__ . '/includes/legal_page_head.php'; ?>

<section class="section">
    <div class="container">
        <div class="legal-layout">
            <?php include __DIR__ . '/includes/legal_toc.php'; ?>

            <article class="legal-content">

                <p class="legal-lead">
                    We want you to be confident in your purchase. This policy explains when a refund is
                    available for a course bought on <?= e(APP_NAME) ?>, and how to request one.
                </p>

                <h2 id="eligibility">1. Refund Eligibility</h2>
                <p>
                    You are eligible for a full refund of a paid course if <strong>all</strong> of the following are true:
                </p>
                <ul>
                    <li>The request is made within the refund window described below, and</li>
                    <li>You have not completed more than 20% of the course's recorded lessons, and</li>
                    <li>You have not attended more than one live class session (for live/hybrid courses), and</li>
                    <li>You have not downloaded course materials in a way that allows offline retention after a refund.</li>
                </ul>

                <h2 id="window">2. Refund Window</h2>
                <p>
                    Refund requests must be made within <strong>7 days</strong> of the payment date. Requests made
                    after this window are reviewed on a case-by-case basis but are not guaranteed.
                </p>

                <h2 id="non-refundable">3. Non-Refundable Cases</h2>
                <p>The following are not eligible for a refund:</p>
                <ul>
                    <li>Free courses (there is no payment to refund).</li>
                    <li>Courses where more than 20% of the recorded content has been accessed.</li>
                    <li>Model tests / exams that have already been attempted and submitted.</li>
                    <li>Certificates already issued for a completed course.</li>
                    <li>Coupon-based promotional or bundle enrollments explicitly marked "non-refundable" at checkout.</li>
                    <li>Requests made after the refund window has closed, unless approved case-by-case.</li>
                </ul>

                <h2 id="live-courses">4. Live Class Courses</h2>
                <p>
                    For courses that include scheduled live classes, you may request a refund only before
                    attending your second live session. Once two or more live sessions have been attended,
                    the enrollment is treated as consumed and is not refundable, since the live seat and
                    teacher's time have already been allocated.
                </p>

                <h2 id="how-to-request">5. How to Request a Refund</h2>
                <p>To request a refund:</p>
                <ol>
                    <li>Go to <strong>Orders</strong> in your account menu and find the relevant invoice, or use our <a href="<?= APP_URL ?>/contact.php">Contact form</a>.</li>
                    <li>Tell us the order/invoice number, the course name, and the reason for the refund.</li>
                    <li>Our support team reviews the request against the eligibility criteria above, usually within 2–3 business days.</li>
                </ol>

                <h2 id="processing">6. Processing Time & Method</h2>
                <p>
                    Approved refunds are returned to the original payment method (bKash, Nagad, Rocket, or
                    card) via our payment gateway, SSLCommerz. Depending on your bank or mobile wallet
                    provider, funds typically appear within <strong>7–10 business days</strong> of approval.
                    We do not charge any processing fee for a refund; however your payment provider's own
                    transaction charges (if any) are non-refundable by us.
                </p>

                <h2 id="cancellations">7. Order Cancellations</h2>
                <p>
                    If a payment fails to complete, or you close the checkout page before finishing payment,
                    no charge is made and there is nothing to refund. If you are charged in error for an
                    order that never activated, contact us immediately with your transaction ID and we'll
                    resolve it as a priority.
                </p>

                <h2 id="contact">8. Contact Us</h2>
                <p>
                    For any refund question, reach us at
                    <a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a>
                    or call <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $contactPhone)) ?>"><?= e($contactPhone) ?></a>.
                    You can also use our <a href="<?= APP_URL ?>/contact.php">Contact page</a>.
                </p>

            </article>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
