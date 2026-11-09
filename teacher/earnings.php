<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_teacher();

// Only admins (direct, or impersonating a teacher via admin/teachers.php) may view this page.
if ((int)($_SESSION['user_role'] ?? 0) === ROLE_TEACHER && empty($_SESSION['original_admin'])) {
    http_response_code(403);
    die(render_403());
}

$user = current_user();
$uid  = (int)$user['id'];
$tp   = db_row("SELECT * FROM teacher_profiles WHERE user_id=?", [$uid]);
if (!$tp) { $tpId = db_insert("INSERT INTO teacher_profiles (user_id,rating_avg,total_students) VALUES (?,0,0)", [$uid]); $tp = db_row("SELECT * FROM teacher_profiles WHERE id=?", [$tpId]); }
$tpId = (int)$tp['id'];

$period = sanitize_string($_GET['period'] ?? '30', 5);
$days   = in_array((int)$period, [7,30,90,365]) ? (int)$period : 30;

// Total earnings
$totalEarnings  = (float)db_val("SELECT COALESCE(SUM(oi.price),0) FROM order_items oi JOIN courses c ON c.id=oi.item_id AND oi.item_type='course' JOIN orders o ON o.id=oi.order_id WHERE c.teacher_id=? AND o.status='paid'", [$tpId]);
$monthEarnings  = (float)db_val("SELECT COALESCE(SUM(oi.price),0) FROM order_items oi JOIN courses c ON c.id=oi.item_id AND oi.item_type='course' JOIN orders o ON o.id=oi.order_id WHERE c.teacher_id=? AND o.status='paid' AND o.paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", [$tpId]);
$pendingPayout  = (float)db_val("SELECT COALESCE(SUM(amount),0) FROM teacher_payouts WHERE teacher_id=? AND status='pending'", [$tpId]) ?? 0;
$totalPaid      = (float)db_val("SELECT COALESCE(SUM(amount),0) FROM teacher_payouts WHERE teacher_id=? AND status='paid'", [$tpId]) ?? 0;
$totalSales     = (int)db_val("SELECT COUNT(*) FROM order_items oi JOIN courses c ON c.id=oi.item_id AND oi.item_type='course' JOIN orders o ON o.id=oi.order_id WHERE c.teacher_id=? AND o.status='paid'", [$tpId]);

