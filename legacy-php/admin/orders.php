<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $act = sanitize_string($_POST['action'] ?? '', 20);
    $oid = sanitize_int($_POST['order_id'] ?? 0);
    if ($act === 'update_status' && $oid) {
        $s = in_array($_POST['status'] ?? '', ['pending','paid','failed','refunded']) ? $_POST['status'] : 'pending';
        if ($s === 'paid') {
            db_exec("UPDATE orders SET status=?,paid_at=COALESCE(paid_at,NOW()) WHERE id=?", [$s, $oid]);
            // Auto-enroll if not already
            $o = db_row("SELECT * FROM orders WHERE id=?", [$oid]);
            if ($o) {
                $items = db_query("SELECT item_id FROM order_items WHERE order_id=? AND item_type='course'", [$oid]);
                foreach ($items as $item) {
                    if (!db_val("SELECT 1 FROM enrollments WHERE user_id=? AND course_id=?", [$o['user_id'], $item['item_id']])) {
                        db_insert("INSERT INTO enrollments (user_id, course_id, status, progress_pct, enrolled_at) VALUES (?,?,'active',0,NOW())", [$o['user_id'], $item['item_id']]);
                        db_exec("UPDATE courses SET enrolled_count=COALESCE(enrolled_count,0)+1 WHERE id=?", [$item['item_id']]);
                    }
                }
                create_notification(
                    $o['user_id'], 'payment', 'Payment received — order #' . $oid,
                    'Your enrollment is active. Tap to view the invoice.',
                    APP_URL . '/invoice.php?order=' . $oid
                );
            }
        } else {
            db_exec("UPDATE orders SET status=? WHERE id=?", [$s, $oid]);
        }
        flash('success', 'Order status updated.');
    }
    redirect(APP_URL . '/admin/orders.php');
}

$status = sanitize_string($_GET['status'] ?? '', 20);
$search = sanitize_string($_GET['q'] ?? '', 100);
$page   = max(1, sanitize_int($_GET['page'] ?? 1));
$per    = 20;

$where  = ['1=1'];
$params = [];
if ($status && in_array($status, ['pending','paid','failed','refunded'])) { $where[] = 'o.status=?'; $params[] = $status; }
if ($search) {
    $where[] = '(u.name LIKE ? OR u.phone LIKE ? OR o.id=?)';
    $params = array_merge($params, ['%'.$search.'%','%'.$search.'%', (int)$search]);
}
$wsql = 'WHERE '.implode(' AND ',$where);
$total = (int)db_val("SELECT COUNT(*) FROM orders o JOIN users u ON u.id=o.user_id $wsql", $params);
$offset = ($page-1)*$per;
$orders = db_query("
    SELECT o.*, u.name as user_name, u.phone, u.avatar,
           GROUP_CONCAT(DISTINCT c.title_bn SEPARATOR ', ') as course_title
    FROM orders o
    JOIN users u ON u.id=o.user_id
    LEFT JOIN order_items oi ON oi.order_id=o.id AND oi.item_type='course'
    LEFT JOIN courses c ON c.id=oi.item_id
    $wsql
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT $per OFFSET $offset
", $params);

$revToday = (float)db_val("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='paid' AND DATE(paid_at)=CURDATE()");
$revMonth = (float)db_val("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='paid' AND MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())");
$revTotal = (float)db_val("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='paid'");
$pendingCnt = (int)db_val("SELECT COUNT(*) FROM orders WHERE status='pending'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Orders — <?= APP_NAME ?></title>
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
<?php foreach(get_flash() as $f): ?>
<div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:16px">
    <span class="material-icons-round"><?= $f['type']==='success'?'check_circle':'error' ?></span>
    <span><?= e($f['message']) ?></span>
</div>
<?php endforeach; ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
    <h1 style="font-size:var(--text-2xl)">Order Management</h1>
    <span style="color:var(--c-ink-muted)"><?= number_format($total) ?> orders</span>
</div>

<!-- Revenue stats -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:var(--sp-4);margin-bottom:var(--sp-6)">
    <?php foreach ([
        ['receipt','primary',number_format($revTotal,0),'Total Revenue'],
        ['today','success',number_format($revMonth,0),'This Month'],
        ['payments','accent',number_format($revToday,0),'Today'],
        ['pending','warning',$pendingCnt,'Pending'],
    ] as [$icon,$col,$val,$lab]): ?>
    <div class="stat-card">
        <div class="stat-card-icon <?= $col ?>"><span class="material-icons-round"><?= $icon ?></span></div>
        <div><div class="stat-card-value"><?= $val ?></div><div class="stat-card-label"><?= $lab ?></div></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filters -->
<form method="GET" style="display:flex;gap:var(--sp-3);margin-bottom:var(--sp-5);flex-wrap:wrap">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, phone, or order ID..." class="form-control" style="max-width:280px">
    <select name="status" class="form-control" style="width:auto" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <?php foreach (['pending'=>'Pending','paid'=>'Paid','failed'=>'Failed','refunded'=>'Refunded'] as $k=>$l): ?>
        <option value="<?= $k ?>" <?= $status===$k?'selected':'' ?>><?= $l ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><span class="material-icons-round">search</span> Search</button>
    <?php if ($search||$status): ?><a href="<?= APP_URL ?>/admin/orders.php" class="btn btn-ghost btn-sm">Reset</a><?php endif; ?>
</form>

<div class="data-table-wrap">
<table class="data-table">
<thead><tr><th>#</th><th>Student</th><th>Course</th><th>Amount</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
<tbody>
<?php if (empty($orders)): ?>
<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--c-ink-muted)">No orders found</td></tr>
<?php else: foreach ($orders as $o): ?>
<tr>
    <td style="font-family:var(--font-latin);color:var(--c-ink-muted);font-size:var(--text-xs)">#<?= (int)$o['id'] ?></td>
    <td>
        <div style="display:flex;align-items:center;gap:8px">
            <img src="<?= e(avatar_url($o['avatar']??'',$o['user_name'])) ?>" style="width:32px;height:32px;border-radius:50%">
            <div>
                <div style="font-weight:700;font-size:var(--text-sm)"><?= e($o['user_name']) ?></div>
                <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= e($o['phone']) ?></div>
            </div>
        </div>
    </td>
    <td style="font-size:var(--text-sm);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($o['course_title'] ?? '—') ?></td>
    <td><strong style="font-family:var(--font-latin)"><?= number_format((float)$o['total'],0) ?></strong></td>
    <td>
        <span class="status-badge <?= match($o['status']){'paid'=>'status-active','pending'=>'status-pending','failed'=>'status-archived',default=>'status-draft'} ?>">
            <?= match($o['status']){'paid'=>'Paid','pending'=>'Pending','failed'=>'Failed','refunded'=>'Refunded',default=>$o['status']} ?>
        </span>
    </td>
    <td style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
    <td>
        <form method="POST" style="display:flex;gap:4px;align-items:center">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
            <select name="status" class="form-control" style="padding:4px 6px;font-size:var(--text-xs);width:auto">
                <?php foreach (['pending'=>'Pending','paid'=>'Paid','failed'=>'Failed','refunded'=>'Refunded'] as $k=>$l): ?>
                <option value="<?= $k ?>" <?= $o['status']===$k?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm" style="padding:4px 8px"><span class="material-icons-round" style="font-size:14px">save</span></button>
        </form>
    </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
<?= paginate($total, $page, $per, APP_URL.'/admin/orders.php?'.http_build_query(array_filter(['q'=>$search,'status'=>$status,'page'=>'{page}']))) ?>
</div>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
