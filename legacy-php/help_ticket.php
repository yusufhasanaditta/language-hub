<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_login();

$uid = (int)current_user()['id'];
$ticketId = sanitize_int($_GET['id'] ?? 0);

$ticket = db_row("SELECT * FROM support_tickets WHERE id=? AND user_id=?", [$ticketId, $uid]);
if (!$ticket) {
    flash('error', 'Ticket not found.');
    redirect(APP_URL . '/help.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if ($ticket['status'] === 'closed') {
        flash('error', 'Cannot reply to a closed ticket.');
    } else {
        $body = sanitize_string($_POST['body'] ?? '', 2000);
        if (mb_strlen($body, 'UTF-8') < 2) {
            flash('error', 'Message is too short.');
        } else {
            db_exec("INSERT INTO support_messages (ticket_id, sender_id, body, created_at) VALUES (?, ?, ?, NOW())", [$ticketId, $uid, $body]);
            db_exec("UPDATE support_tickets SET status='open' WHERE id=?", [$ticketId]); // Back to open when student replies
            flash('success', 'Reply sent.');
            redirect(APP_URL . '/help_ticket.php?id=' . $ticketId);
        }
    }
}

$messages = db_query("SELECT * FROM support_messages WHERE ticket_id=? ORDER BY created_at ASC", [$ticketId]);

$page_title = 'Ticket: ' . $ticket['subject'];
include __DIR__ . '/includes/header.php';
?>

<section class="section-sm" style="background:var(--c-surface);padding:var(--sp-6) 0">
    <div class="container">
        <a href="<?= APP_URL ?>/help.php" class="btn btn-ghost btn-sm" style="margin-bottom:var(--sp-4);margin-left:-8px">
            <span class="material-icons-round">arrow_back</span> Back to Help Center
        </a>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--sp-4)">
            <div>
                <h1 style="font-size:var(--text-2xl);font-family:var(--font-display);margin-bottom:4px"><?= e($ticket['subject']) ?></h1>
                <div style="font-size:var(--text-sm);color:var(--c-ink-muted)">Ticket #<?= $ticket['id'] ?></div>
            </div>
            <span class="status-badge <?= match($ticket['status']) { 'open' => 'status-pending', 'replied' => 'status-active', 'closed' => 'status-inactive', default => 'status-pending' } ?>">
                <?= ucfirst($ticket['status']) ?>
            </span>
        </div>
    </div>
</section>

<section class="section-sm">
    <div class="container" style="max-width:800px;margin:0 auto">
        <div class="card" style="padding:0;overflow:hidden;display:flex;flex-direction:column;min-height:500px">
            
            <div style="flex:1;overflow-y:auto;padding:var(--sp-5);background:var(--c-surface-2);display:flex;flex-direction:column;gap:var(--sp-4)" id="msgContainer">
                <?php 
                $lastDate = '';
                foreach ($messages as $msg):
                    $dateStr = date('F j, Y', strtotime($msg['created_at']));
                    if ($dateStr !== $lastDate) {
                        echo '<div style="text-align:center;margin:var(--sp-3) 0"><span style="background:var(--c-border);color:var(--c-ink);font-size:11px;padding:2px 8px;border-radius:99px;font-weight:600">'.$dateStr.'</span></div>';
                        $lastDate = $dateStr;
                    }
                    $isMe = ((int)$msg['sender_id'] === $uid);
                ?>
                <div style="display:flex;justify-content:<?= $isMe ? 'flex-end' : 'flex-start' ?>;gap:var(--sp-2)">
                    <div style="max-width:80%;padding:var(--sp-3) var(--sp-4);border-radius:<?= $isMe ? '18px 18px 4px 18px' : '18px 18px 18px 4px' ?>;background:<?= $isMe ? 'var(--c-primary)' : 'var(--c-surface)' ?>;border:1px solid <?= $isMe ? 'var(--c-primary)' : 'var(--c-border)' ?>;color:<?= $isMe ? '#fff' : 'var(--c-ink)' ?>;font-size:var(--text-sm);line-height:1.5">
                        <div style="font-size:11px;font-weight:700;margin-bottom:4px;color:<?= $isMe ? 'rgba(255,255,255,0.9)' : 'var(--c-primary)' ?>"><?= $isMe ? 'You' : 'Support Team' ?></div>
                        <?= nl2br(e($msg['body'])) ?>
                        <div style="font-size:10px;margin-top:4px;text-align:<?= $isMe ? 'right' : 'left' ?>;color:<?= $isMe ? 'rgba(255,255,255,0.7)' : 'var(--c-ink-muted)' ?>"><?= date('h:i A', strtotime($msg['created_at'])) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="padding:var(--sp-4);border-top:1px solid var(--c-border);background:var(--c-surface)">
                <?php if ($ticket['status'] === 'closed'): ?>
                <div style="text-align:center;color:var(--c-ink-muted);font-size:var(--text-sm)">This ticket is closed. If you need further assistance, please open a new ticket.</div>
                <?php else: ?>
                <form method="POST" style="display:flex;gap:var(--sp-3);align-items:flex-end">
                    <?= csrf_field() ?>
                    <div style="flex:1">
                        <textarea name="body" class="form-control" rows="1" style="resize:none;max-height:120px;overflow-y:auto" placeholder="Type your reply..." required id="msgInput" autofocus></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary" style="padding:0;width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0" data-loading="<span class='material-icons-round' style='font-size:20px;animation:spin 1s linear infinite'>hourglass_empty</span>">
                        <span class="material-icons-round" style="font-size:20px;margin-left:4px">send</span>
                    </button>
                </form>
                <?php endif; ?>
            </div>

        </div>
    </div>
</section>

<script>
    const msgContainer = document.getElementById('msgContainer');
    msgContainer.scrollTop = msgContainer.scrollHeight;

    const msgInput = document.getElementById('msgInput');
    if (msgInput) {
        msgInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
            if (this.value === '') {
                this.style.height = '44px';
            }
        });
        msgInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (this.value.trim().length > 0) {
                    this.closest('form').submit();
                }
            }
        });
        setTimeout(() => msgInput.style.height = '44px', 10);
    }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>