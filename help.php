<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();

$submitSuccess = $submitError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if (!is_logged_in()) {
        flash('error', 'Please log in to send a message.');
        redirect(APP_URL . '/login.php?next=' . urlencode(APP_URL . '/help.php'));
    }
    $uid     = (int)current_user()['id'];
    $subject = sanitize_string($_POST['subject'] ?? '', 255);
    $body    = sanitize_string($_POST['body'] ?? '', 2000);

    if (mb_strlen($subject, 'UTF-8') < 3) {
        $submitError = 'Subject must be at least 3 characters.';
    } elseif (mb_strlen($body, 'UTF-8') < 20) {
        $submitError = 'Message must be at least 20 characters.';
    } else {
        $newTicketId = db_insert("INSERT INTO support_tickets (user_id,subject,body,created_at) VALUES (?,?,?,NOW())",
            [$uid, $subject, $body]);
        db_exec("INSERT INTO support_messages (ticket_id, sender_id, body, created_at) VALUES (?, ?, ?, NOW())", 
            [$newTicketId, $uid, $body]);
        $submitSuccess = 'Your message has been sent. We will get back to you soon.';
    }
}

// Load previous tickets if logged in
$myTickets = [];
if (is_logged_in()) {
    $uid = (int)current_user()['id'];
    $myTickets = db_query("SELECT * FROM support_tickets WHERE user_id=? ORDER BY created_at DESC LIMIT 10", [$uid]);
}

$contactPhone = get_setting('contact_phone', '+8801577588132');
$contactEmail = get_setting('contact_email', '');
$page_title = 'Help Center';
$extra_css = '<style>
.help-contact-card{background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--r-xl);padding:var(--sp-6);display:flex;align-items:flex-start;gap:var(--sp-4)}
.help-icon{width:52px;height:52px;border-radius:var(--r-lg);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.ticket-row{background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--r-lg);padding:var(--sp-4) var(--sp-5);margin-bottom:var(--sp-3)}
</style>';
include __DIR__ . '/includes/header.php';
?>

<!-- Hero -->
<div style="background:linear-gradient(135deg,#0F1A3E 0%,#1C0B52 100%);padding:var(--sp-12) 0 var(--sp-10)">
    <div class="container" style="text-align:center">
        <div style="width:64px;height:64px;border-radius:var(--r-xl);background:rgba(91, 33, 182,.3);border:1px solid rgba(91, 33, 182,.5);display:flex;align-items:center;justify-content:center;margin:0 auto var(--sp-4)">
            <span class="material-icons-round" style="font-size:32px;color:#A78BFA">support_agent</span>
        </div>
        <h1 style="color:#fff;font-size:clamp(1.5rem,3vw,2.2rem);margin-bottom:8px">Help Center</h1>
        <p style="color:rgba(255,255,255,.65);font-size:var(--text-base);max-width:520px;margin:0 auto">
            We're here for any problem you have. Send a message, call, or email us.
        </p>
    </div>
</div>

