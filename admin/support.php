<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_admin();

$ticketId = sanitize_int($_GET['ticket'] ?? 0);

// Handle POST request to send a reply or close ticket
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    $id = sanitize_int($_POST['ticket_id'] ?? 0);

    if ($action === 'reply' && $id) {
        $body = sanitize_string($_POST['body'] ?? '', 2000);
        if ($body) {
            db_exec("INSERT INTO support_messages (ticket_id, sender_id, body, created_at) VALUES (?, 0, ?, NOW())", [$id, $body]);
            db_exec("UPDATE support_tickets SET status='replied', replied_at=NOW() WHERE id=?", [$id]);
            flash('success', 'Reply sent successfully.');
        } else {
            flash('error', 'Reply cannot be empty.');
        }
    } elseif ($action === 'close' && $id) {
        db_exec("UPDATE support_tickets SET status='closed' WHERE id=?", [$id]);
        flash('success', 'Ticket closed.');
    } elseif ($action === 'delete' && $id) {
        db_exec("DELETE FROM support_messages WHERE ticket_id=?", [$id]);
        db_exec("DELETE FROM support_tickets WHERE id=?", [$id]);
        flash('success', 'Ticket deleted.');
        redirect(APP_URL . '/admin/support.php');
        exit;
    }
    redirect(APP_URL . '/admin/support.php?ticket=' . $id);
}

