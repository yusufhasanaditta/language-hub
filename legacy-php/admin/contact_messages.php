<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_admin();

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $postAction = sanitize_string($_POST['action'] ?? '', 20);
    $id = sanitize_int($_POST['id'] ?? 0);

    if ($postAction === 'mark_read' && $id) {
        db_exec("UPDATE contact_messages SET is_read=1 WHERE id=?", [$id]);
    } elseif ($postAction === 'delete' && $id) {
        db_exec("DELETE FROM contact_messages WHERE id=?", [$id]);
        flash('success', 'Message deleted.');
    }
    redirect(APP_URL . '/admin/contact_messages.php');
}

$page   = max(1, sanitize_int($_GET['page'] ?? 1));
$per    = 20;
$offset = ($page - 1) * $per;
$total  = (int) db_val("SELECT COUNT(*) FROM contact_messages");
$unread = (int) db_val("SELECT COUNT(*) FROM contact_messages WHERE is_read=0");
$messages = db_query("SELECT * FROM contact_messages ORDER BY created_at DESC LIMIT $per OFFSET $offset");

$page_title = 'Contact Messages';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?> — <?= APP_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@700;800&family=Hind+Siliguri:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<script>const APP_URL='<?= APP_URL ?>';const CSRF_TOKEN='<?= csrf_token() ?>';</script>
</head>
<body>
<div class="admin-layout">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="admin-content">
<?php foreach (get_flash() as $f): ?>
<div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:16px">
    <span class="material-icons-round"><?= $f['type'] === 'success' ? 'check_circle' : 'error' ?></span>
    <span><?= e($f['message']) ?></span>
</div>
<?php endforeach; ?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--sp-3);margin-bottom:var(--sp-6)">
    <div>
        <h1 style="font-size:var(--text-2xl)">Contact Messages</h1>
        <p style="color:var(--c-ink-muted);margin:0"><?= $unread ?> unread / <?= $total ?> total</p>
    </div>
</div>

<?php if (empty($messages)): ?>
<div style="text-align:center;padding:var(--sp-16);background:var(--c-surface);border-radius:var(--r-lg);border:2px dashed var(--c-border)">
    <span class="material-icons-round" style="font-size:56px;color:var(--c-ink-faint)">mail</span>
    <h3 style="margin:12px 0 8px;color:var(--c-ink-light)">No messages yet</h3>
    <p style="color:var(--c-ink-muted)">Submissions from the Contact Us page will show up here.</p>
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:var(--sp-4)">
    <?php foreach ($messages as $m): ?>
    <div style="background:var(--c-surface);border:1px solid var(--c-border);<?= $m['is_read'] ? '' : 'border-left:4px solid var(--c-primary);' ?>border-radius:var(--r-lg);padding:var(--sp-5)">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--sp-3);flex-wrap:wrap">
            <div>
                <div style="font-weight:700"><?= e($m['name']) ?> <?= $m['is_read'] ? '' : '<span class="status-badge status-active" style="margin-left:6px">New</span>' ?></div>
                <div style="font-size:var(--text-sm);color:var(--c-ink-muted);display:flex;gap:var(--sp-3);flex-wrap:wrap;margin-top:4px">
                    <a href="mailto:<?= e($m['email']) ?>" style="color:var(--c-primary)"><?= e($m['email']) ?></a>
                    <?php if ($m['phone']): ?><a href="tel:<?= e($m['phone']) ?>" style="color:var(--c-primary)"><?= e($m['phone']) ?></a><?php endif; ?>
                    <span><?= date('d M Y, h:i A', strtotime($m['created_at'])) ?></span>
                </div>
            </div>
            <div style="display:flex;gap:6px">
                <?php if (!$m['is_read']): ?>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_read">
                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" title="Mark as read">
                        <span class="material-icons-round" style="font-size:15px">mark_email_read</span>
                    </button>
                </form>
                <?php endif; ?>
                <form method="POST" onsubmit="return confirm('Delete this message?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--c-error)" title="Delete">
                        <span class="material-icons-round" style="font-size:15px">delete</span>
                    </button>
                </form>
            </div>
        </div>
        <?php if ($m['subject']): ?><div style="font-weight:600;margin-top:var(--sp-3)"><?= e($m['subject']) ?></div><?php endif; ?>
        <p style="margin-top:6px;white-space:pre-wrap;color:var(--c-ink)"><?= e($m['message']) ?></p>
    </div>
    <?php endforeach; ?>
</div>
<?= paginate($total, $page, $per, APP_URL . '/admin/contact_messages.php?page={page}') ?>
<?php endif; ?>

</div>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>
