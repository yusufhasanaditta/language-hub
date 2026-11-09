<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_login();

$user = current_user();
$uid  = (int)$user['id'];

$page   = max(1, sanitize_int($_GET['page'] ?? 1));
$per    = 10;
$total  = (int)db_val("SELECT COUNT(*) FROM orders WHERE user_id=?", [$uid]);
$offset = ($page - 1) * $per;

$orders = db_query("
    SELECT o.*,
           GROUP_CONCAT(oi.title ORDER BY oi.id SEPARATOR ' | ') as items_list,
           GROUP_CONCAT(oi.item_id ORDER BY oi.id SEPARATOR ',') as item_ids
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id AND oi.item_type = 'course'
    WHERE o.user_id = ?
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT $per OFFSET $offset
", [$uid]);

// Stats
$totalSpent  = (float)db_val("SELECT COALESCE(SUM(total),0) FROM orders WHERE user_id=? AND status='paid'", [$uid]);
$totalOrders = $total;
$paidOrders  = (int)db_val("SELECT COUNT(*) FROM orders WHERE user_id=? AND status='paid'", [$uid]);

$page_title = 'Order History';
include __DIR__ . '/includes/header.php';
?>

<div style="background:linear-gradient(135deg,#1A1033,#2D1B8A);padding:var(--sp-12) 0 var(--sp-8);color:#fff">
    <div class="container">
        <h1 style="color:#fff;font-size:clamp(1.5rem,3vw,2rem);margin-bottom:var(--sp-3)">Order History</h1>
        <div style="display:flex;gap:var(--sp-4);flex-wrap:wrap">
            <?php foreach ([
                ['receipt', number_format($totalOrders), 'Total Orders'],
                ['check_circle', number_format($paidOrders), 'Completed'],
                ['payments', number_format($totalSpent, 0), 'Total Spent'],
            ] as [$icon, $val, $lab]): ?>
            <div style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:var(--r-xl);padding:var(--sp-4) var(--sp-5);display:flex;align-items:center;gap:12px">
                <span class="material-icons-round" style="font-size:22px;color:rgba(255,255,255,.6)"><?= $icon ?></span>
                <div>
                    <div style="font-family:var(--font-latin);font-size:var(--text-xl);font-weight:800;color:#fff"><?= $val ?></div>
                    <div style="font-size:var(--text-xs);color:rgba(255,255,255,.55)"><?= $lab ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="container" style="padding-top:var(--sp-10);padding-bottom:var(--sp-16)">

<?php if (empty($orders)): ?>
<div style="text-align:center;padding:var(--sp-20);background:var(--c-surface);border-radius:var(--r-2xl);border:2px dashed var(--c-border)">
    <span class="material-icons-round" style="font-size:64px;color:var(--c-ink-faint);display:block;margin-bottom:16px">receipt_long</span>
    <h2 style="color:var(--c-ink-light);margin-bottom:8px">No orders yet</h2>
    <p style="color:var(--c-ink-muted);margin-bottom:24px">You haven't purchased any courses yet.</p>
    <a href="<?= APP_URL ?>/courses.php" class="btn btn-primary">Browse Courses</a>
</div>

<?php else: ?>
<div style="display:flex;flex-direction:column;gap:var(--sp-4)">
    <?php foreach ($orders as $o):
        $statusLabel = match($o['status']) {
            'paid'      => 'Paid',
            'pending'   => 'Pending',
            'failed'    => 'Failed',
            'refunded'  => 'Refunded',
            'cancelled' => 'Cancelled',
            default     => $o['status'],
        };
        $statusClass = match($o['status']) {
            'paid'     => 'status-active',
            'pending'  => 'status-pending',
            'failed'   => 'status-archived',
            'refunded' => 'status-draft',
            default    => 'status-draft',
        };
        $statusIcon = match($o['status']) {
            'paid'    => 'check_circle',
            'pending' => 'hourglass_empty',
            'failed'  => 'cancel',
            default   => 'info',
        };
    ?>
    <div class="card" style="padding:var(--sp-5)">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--sp-4);flex-wrap:wrap">
            <div style="flex:1;min-width:0">
                <!-- Order header -->
                <div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:var(--sp-3)">
                    <div style="width:40px;height:40px;border-radius:var(--r-md);background:var(--c-primary-light);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                        <span class="material-icons-round" style="color:var(--c-primary)"><?= $statusIcon ?></span>
                    </div>
                    <div>
                        <div style="font-family:var(--font-latin);font-size:var(--text-xs);color:var(--c-ink-muted);font-weight:700">Order #<?= (int)$o['id'] ?></div>
                        <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= date('d F Y, h:i A', strtotime($o['created_at'])) ?></div>
                    </div>
                    <span class="status-badge <?= $statusClass ?>" style="margin-left:auto"><?= $statusLabel ?></span>
                </div>

                <!-- Items -->
                <?php if ($o['items_list']): ?>
                <div style="margin-bottom:var(--sp-3)">
                    <?php foreach (explode(' | ', $o['items_list']) as $item): ?>
                    <?php if (trim($item)): ?>
                    <div style="display:flex;align-items:center;gap:8px;padding:var(--sp-2) 0;border-bottom:1px solid var(--c-border)">
                        <span class="material-icons-round" style="font-size:16px;color:var(--c-primary);flex-shrink:0">play_circle</span>
                        <span style="font-size:var(--text-sm);color:var(--c-ink)"><?= e($item) ?></span>
                    </div>
                    <?php endif; endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Payment details -->
                <div style="display:flex;flex-wrap:wrap;gap:var(--sp-4)">
                    <?php if ($o['subtotal'] != $o['total']): ?>
                    <div style="font-size:var(--text-sm);color:var(--c-ink-muted)">
                        Subtotal: <span style="font-family:var(--font-latin)"><?= number_format((float)$o['subtotal'], 0) ?></span>
                    </div>
                    <?php if ((float)$o['discount'] > 0): ?>
                    <div style="font-size:var(--text-sm);color:var(--c-success)">
                        Discount: <span style="font-family:var(--font-latin)">-<?= number_format((float)$o['discount'], 0) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                    <div style="font-weight:700;font-size:var(--text-base)">
                        Total: <span style="font-family:var(--font-latin);color:var(--c-primary)"><?= number_format((float)$o['total'], 0) ?></span>
                    </div>
                    <?php if ($o['coupon_code']): ?>
                    <div style="font-size:var(--text-xs);background:var(--c-success-light);color:var(--c-success);padding:2px 8px;border-radius:999px;font-weight:700">
                        Coupon: <?= e($o['coupon_code']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Actions -->
            <div style="display:flex;flex-direction:column;gap:8px;flex-shrink:0">
                <?php if ($o['status'] === 'paid'): ?>
                <?php $firstCourseId = (int)explode(',', (string)$o['item_ids'])[0]; ?>
                <a href="<?= $firstCourseId ? APP_URL . '/learn.php?course=' . $firstCourseId : APP_URL . '/my-courses.php' ?>" class="btn btn-primary btn-sm">
                    <span class="material-icons-round" style="font-size:15px">play_circle</span> Go to Course
                </a>
                <?php elseif ($o['status'] === 'pending'): ?>
                <a href="<?= APP_URL ?>/checkout.php?retry=<?= (int)$o['id'] ?>" class="btn btn-warning btn-sm">
                    <span class="material-icons-round" style="font-size:15px">payment</span> Make Payment
                </a>
                <?php endif; ?>
                <a href="<?= APP_URL ?>/invoice.php?order=<?= (int)$o['id'] ?>" class="btn btn-ghost btn-sm">
                    <span class="material-icons-round" style="font-size:15px">receipt_long</span> Invoice
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?= paginate($total, $page, $per, APP_URL . '/orders.php?page={page}') ?>
<?php endif; ?>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
