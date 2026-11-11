<?php
/**
 * Printable invoice for a single order. Students see their own orders,
 * admins see any order.
 */
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_login();

$me      = current_user();
$orderId = (int)($_GET['order'] ?? 0);

$order = db_row(
    "SELECT o.*, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone
     FROM orders o JOIN users u ON u.id = o.user_id
     WHERE o.id = ?",
    [$orderId]
);

if (!$order || ((int)$order['user_id'] !== (int)$me['id'] && !is_admin())) {
    http_response_code(404);
    flash('error', 'Invoice not found.');
    redirect(APP_URL . '/orders.php');
}

// Older orders predate invoice numbering — assign one on first view.
if (empty($order['invoice_no'])) {
    $order['invoice_no'] = 'LH-' . date('Y', strtotime($order['paid_at'] ?: $order['created_at'])) . '-' . str_pad((string)$order['id'], 6, '0', STR_PAD_LEFT);
    db_exec("UPDATE orders SET invoice_no=? WHERE id=?", [$order['invoice_no'], $order['id']]);
}

$items   = db_query("SELECT * FROM order_items WHERE order_id=?", [$orderId]);
$payment = db_row("SELECT * FROM payments WHERE order_id=? ORDER BY id DESC LIMIT 1", [$orderId]);

// The course this order unlocked — used for the "go to course" button
$courseRow = db_row(
    "SELECT c.id, c.slug, c.title_bn FROM order_items oi
     JOIN courses c ON c.id = oi.item_id
     WHERE oi.order_id = ? AND oi.item_type = 'course' LIMIT 1",
    [$orderId]
);

$statusClass = match ($order['status']) {
    'paid'                 => 'paid',
    'failed', 'cancelled'  => 'failed',
    default                => 'pending',
};

$page_title = 'Invoice ' . $order['invoice_no'];
include __DIR__ . '/includes/header.php';
?>

<section class="section-sm">
    <div class="container">

        <div class="no-print" style="display:flex;gap:var(--sp-3);flex-wrap:wrap;align-items:center;margin-bottom:var(--sp-5)">
            <a href="<?= APP_URL ?>/orders.php" class="btn btn-ghost btn-sm">
                <span class="material-icons-round">arrow_back</span> My Orders
            </a>
            <button class="btn btn-primary btn-sm" onclick="window.print()">
                <span class="material-icons-round">print</span> Print / Save PDF
            </button>
            <?php if ($courseRow && $order['status'] === 'paid'): ?>
            <a class="btn btn-accent btn-sm" href="<?= APP_URL ?>/learn.php?course=<?= (int)$courseRow['id'] ?>">
                <span class="material-icons-round">play_circle</span> Go to course
            </a>
            <?php endif; ?>
        </div>

        <div class="invoice-sheet">
            <div class="inv-top">
                <div class="inv-brand">
                    <img src="<?= APP_URL ?>/image/language-hub-logo.png" alt="<?= e(APP_NAME) ?>">
                    <div>
                        <div style="font-family:var(--font-display);font-weight:800;font-size:var(--text-xl)"><?= e(APP_NAME) ?></div>
                        <div class="text-xs text-muted"><?= e(get_setting('site_tagline', APP_TAGLINE)) ?></div>
                    </div>
                </div>
                <div style="text-align:right">
                    <div class="inv-title">INVOICE</div>
                    <div class="inv-no"><?= e($order['invoice_no']) ?></div>
                    <div style="margin-top:var(--sp-3)">
                        <span class="inv-stamp <?= $statusClass ?>">
                            <span class="material-icons-round" style="font-size:16px">
                                <?= $order['status'] === 'paid' ? 'verified' : ($statusClass === 'failed' ? 'cancel' : 'schedule') ?>
                            </span>
                            <?= e(ucfirst($order['status'])) ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="inv-meta-grid">
                <div>
                    <div class="inv-meta-label">Billed to</div>
                    <div class="inv-meta-val">
                        <strong><?= e($order['billing_name'] ?: $order['customer_name']) ?></strong><br>
                        <?php if ($order['billing_phone'] ?: $order['customer_phone']): ?>
                            <?= e($order['billing_phone'] ?: $order['customer_phone']) ?><br>
                        <?php endif; ?>
                        <?php if ($order['billing_email'] ?: $order['customer_email']): ?>
                            <?= e($order['billing_email'] ?: $order['customer_email']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <div class="inv-meta-label">Invoice date</div>
                    <div class="inv-meta-val">
                        <?= date('d M Y, h:i A', strtotime($order['created_at'])) ?>
                        <?php if ($order['paid_at']): ?>
                        <br><span class="text-muted text-xs">Paid <?= date('d M Y, h:i A', strtotime($order['paid_at'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <div class="inv-meta-label">Payment</div>
                    <div class="inv-meta-val">
                        <?= $payment ? e(strtoupper($payment['gateway'])) : 'Not paid yet' ?>
                        <?php if ($payment && $payment['tran_id']): ?>
                        <br><span class="text-muted text-xs">TXN <?= e($payment['tran_id']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="table-wrap">
                <table class="inv-table">
                    <thead>
                        <tr>
                            <th style="width:60%">Item</th>
                            <th>Type</th>
                            <th class="num">Amount (<?= APP_CURRENCY ?>)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($items): foreach ($items as $it): ?>
                        <tr>
                            <td><strong><?= e($it['title']) ?></strong></td>
                            <td><?= e(ucfirst($it['item_type'])) ?></td>
                            <td class="num"><?= format_price((float)$it['price']) ?></td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="3" class="text-muted">No line items recorded.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="inv-totals">
                <div class="inv-total-row">
                    <span>Subtotal</span>
                    <span class="latin"><?= format_price((float)$order['subtotal']) ?></span>
                </div>
                <?php if ((float)$order['discount'] > 0): ?>
                <div class="inv-total-row">
                    <span>Discount <?= $order['coupon_code'] ? '(' . e($order['coupon_code']) . ')' : '' ?></span>
                    <span class="latin text-success">− <?= format_price((float)$order['discount']) ?></span>
                </div>
                <?php endif; ?>
                <div class="inv-total-row grand">
                    <span>Total (<?= APP_CURRENCY ?>)</span>
                    <span class="latin"><?= format_price((float)$order['total']) ?></span>
                </div>
            </div>

            <div class="inv-foot">
                <strong>Thank you for learning with <?= e(APP_NAME) ?>.</strong><br>
                This is a computer-generated invoice and is valid without a signature.
                Course access is granted to the purchasing account only and is non-transferable.
                For billing questions contact <?= e(get_setting('contact_email', '')) ?>.
            </div>
        </div>

    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