// Revenue trend
$revTrend = db_query("
    SELECT DATE(o.paid_at) as day, SUM(oi.price) as revenue, COUNT(*) as sales
    FROM order_items oi
    JOIN courses c ON c.id=oi.item_id AND oi.item_type='course'
    JOIN orders o ON o.id=oi.order_id
    WHERE c.teacher_id=? AND o.status='paid' AND o.paid_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY DATE(o.paid_at)
    ORDER BY day ASC
", [$tpId, $days]);

// Per-course revenue
$courseRevenue = db_query("
    SELECT c.id, c.title_bn, c.thumbnail,
           COUNT(oi.id) as sales,
           COALESCE(SUM(oi.price),0) as revenue
    FROM courses c
    LEFT JOIN order_items oi ON oi.item_id=c.id AND oi.item_type='course'
    LEFT JOIN orders o ON o.id=oi.order_id AND o.status='paid'
    WHERE c.teacher_id=?
    GROUP BY c.id, c.title_bn, c.thumbnail
    ORDER BY revenue DESC
", [$tpId]);

// Recent transactions
$recentOrders = db_query("
    SELECT u.name as buyer_name, u.avatar as buyer_avatar,
           c.title_bn as course_title, oi.price, o.paid_at
    FROM order_items oi
    JOIN courses c ON c.id=oi.item_id AND oi.item_type='course'
    JOIN orders o ON o.id=oi.order_id
    JOIN users u ON u.id=o.user_id
    WHERE c.teacher_id=? AND o.status='paid'
    ORDER BY o.paid_at DESC
    LIMIT 15
", [$tpId]);

// Payout history
$payouts = db_query("SELECT * FROM teacher_payouts WHERE teacher_id=? ORDER BY created_at DESC LIMIT 10", [$tpId]) ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Earnings — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@700;800&family=Hind+Siliguri:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>const APP_URL='<?= APP_URL ?>';const CSRF_TOKEN='<?= csrf_token() ?>';</script>
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__.'/includes/sidebar.php'; ?>
    <div class="admin-content">

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
            <h1 style="font-size:var(--text-2xl)">Earnings & Analytics</h1>
            <form method="GET">
                <select name="period" class="form-control form-control-sm" onchange="this.form.submit()">
                    <?php foreach (['7'=>'Last 7 days','30'=>'Last 30 days','90'=>'Last 90 days','365'=>'Last 1 year'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $period==$v?'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <!-- Summary cards -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:var(--sp-4);margin-bottom:var(--sp-8)" class="earn-stats">
            <div class="stat-card" style="border-left:4px solid var(--c-success)">
                <div class="stat-card-icon success"><span class="material-icons-round">payments</span></div>
                <div>
                    <div class="stat-card-value" style="color:var(--c-success)"><?= number_format($totalEarnings,0) ?></div>
                    <div class="stat-card-label">Total Earnings</div>
                </div>
            </div>
            <div class="stat-card" style="border-left:4px solid var(--c-primary)">
                <div class="stat-card-icon primary"><span class="material-icons-round">calendar_month</span></div>
                <div>
                    <div class="stat-card-value"><?= number_format($monthEarnings,0) ?></div>
                    <div class="stat-card-label">This Month's Earnings</div>
                </div>
            </div>
            <div class="stat-card" style="border-left:4px solid var(--c-warning)">
                <div class="stat-card-icon warning"><span class="material-icons-round">account_balance_wallet</span></div>
                <div>
                    <div class="stat-card-value"><?= number_format($pendingPayout,0) ?></div>
                    <div class="stat-card-label">Pending Payout</div>
                </div>
            </div>
            <div class="stat-card" style="border-left:4px solid var(--c-accent)">
                <div class="stat-card-icon accent"><span class="material-icons-round">shopping_cart</span></div>
                <div>
                    <div class="stat-card-value"><?= number_format($totalSales) ?></div>
                    <div class="stat-card-label">Total Sales</div>
                </div>
            </div>
        </div>

        <!-- Revenue chart -->
        <div class="card" style="padding:var(--sp-6);margin-bottom:var(--sp-6)">
            <h3 style="margin-bottom:var(--sp-4)">Earnings Graph (last <?= $days ?> days)</h3>
            <?php
            $trendMap = [];
            foreach ($revTrend as $t) { $trendMap[$t['day']] = (float)$t['revenue']; }
            $labels = []; $revData = [];
            for ($i=$days-1; $i>=0; $i--) {
                $d = date('Y-m-d', strtotime("-$i days"));
                $labels[]  = date('d M', strtotime($d));
                $revData[] = $trendMap[$d] ?? 0;
            }
            ?>
            <canvas id="revenueChart" style="max-height:240px"></canvas>
            <script>
            new Chart(document.getElementById('revenueChart'), {
                type:'bar',
                data:{
                    labels:<?= json_encode($labels) ?>,
                    datasets:[{
                        label:'Earnings',
                        data:<?= json_encode($revData) ?>,
                        backgroundColor:'rgba(91, 33, 182,0.7)',
                        borderRadius:4
                    }]
                },
                options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}
            });
            </script>
        </div>

        <div style="display:grid;grid-template-columns:1fr 340px;gap:var(--sp-6)" class="earn-two-col">

            <!-- Course revenue breakdown -->
            <div>
                <h3 style="font-size:var(--text-xl);margin-bottom:var(--sp-4)">Earnings by Course</h3>
                <?php if (empty($courseRevenue)): ?>
                <div style="text-align:center;padding:var(--sp-12);background:var(--c-surface);border-radius:var(--r-lg);color:var(--c-ink-muted)">No sales yet</div>
                <?php else: ?>
                <?php $maxRev = max(array_column($courseRevenue,'revenue')) ?: 1; ?>
                <div style="display:flex;flex-direction:column;gap:var(--sp-3)">
                    <?php foreach ($courseRevenue as $cr): ?>
                    <div style="background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--r-lg);padding:var(--sp-4)">
                        <div style="display:flex;gap:var(--sp-3);align-items:center;margin-bottom:var(--sp-3)">
                            <img src="<?= e(course_thumbnail_url($cr['thumbnail'], $cr['title_bn'] ?? 'Course', '72x40')) ?>"
                                 style="width:72px;height:40px;object-fit:cover;border-radius:var(--r-sm);flex-shrink:0">
                            <div style="flex:1;min-width:0">
                                <div style="font-weight:700;font-size:var(--text-sm);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($cr['title_bn']) ?></div>
                                <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= (int)$cr['sales'] ?> sales</div>
                            </div>
                            <div style="font-family:var(--font-latin);font-weight:800;font-size:var(--text-base);color:var(--c-success);white-space:nowrap">
                                <?= number_format((float)$cr['revenue'],0) ?>
                            </div>
                        </div>
                        <div class="progress-bar" style="height:5px">
                            <div class="progress-fill" style="width:<?= $maxRev > 0 ? min(100,(float)$cr['revenue']/$maxRev*100) : 0 ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Recent transactions -->
                <?php if (!empty($recentOrders)): ?>
                <h3 style="font-size:var(--text-xl);margin-top:var(--sp-8);margin-bottom:var(--sp-4)">Recent Transactions</h3>
                <div class="data-table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Buyer</th><th>Course</th><th>Amount</th><th>Date</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentOrders as $ro): ?>
                            <tr>
                                <td>
                                    <div style="display:flex;align-items:center;gap:8px">
                                        <img src="<?= e(avatar_url($ro['buyer_avatar'],$ro['buyer_name'])) ?>"
                                             style="width:30px;height:30px;border-radius:50%">
                                        <span style="font-size:var(--text-sm);font-weight:600"><?= e($ro['buyer_name']) ?></span>
                                    </div>
                                </td>
                                <td style="font-size:var(--text-xs);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($ro['course_title']) ?></td>
                                <td style="font-family:var(--font-latin);font-weight:700;color:var(--c-success)"><?= number_format((float)$ro['price'],0) ?></td>
                                <td style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= date('d M Y', strtotime($ro['paid_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Payout history -->
            <div>
                <div style="background:linear-gradient(135deg,#1a1a2e,#16213e);border-radius:var(--r-lg);padding:var(--sp-6);color:#fff;margin-bottom:var(--sp-5)">
                    <div style="font-size:var(--text-xs);font-weight:700;text-transform:uppercase;letter-spacing:.08em;opacity:.6;margin-bottom:var(--sp-2)">Available Balance</div>
                    <div style="font-size:var(--text-3xl);font-weight:800;font-family:var(--font-latin)"><?= number_format($totalEarnings - $totalPaid, 0) ?></div>
                    <div style="font-size:var(--text-xs);opacity:.6;margin-top:4px">Paid: <?= number_format($totalPaid,0) ?></div>
                    <button class="btn btn-white btn-sm" style="margin-top:var(--sp-4);width:100%"
                            onclick="alert('Payout request sent to admin.')">
                        <span class="material-icons-round" style="font-size:16px">payments</span>
                        Request Payout
                    </button>
                </div>

                <h4 style="margin-bottom:var(--sp-3)">Payout History</h4>
                <?php if (empty($payouts)): ?>
                <div style="text-align:center;padding:var(--sp-8);background:var(--c-surface);border-radius:var(--r-lg);color:var(--c-ink-muted);font-size:var(--text-sm)">
                    <span class="material-icons-round" style="font-size:36px;display:block;margin-bottom:8px">account_balance</span>
                    No payout history
                </div>
                <?php else: ?>
                <div style="border:1px solid var(--c-border);border-radius:var(--r-lg);overflow:hidden">
                    <?php foreach ($payouts as $i => $p): ?>
                    <div style="padding:var(--sp-3) var(--sp-4);<?= $i>0?'border-top:1px solid var(--c-border)':'' ?>;display:flex;align-items:center;justify-content:space-between">
                        <div>
                            <div style="font-family:var(--font-latin);font-weight:700;font-size:var(--text-base)"><?= number_format((float)$p['amount'],0) ?></div>
                            <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= date('d M Y', strtotime($p['created_at'])) ?></div>
                        </div>
                        <span class="status-badge <?= $p['status']==='paid'?'status-active':'status-draft' ?>">
                            <?= $p['status']==='paid' ? 'Paid' : 'Pending' ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<style>
@media(max-width:1200px){.earn-stats{grid-template-columns:repeat(2,1fr) !important}}
@media(max-width:900px){.earn-two-col{grid-template-columns:1fr !important}}
@media(max-width:480px){.earn-stats{grid-template-columns:1fr !important}}
</style>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
