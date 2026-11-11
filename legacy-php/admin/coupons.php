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

    if ($act === 'create') {
        $code      = strtoupper(sanitize_string($_POST['code'] ?? '', 30));
        $type      = in_array($_POST['type']??'', ['percent','flat']) ? $_POST['type'] : 'percent';
        $value     = max(0, (float)($_POST['value'] ?? 0));
        $minOrder  = max(0, (float)($_POST['min_order'] ?? 0));
        $maxUses   = max(0, sanitize_int($_POST['max_uses'] ?? 0));
        $expiry    = sanitize_string($_POST['expires_at'] ?? '', 20);

        if (!$code || !$value) { flash('error', 'Code and value are required.'); }
        elseif (db_val("SELECT 1 FROM coupons WHERE code=?", [$code])) { flash('error', 'This code already exists.'); }
        else {
            db_insert("INSERT INTO coupons (code,type,value,min_order,max_uses,used_count,expires_at,is_active,created_at) VALUES (?,?,?,?,?,0,?,1,NOW())",
                [$code,$type,$value,$minOrder,$maxUses,$expiry ?: null]);
            flash('success', 'Coupon created.');
        }
    } elseif ($act === 'toggle' && ($cid = sanitize_int($_POST['coupon_id'] ?? 0))) {
        db_exec("UPDATE coupons SET is_active=1-is_active WHERE id=?", [$cid]);
        flash('success', 'Coupon status changed.');
    } elseif ($act === 'delete' && ($cid = sanitize_int($_POST['coupon_id'] ?? 0))) {
        db_exec("DELETE FROM coupons WHERE id=?", [$cid]);
        flash('success', 'Coupon deleted.');
    }
    redirect(APP_URL . '/admin/coupons.php');
}

$page    = max(1, sanitize_int($_GET['page'] ?? 1));
$per     = 20;
$total   = (int)db_val("SELECT COUNT(*) FROM coupons");
$offset  = ($page-1)*$per;
$coupons = db_query("SELECT * FROM coupons ORDER BY created_at DESC LIMIT $per OFFSET $offset");
$active  = (int)db_val("SELECT COUNT(*) FROM coupons WHERE is_active=1");
$totalUsed = (int)db_val("SELECT COALESCE(SUM(used_count),0) FROM coupons");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Coupons — <?= APP_NAME ?></title>
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

<div style="display:grid;grid-template-columns:1fr 300px;gap:var(--sp-6);align-items:start">
<div>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-5)">
    <h1 style="font-size:var(--text-2xl)">Coupon Management</h1>
    <div style="display:flex;gap:var(--sp-3)">
        <span style="color:var(--c-ink-muted)"><?= $active ?> active / <?= $total ?> total</span>
        <span>· used <?= $totalUsed ?> times</span>
    </div>
</div>

<div class="data-table-wrap">
<table class="data-table">
<thead><tr><th>Code</th><th>Type</th><th>Value</th><th>Used</th><th>Expiry</th><th>Status</th><th>Action</th></tr></thead>
<tbody>
<?php if (empty($coupons)): ?>
<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--c-ink-muted)">No coupons yet</td></tr>
<?php else: foreach ($coupons as $cp): ?>
<tr>
    <td><code style="font-family:monospace;font-weight:700;background:var(--c-surface);padding:3px 8px;border-radius:4px"><?= e($cp['code']) ?></code></td>
    <td><?= $cp['type']==='percent'?'Percent':'Fixed' ?></td>
    <td style="font-family:var(--font-latin);font-weight:700">
        <?= $cp['type']==='percent' ? number_format((float)$cp['value'],0).'%' : number_format((float)$cp['value'],0) ?>
    </td>
    <td style="font-family:var(--font-latin)">
        <?= (int)$cp['used_count'] ?><?= $cp['max_uses'] ? ' / '.(int)$cp['max_uses'] : '' ?>
    </td>
    <td style="font-size:var(--text-xs);color:var(--c-ink-muted)">
        <?= $cp['expires_at'] ? date('d M Y', strtotime($cp['expires_at'])) : 'No expiry' ?>
    </td>
    <td>
        <span class="status-badge <?= $cp['is_active']?'status-active':'status-archived' ?>">
            <?= $cp['is_active']?'Active':'Off' ?>
        </span>
    </td>
    <td>
        <div style="display:flex;gap:4px">
            <form method="POST" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="coupon_id" value="<?= (int)$cp['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm">
                    <span class="material-icons-round" style="font-size:15px"><?= $cp['is_active']?'toggle_off':'toggle_on' ?></span>
                </button>
            </form>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this coupon?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="coupon_id" value="<?= (int)$cp['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--c-error)">
                    <span class="material-icons-round" style="font-size:15px">delete</span>
                </button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
<?= paginate($total, $page, $per, APP_URL.'/admin/coupons.php?page={page}') ?>
</div>

<!-- Create coupon -->
<div class="card" style="padding:var(--sp-6)">
    <h3 style="margin-bottom:var(--sp-5)">New Coupon</h3>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-group">
            <label class="form-label">Coupon Code *</label>
            <input type="text" name="code" class="form-control" required placeholder="SUMMER50" style="text-transform:uppercase">
        </div>
        <div class="form-group">
            <label class="form-label">Discount Type</label>
            <select name="type" class="form-control">
                <option value="percent">Percent (%)</option>
                <option value="flat">Fixed amount</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Value *</label>
            <input type="number" name="value" class="form-control" required min="1" step="0.01" placeholder="20">
        </div>
        <div class="form-group">
            <label class="form-label">Minimum Order</label>
            <input type="number" name="min_order" class="form-control" min="0" placeholder="0">
        </div>
        <div class="form-group">
            <label class="form-label">Max Uses (0 = unlimited)</label>
            <input type="number" name="max_uses" class="form-control" min="0" placeholder="100">
        </div>
        <div class="form-group">
            <label class="form-label">Expiry Date</label>
            <input type="date" name="expires_at" class="form-control" min="<?= date('Y-m-d') ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Create Coupon</button>
    </form>
</div>
</div>
</div>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
