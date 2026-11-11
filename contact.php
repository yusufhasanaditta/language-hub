<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();

// Self-healing table — no manual SQL import needed after upload.
try {
    db_exec("SELECT 1 FROM contact_messages LIMIT 1");
} catch (PDOException $e) {
    db_exec("CREATE TABLE IF NOT EXISTS contact_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        email VARCHAR(190) NOT NULL,
        phone VARCHAR(50) NULL,
        subject VARCHAR(200) NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash('error', 'Session expired. Please try again.');
        redirect(APP_URL . '/contact.php');
    }
    $name    = sanitize_string($_POST['name'] ?? '', 150);
    $email   = sanitize_string($_POST['email'] ?? '', 190);
    $phone   = sanitize_string($_POST['phone'] ?? '', 50);
    $subject = sanitize_string($_POST['subject'] ?? '', 200);
    $message = trim((string)($_POST['message'] ?? ''));

    if (!$name || !$email || !$message) {
        flash('error', 'Please fill in your name, email, and message.');
        redirect(APP_URL . '/contact.php');
    }
    if (!validate_email($email)) {
        flash('error', 'Please enter a valid email address.');
        redirect(APP_URL . '/contact.php');
    }

    db_insert(
        "INSERT INTO contact_messages (name, email, phone, subject, message) VALUES (?,?,?,?,?)",
        [$name, $email, $phone ?: null, $subject ?: null, mb_substr($message, 0, 5000, 'UTF-8')]
    );

    flash('success', "Thanks for reaching out! We'll get back to you soon.");
    redirect(APP_URL . '/contact.php');
}

$contactEmail = get_setting('contact_email', '');
$contactPhone = get_setting('contact_phone', '+8801577588132');

$page_title = 'Contact Us';
$page_desc  = 'Get in touch with the ' . APP_NAME . ' team — questions, feedback, or support.';
include __DIR__ . '/includes/header.php';
?>

<section class="section-sm" style="background:linear-gradient(135deg,#1A1033,#2A0E4A);padding:var(--sp-16) 0">
    <div class="container" style="text-align:center;color:#fff">
        <div class="section-label" style="background:rgba(255,255,255,.12);color:rgba(255,255,255,.9)">Contact Us</div>
        <h1 style="font-size:clamp(1.8rem,4vw,3rem);color:#fff;margin:var(--sp-3) 0">We'd love to hear from you</h1>
        <p style="color:rgba(255,255,255,.75);max-width:640px;margin:0 auto;font-size:var(--text-lg)">
            Questions about a course, a payment, or just feedback — send us a message and our team will
            reply as soon as possible.
        </p>
    </div>
</section>

<section class="section">
    <div class="container" style="max-width:1000px">
        <div style="display:grid;grid-template-columns:1fr 1.4fr;gap:var(--sp-8)" class="contact-grid">

            <!-- Contact info -->
            <div style="display:flex;flex-direction:column;gap:var(--sp-5)">
                <?php if ($contactPhone): ?>
                <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $contactPhone)) ?>"
                   style="display:flex;align-items:center;gap:var(--sp-4);text-decoration:none;color:inherit;background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--r-lg);padding:var(--sp-5)">
                    <div style="width:48px;height:48px;background:var(--c-primary-light);border-radius:50%;display:flex;align-items:center;justify-content:center;flex:0 0 auto">
                        <span class="material-icons-round" style="color:var(--c-primary)">call</span>
                    </div>
                    <div>
                        <div style="font-weight:700">Call Us</div>
                        <div style="color:var(--c-ink-muted)"><?= e($contactPhone) ?></div>
                    </div>
                </a>
                <?php endif; ?>

                <a href="mailto:<?= e($contactEmail) ?>"
                   style="display:flex;align-items:center;gap:var(--sp-4);text-decoration:none;color:inherit;background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--r-lg);padding:var(--sp-5)">
                    <div style="width:48px;height:48px;background:var(--c-primary-light);border-radius:50%;display:flex;align-items:center;justify-content:center;flex:0 0 auto">
                        <span class="material-icons-round" style="color:var(--c-primary)">mail</span>
                    </div>
                    <div>
                        <div style="font-weight:700">Email Us</div>
                        <div style="color:var(--c-ink-muted);word-break:break-all"><?= e($contactEmail) ?></div>
                    </div>
                </a>

                <div style="display:flex;align-items:center;gap:var(--sp-4);background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--r-lg);padding:var(--sp-5)">
                    <div style="width:48px;height:48px;background:var(--c-primary-light);border-radius:50%;display:flex;align-items:center;justify-content:center;flex:0 0 auto">
                        <span class="material-icons-round" style="color:var(--c-primary)">schedule</span>
                    </div>
                    <div>
                        <div style="font-weight:700">Support Hours</div>
                        <div style="color:var(--c-ink-muted)">Every day, 9 AM – 10 PM</div>
                    </div>
                </div>
            </div>

            <!-- Contact form -->
            <form method="POST" style="background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--r-lg);padding:var(--sp-6)">
                <?= csrf_field() ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)" class="contact-form-row">
                    <div class="form-group">
                        <label class="form-label">Your Name</label>
                        <input type="text" name="name" class="form-control" required maxlength="150"
                               value="<?= e($me['name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required maxlength="190"
                               value="<?= e($me['email'] ?? '') ?>">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)" class="contact-form-row">
                    <div class="form-group">
                        <label class="form-label">Phone (optional)</label>
                        <input type="tel" name="phone" class="form-control" maxlength="50">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Subject</label>
                        <input type="text" name="subject" class="form-control" maxlength="200">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Message</label>
                    <textarea name="message" class="form-control" rows="6" required maxlength="5000"></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-lg" style="width:100%">
                    <span class="material-icons-round">send</span> Send Message
                </button>
            </form>
        </div>
    </div>
</section>

<style>
@media (max-width: 768px) {
    .contact-grid { grid-template-columns: 1fr !important; }
    .contact-form-row { grid-template-columns: 1fr !important; }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