<div class="container" style="padding-top:var(--sp-10);padding-bottom:var(--sp-16)">
    <div style="display:grid;grid-template-columns:1fr 380px;gap:var(--sp-8)" class="help-grid">

        <!-- Left: contact form -->
        <div>
            <h2 style="margin-bottom:var(--sp-6)">Contact Us</h2>

            <?php if ($submitSuccess): ?>
            <div class="alert alert-success" style="margin-bottom:var(--sp-5)">
                <span class="material-icons-round">check_circle</span>
                <span><?= e($submitSuccess) ?></span>
            </div>
            <?php elseif ($submitError): ?>
            <div class="alert alert-error" style="margin-bottom:var(--sp-5)">
                <span class="material-icons-round">error</span>
                <span><?= e($submitError) ?></span>
            </div>
            <?php endif; ?>

            <?php if (!is_logged_in()): ?>
            <div style="text-align:center;padding:var(--sp-10);background:var(--c-surface);border-radius:var(--r-xl);border:2px dashed var(--c-border)">
                <span class="material-icons-round" style="font-size:48px;color:var(--c-ink-faint);display:block;margin-bottom:12px">lock</span>
                <h4>Login Required</h4>
                <p style="color:var(--c-ink-muted);font-size:var(--text-sm);margin-bottom:var(--sp-4)">Log in to your account to send a message.</p>
                <a href="<?= APP_URL ?>/login.php?next=<?= urlencode(APP_URL.'/help.php') ?>" class="btn btn-primary">Log In</a>
            </div>
            <?php else: ?>
            <div class="card" style="padding:var(--sp-6)">
                <form method="POST" data-loading>
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label">Subject *</label>
                        <select name="subject" class="form-control" required>
                            <option value="">Choose a subject</option>
                            <option value="Course-related issue">Course-related issue</option>
                            <option value="Payment issue">Payment issue</option>
                            <option value="Login / account issue">Login / account issue</option>
                            <option value="Exam-related issue">Exam-related issue</option>
                            <option value="Video not playing">Video not playing</option>
                            <option value="Haven't received certificate">Haven't received certificate</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Message *</label>
                        <textarea name="body" class="form-control" rows="6" required minlength="20" maxlength="2000"
                                  placeholder="Describe your problem in detail..."></textarea>
                        <div class="form-hint">Writing more detail helps us help you faster.</div>
                    </div>
                    <button type="submit" class="btn btn-primary" data-loading="Sending...">
                        <span class="material-icons-round">send</span> Send Message
                    </button>
                </form>
            </div>

            <!-- My previous tickets -->
            <?php if (!empty($myTickets)): ?>
            <h3 style="margin-top:var(--sp-8);margin-bottom:var(--sp-4)">My Previous Messages</h3>
            <div style="display:flex;flex-direction:column;gap:var(--sp-3)">
            <?php foreach ($myTickets as $tk): ?>
            <a href="<?= APP_URL ?>/help_ticket.php?id=<?= $tk['id'] ?>" class="ticket-row" style="display:block;text-decoration:none;transition:transform 0.2s;border:1px solid var(--c-border);border-radius:var(--r-md);padding:var(--sp-4);background:var(--c-surface)">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--sp-2);margin-bottom:var(--sp-2)">
                    <span style="font-weight:700;font-size:var(--text-md);color:var(--c-ink)"><?= e($tk['subject']) ?></span>
                    <span class="status-badge <?= match($tk['status']) { 'open' => 'status-pending', 'replied' => 'status-active', 'closed' => 'status-archived', default => 'status-pending' } ?>">
                        <?= match($tk['status']) { 'open' => 'Pending', 'replied' => 'Replied', 'closed' => 'Closed', default => 'Pending' } ?>
                    </span>
                </div>
                
                <?php 
                $lastMsg = db_row("SELECT * FROM support_messages WHERE ticket_id=? ORDER BY created_at DESC LIMIT 1", [$tk['id']]);
                if ($lastMsg):
                    $isAdmin = ((int)$lastMsg['sender_id'] === 0);
                ?>
                <p style="font-size:var(--text-sm);color:var(--c-ink-muted);margin-bottom:var(--sp-2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                    <strong style="color:<?= $isAdmin ? 'var(--c-primary)' : 'var(--c-ink)' ?>"><?= $isAdmin ? 'Support Team' : 'You' ?>:</strong> <?= e(mb_substr($lastMsg['body'], 0, 100, 'UTF-8')) ?>...
                </p>
                <?php endif; ?>
                
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:var(--sp-3)">
                    <div style="font-size:12px;color:var(--c-ink-faint)"><?= date('d M Y, h:i A', strtotime($tk['created_at'])) ?></div>
                    <div style="font-size:13px;font-weight:600;color:var(--c-primary);display:flex;align-items:center;gap:4px">View Conversation <span class="material-icons-round" style="font-size:16px">arrow_forward</span></div>
                </div>
            </a>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Right: contact info -->
        <div>
            <h3 style="margin-bottom:var(--sp-5)">Direct Contact</h3>

            <div style="display:flex;flex-direction:column;gap:var(--sp-4);margin-bottom:var(--sp-8)">
                <!-- Phone -->
                <div class="help-contact-card">
                    <div class="help-icon" style="background:rgba(21,190,139,.12)">
                        <span class="material-icons-round" style="font-size:26px;color:var(--c-success)">phone</span>
                    </div>
                    <div>
                        <div style="font-weight:700;margin-bottom:4px">Helpline</div>
                        <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $contactPhone)) ?>" style="color:var(--c-ink-light);font-size:var(--text-lg);font-family:var(--font-latin);font-weight:700;text-decoration:none"><?= e($contactPhone) ?></a>
                        <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:4px">Every day, 9 AM – 10 PM</div>
                    </div>
                </div>

                <!-- Email -->
                <div class="help-contact-card">
                    <div class="help-icon" style="background:rgba(91, 33, 182,.12)">
                        <span class="material-icons-round" style="font-size:26px;color:var(--c-primary)">email</span>
                    </div>
                    <div>
                        <div style="font-weight:700;margin-bottom:4px">Email</div>
                        <a href="mailto:<?= e($contactEmail) ?>" style="color:var(--c-ink-light);font-size:var(--text-sm);font-weight:700;text-decoration:none;word-break:break-all"><?= e($contactEmail) ?></a>
                        <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:4px">Usually replies within 24 hours</div>
                    </div>
                </div>
            </div>

            <!-- FAQ quick links -->
            <div class="card" style="padding:var(--sp-5)">
                <h4 style="margin-bottom:var(--sp-4)">Frequently Asked Questions</h4>
                <?php $faqs = [
                    ['How do I buy a course?', 'Go to the course page, click "Enroll", and complete the payment.'],
                    ['I paid but don\'t have access to the course?', 'Courses activate within 1 hour of payment confirmation at most.'],
                    ['Video not loading?', 'Check your internet connection. Refresh the page or try a different browser.'],
                    ['How do I get my certificate?', 'Once you complete 100% of a course, you can download the certificate from the certificates page.'],
                ]; ?>
                <?php foreach ($faqs as [$q, $a]): ?>
                <details style="border-bottom:1px solid var(--c-border);padding:var(--sp-3) 0" class="faq-item">
                    <summary style="font-weight:700;font-size:var(--text-sm);cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between">
                        <?= $q ?>
                        <span class="material-icons-round" style="font-size:18px;color:var(--c-ink-muted);flex-shrink:0">expand_more</span>
                    </summary>
                    <p style="font-size:var(--text-sm);color:var(--c-ink-muted);margin:var(--sp-2) 0 0;line-height:1.7"><?= $a ?></p>
                </details>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<style>
@media(max-width:1024px){.help-grid{grid-template-columns:1fr !important}}
details summary::-webkit-details-marker{display:none}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
