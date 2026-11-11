<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_admin();

$page    = max(1, sanitize_int($_GET['page'] ?? 1));
$per     = 25;
$q       = sanitize_string($_GET['q'] ?? '', 100);
$wparams = [];
$wsql    = '';
if ($q) {
    $wsql    = "WHERE (u_s.name LIKE ? OR u_t.name LIKE ? OR c.title_bn LIKE ?)";
    $s       = '%' . $q . '%';
    $wparams = [$s, $s, $s];
}
$total   = (int)db_val("SELECT COUNT(*) FROM messages m
    JOIN users u_s ON u_s.id=m.sender_id
    JOIN users u_t ON u_t.id=m.receiver_id
    JOIN courses c ON c.id=m.course_id $wsql", $wparams);
$offset  = ($page-1)*$per;
$msgs    = db_query("
    SELECT m.*, u_s.name as sender_name, u_t.name as receiver_name, c.title_bn as course_title
    FROM messages m
    JOIN users u_s ON u_s.id=m.sender_id
    JOIN users u_t ON u_t.id=m.receiver_id
    JOIN courses c ON c.id=m.course_id
    $wsql
    ORDER BY m.created_at DESC
    LIMIT $per OFFSET $offset
", $wparams);

$totalMsgs  = (int)db_val("SELECT COUNT(*) FROM messages");
$unread     = (int)db_val("SELECT COUNT(*) FROM messages WHERE read_at IS NULL");
$today      = (int)db_val("SELECT COUNT(*) FROM messages WHERE DATE(created_at)=CURDATE()");

function time_ago(string $dt): string { $d=time()-strtotime($dt); if($d<60)return 'just now'; if($d<3600)return intdiv($d,60).'m ago'; if($d<86400)return intdiv($d,3600).'h ago'; return date('d M Y',strtotime($dt)); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Message Monitor — <?= APP_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@700;800&family=Hind+Siliguri:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<script>const APP_URL='<?= APP_URL ?>';const CSRF_TOKEN='<?= csrf_token() ?>';</script>
</head>
<body>
<div class="admin-layout">
<?php include __DIR__.'/includes/sidebar.php'; ?>
<div class="admin-content">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
    <h1 style="font-size:var(--text-2xl)">Message Monitor</h1>
    <span style="color:var(--c-ink-muted);font-size:var(--text-sm)">Total <?= number_format($totalMsgs) ?> messages</span>
</div>

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:var(--sp-4);margin-bottom:var(--sp-6)">
    <?php foreach ([['chat','primary',$totalMsgs,'Total Messages'],['mark_chat_unread','warning',$unread,'Unread'],['today','success',$today,'Today']] as [$i,$c,$v,$l]): ?>
    <div class="stat-card"><div class="stat-card-icon <?= $c ?>"><span class="material-icons-round"><?= $i ?></span></div><div><div class="stat-card-value"><?= number_format($v) ?></div><div class="stat-card-label"><?= $l ?></div></div></div>
    <?php endforeach; ?>
</div>

<form method="GET" style="display:flex;gap:var(--sp-3);margin-bottom:var(--sp-5)">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Student, teacher, or course..." class="form-control" style="max-width:320px">
    <button type="submit" class="btn btn-primary btn-sm"><span class="material-icons-round">search</span></button>
    <?php if ($q): ?><a href="<?= APP_URL ?>/admin/messages.php" class="btn btn-ghost btn-sm">Reset</a><?php endif; ?>
</form>

<div class="data-table-wrap">
<table class="data-table">
<thead><tr><th>Sender</th><th>Recipient</th><th>Course</th><th>Message</th><th>Time</th><th>Status</th></tr></thead>
<tbody>
<?php if (empty($msgs)): ?>
<tr><td colspan="6" style="text-align:center;padding:40px;color:var(--c-ink-muted)">No messages yet</td></tr>
<?php else: foreach ($msgs as $m): ?>
<tr>
    <td style="font-weight:600;font-size:var(--text-sm)"><?= e($m['sender_name']) ?></td>
    <td style="font-size:var(--text-sm);color:var(--c-ink-muted)"><?= e($m['receiver_name']) ?></td>
    <td style="font-size:var(--text-xs);color:var(--c-primary)"><?= e(mb_substr($m['course_title']??'',0,30,'UTF-8')) ?></td>
    <td style="font-size:var(--text-sm);max-width:300px"><?= e(mb_substr($m['body'],0,80,'UTF-8')) ?><?= mb_strlen($m['body'],'UTF-8')>80?'…':'' ?></td>
    <td style="font-size:var(--text-xs);color:var(--c-ink-muted);white-space:nowrap"><?= time_ago($m['created_at']) ?></td>
    <td>
        <span class="status-badge <?= $m['read_at']?'status-active':'status-pending' ?>">
            <?= $m['read_at']?'Read':'Unread' ?>
        </span>
    </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
<?= paginate($total, $page, $per, APP_URL.'/admin/messages.php?'.http_build_query(array_filter(['q'=>$q,'page'=>'{page}']))) ?>
</div>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