// Fetch conversation list (all tickets)
// We'll show the most recently updated tickets first
$tickets = db_query("
    SELECT st.*, u.name as user_name, u.avatar as user_avatar, u.email as user_email, u.phone as user_phone,
           (SELECT body FROM support_messages sm WHERE sm.ticket_id = st.id ORDER BY created_at DESC LIMIT 1) as last_msg,
           (SELECT created_at FROM support_messages sm WHERE sm.ticket_id = st.id ORDER BY created_at DESC LIMIT 1) as last_activity
    FROM support_tickets st
    JOIN users u ON u.id=st.user_id
    ORDER BY COALESCE(last_activity, st.created_at) DESC
");

// Fetch active ticket data if selected
$activeTicket = null;
$messages = [];
if ($ticketId) {
    $activeTicket = db_row("
        SELECT st.*, u.name as user_name, u.avatar as user_avatar, u.email as user_email, u.phone as user_phone
        FROM support_tickets st
        JOIN users u ON u.id=st.user_id
        WHERE st.id=?
    ", [$ticketId]);

    if ($activeTicket) {
        $messages = db_query("SELECT * FROM support_messages WHERE ticket_id=? ORDER BY created_at ASC", [$ticketId]);
    } else {
        $ticketId = 0; // Invalid ticket
    }
}

$page_title = 'Support Center - Admin';
$_active = 'support.php';

function time_ago(string $dt): string { $d=time()-strtotime($dt); if($d<60)return 'just now'; if($d<3600)return intdiv($d,60).'m ago'; if($d<86400)return intdiv($d,3600).'h ago'; return date('d M Y',strtotime($dt)); }

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $page_title ?> — <?= APP_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@700;800&family=Hind+Siliguri:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<script>const APP_URL='<?= APP_URL ?>';const CSRF_TOKEN='<?= csrf_token() ?>';</script>
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="admin-content" style="display:flex;flex-direction:column">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--sp-6)">
            <div>
                <h1 style="font-size:var(--text-2xl);font-family:var(--font-display);margin-bottom:4px">Support Center</h1>
                <div style="font-size:var(--text-sm);color:var(--c-ink-muted)">Manage and reply to student support tickets</div>
            </div>
        </div>

        <?php foreach(get_flash() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:16px">
            <span class="material-icons-round"><?= $f['type']==='success'?'check_circle':'error' ?></span>
            <span><?= e($f['message']) ?></span>
        </div>
        <?php endforeach; ?>

        <div style="display:grid;grid-template-columns:320px 1fr;gap:var(--sp-5);flex:1;min-height:0" class="msg-layout">

            <!-- Conversation list -->
            <div class="card" style="padding:0;overflow:hidden;display:flex;flex-direction:column;height:100%">
                <div style="padding:var(--sp-4);border-bottom:1px solid var(--c-border);font-weight:700;font-family:var(--font-display);display:flex;justify-content:space-between;align-items:center">
                    <span>Tickets (<?= count($tickets) ?>)</span>
                </div>
                
                <div style="overflow-y:auto;flex:1">
                    <?php if (empty($tickets)): ?>
                    <div style="padding:var(--sp-8);text-align:center;color:var(--c-ink-muted)">
                        <span class="material-icons-round" style="font-size:40px;display:block;margin-bottom:8px">check_circle_outline</span>
                        No support tickets.
                    </div>
                    <?php else: foreach ($tickets as $tk):
                        $isActive  = ($ticketId == $tk['id']);
                    ?>
                    <a href="<?= APP_URL ?>/admin/support.php?ticket=<?= (int)$tk['id'] ?>"
                       style="display:flex;align-items:center;gap:var(--sp-3);padding:var(--sp-4);border-bottom:1px solid var(--c-border);text-decoration:none;background:<?= $isActive ? 'var(--c-primary-light)' : 'transparent' ?>;transition:background .15s">
                        <img src="<?= e(avatar_url($tk['user_avatar'], $tk['user_name'])) ?>"
                             style="width:44px;height:44px;border-radius:50%;object-fit:cover;flex-shrink:0" alt="">
                        <div style="flex:1;overflow:hidden">
                            <div style="display:flex;justify-content:space-between;align-items:center">
                                <span style="font-weight:700;font-size:var(--text-sm);color:var(--c-ink)"><?= e($tk['user_name']) ?></span>
                                <span class="status-badge <?= match($tk['status']) { 'open' => 'status-pending', 'replied' => 'status-active', 'closed' => 'status-inactive', default => 'status-pending' } ?>" style="padding:1px 6px;font-size:10px"><?= ucfirst($tk['status']) ?></span>
                            </div>
                            <div style="font-size:11px;color:var(--c-primary);margin:1px 0"><?= e(mb_substr($tk['subject'],0,30,'UTF-8')) ?></div>
                            <div style="font-size:var(--text-xs);color:var(--c-ink-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                <?= e(mb_substr($tk['last_msg'] ?? $tk['body'], 0, 50, 'UTF-8')) ?>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <!-- Chat window -->
            <div class="card" style="padding:0;display:flex;flex-direction:column;overflow:hidden;height:100%">
                <?php if ($ticketId && $activeTicket): ?>
                
                <!-- Header -->
                <div style="padding:var(--sp-4);border-bottom:1px solid var(--c-border);display:flex;align-items:center;justify-content:space-between;background:var(--c-surface)">
                    <div style="display:flex;align-items:center;gap:var(--sp-3)">
                        <img src="<?= e(avatar_url($activeTicket['user_avatar'], $activeTicket['user_name'])) ?>"
                             style="width:40px;height:40px;border-radius:50%;object-fit:cover" alt="">
                        <div>
                            <div style="font-weight:700"><?= e($activeTicket['user_name']) ?> <span style="font-size:12px;font-weight:400;color:var(--c-ink-muted)">(<?= e($activeTicket['user_email'] ?: $activeTicket['user_phone']) ?>)</span></div>
                            <div style="font-size:11px;color:var(--c-primary)"><?= e($activeTicket['subject']) ?></div>
                        </div>
                    </div>
                    
                    <div style="display:flex;gap:var(--sp-2)">
                        <?php if ($activeTicket['status'] !== 'closed'): ?>
                        <form method="POST" onsubmit="return confirm('Close this ticket?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="close">
                            <input type="hidden" name="ticket_id" value="<?= $activeTicket['id'] ?>">
                            <button type="submit" class="btn btn-ghost btn-sm">Close Ticket</button>
                        </form>
                        <?php endif; ?>
                        
                        <form method="POST" onsubmit="return confirm('Delete this ticket permanently?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="ticket_id" value="<?= $activeTicket['id'] ?>">
                            <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--c-error)">Delete</button>
                        </form>
                    </div>
                </div>

                <!-- Messages container -->
                <div style="flex:1;overflow-y:auto;padding:var(--sp-5);background:var(--c-surface-2);display:flex;flex-direction:column;gap:var(--sp-4)" id="msgContainer">
                    <?php 
                    $lastDate = '';
                    foreach ($messages as $msg):
                        $dateStr = date('F j, Y', strtotime($msg['created_at']));
                        if ($dateStr !== $lastDate) {
                            echo '<div style="text-align:center;margin:var(--sp-3) 0"><span style="background:var(--c-border);color:var(--c-ink);font-size:11px;padding:2px 8px;border-radius:99px;font-weight:600">'.$dateStr.'</span></div>';
                            $lastDate = $dateStr;
                        }
                        $isAdmin = ((int)$msg['sender_id'] === 0);
                    ?>
                    <div style="display:flex;justify-content:<?= $isAdmin ? 'flex-end' : 'flex-start' ?>;gap:var(--sp-2)">
                        <div style="max-width:75%;padding:var(--sp-3) var(--sp-4);border-radius:<?= $isAdmin ? '18px 18px 4px 18px' : '18px 18px 18px 4px' ?>;background:<?= $isAdmin ? 'var(--c-primary)' : 'var(--c-surface)' ?>;border:1px solid <?= $isAdmin ? 'var(--c-primary)' : 'var(--c-border)' ?>;color:<?= $isAdmin ? '#fff' : 'var(--c-ink)' ?>;font-size:var(--text-sm);line-height:1.5">
                            <?= nl2br(e($msg['body'])) ?>
                            <div style="font-size:10px;margin-top:4px;text-align:<?= $isAdmin ? 'right' : 'left' ?>;color:<?= $isAdmin ? 'rgba(255,255,255,0.7)' : 'var(--c-ink-muted)' ?>"><?= date('h:i A', strtotime($msg['created_at'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Input area -->
                <div style="padding:var(--sp-4);border-top:1px solid var(--c-border);background:var(--c-surface)">
                    <?php if ($activeTicket['status'] === 'closed'): ?>
                    <div style="text-align:center;color:var(--c-ink-muted);font-size:var(--text-sm)">This ticket is closed.</div>
                    <?php else: ?>
                    <form method="POST" style="display:flex;gap:var(--sp-3);align-items:flex-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reply">
                        <input type="hidden" name="ticket_id" value="<?= (int)$activeTicket['id'] ?>">
                        <div style="flex:1">
                            <textarea name="body" class="form-control" rows="1" style="resize:none;max-height:120px;overflow-y:auto" placeholder="Type a message..." required id="msgInput" autofocus></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary" style="padding:0;width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0" data-loading="<span class='material-icons-round' style='font-size:20px;animation:spin 1s linear infinite'>hourglass_empty</span>">
                            <span class="material-icons-round" style="font-size:20px;margin-left:4px">send</span>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php else: ?>
                <div style="flex:1;display:flex;align-items:center;justify-content:center;color:var(--c-ink-muted);flex-direction:column">
                    <span class="material-icons-round" style="font-size:48px;margin-bottom:16px;opacity:0.5">chat</span>
                    Select a ticket to start messaging
                </div>
                <?php endif; ?>
            </div>
            
        </div>
    </main>
</div>

<?php if ($ticketId && $activeTicket): ?>
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
<?php endif; ?>
</div>
</div>
</body>
</html>
