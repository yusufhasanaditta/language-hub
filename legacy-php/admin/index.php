<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_admin();

// Dashboard stats
$stats = [
    'total_users'    => db_val("SELECT COUNT(*) FROM users WHERE role_id=1"),
    'total_courses'  => db_val("SELECT COUNT(*) FROM courses WHERE status='published'"),
    'total_teachers' => db_val("SELECT COUNT(*) FROM teacher_profiles"),
    'total_orders'   => db_val("SELECT COUNT(*) FROM orders WHERE status='paid'"),
    'revenue_today'  => db_val("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='paid' AND DATE(paid_at)=CURDATE()"),
    'revenue_month'  => db_val("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='paid' AND MONTH(paid_at)=MONTH(NOW())"),
    'new_users_week' => db_val("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)"),
    'active_enrolls' => db_val("SELECT COUNT(*) FROM enrollments WHERE status='active'"),
];

// Recent orders
$recentOrders = db_query("
    SELECT o.*, u.name as user_name, u.phone
    FROM orders o JOIN users u ON u.id=o.user_id
    WHERE o.status='paid'
    ORDER BY o.paid_at DESC LIMIT 8
");

// Recent users
$recentUsers = db_query("
    SELECT * FROM users ORDER BY created_at DESC LIMIT 8
");

$page_title = 'Admin Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?> — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@700;800&family=Hind+Siliguri:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
    <script>const APP_URL='<?= APP_URL ?>';const CSRF_TOKEN='<?= csrf_token() ?>';</script>
</head>
<body>
<div class="admin-layout">
    <!-- Sidebar -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main content -->
    <div class="admin-content">
        <!-- Top bar -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-8)">
            <div>
                <h1 style="font-size:var(--text-2xl)">Dashboard</h1>
                <p style="color:var(--c-ink-muted);margin:0">Welcome, <?= e(current_user()['name']) ?>! Today is <?= date('d F Y, l') ?></p>
            </div>
            <div style="display:flex;gap:var(--sp-3)">
                <a href="<?= APP_URL ?>/admin/courses.php?action=add" class="btn btn-primary btn-sm">
                    <span class="material-icons-round">add</span> New Course
                </a>
            </div>
        </div>

        <!-- Stat cards -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:var(--sp-4);margin-bottom:var(--sp-8)">
            <?php
            $cards = [
                ['icon'=>'people', 'color'=>'primary', 'value'=>format_number((int)$stats['total_users']), 'label'=>'Total Students', 'sub'=>'+'.format_number((int)$stats['new_users_week']).' this week'],
                ['icon'=>'play_circle', 'color'=>'accent', 'value'=>(int)$stats['total_courses'], 'label'=>'Published Courses', 'sub'=>(int)$stats['active_enrolls'].' active enrollments'],
                ['icon'=>'receipt', 'color'=>'success', 'value'=>number_format((float)$stats['revenue_month'],0), 'label'=>'This Month\'s Revenue', 'sub'=>'Today '.number_format((float)$stats['revenue_today'],0)],
                ['icon'=>'school', 'color'=>'warning', 'value'=>(int)$stats['total_teachers'], 'label'=>'Teachers', 'sub'=>(int)$stats['total_orders'].' successful orders'],
            ];
            foreach ($cards as $c): ?>
            <div class="stat-card">
                <div class="stat-card-icon <?= e($c['color']) ?>">
                    <span class="material-icons-round"><?= e($c['icon']) ?></span>
                </div>
                <div>
                    <div class="stat-card-value"><?= e($c['value']) ?></div>
                    <div class="stat-card-label"><?= e($c['label']) ?></div>
                    <div style="font-size:var(--text-xs);color:var(--c-success);margin-top:4px"><?= e($c['sub']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-6)">
            <!-- Recent orders -->
            <div>
                <h3 style="margin-bottom:var(--sp-4)">Recent Orders</h3>
                <div class="data-table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr><th>Student</th><th>Amount</th><th>Date</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentOrders)): ?>
                            <tr><td colspan="4" style="text-align:center;color:var(--c-ink-muted)">No orders yet</td></tr>
                            <?php else: foreach ($recentOrders as $o): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600"><?= e($o['user_name']) ?></div>
                                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= e($o['phone']) ?></div>
                                </td>
                                <td><strong style="font-family:var(--font-latin)"><?= number_format((float)$o['total'],0) ?></strong></td>
                                <td style="font-size:var(--text-xs)"><?= date('d M', strtotime($o['paid_at'])) ?></td>
                                <td><span class="status-badge status-<?= e($o['status']) ?>"><?= e($o['status']) ?></span></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <a href="<?= APP_URL ?>/admin/orders.php" class="view-all-link" style="margin-top:12px;display:flex">View all orders →</a>
            </div>

            <!-- Recent users -->
            <div>
                <h3 style="margin-bottom:var(--sp-4)">New Students</h3>
                <div class="data-table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr><th>Name</th><th>Phone</th><th>Joined</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentUsers)): ?>
                            <tr><td colspan="3" style="text-align:center;color:var(--c-ink-muted)">No users yet</td></tr>
                            <?php else: foreach ($recentUsers as $u): ?>
                            <tr>
                                <td>
                                    <div style="display:flex;align-items:center;gap:8px">
                                        <img src="<?= e(avatar_url($u['avatar'], $u['name'])) ?>" alt="" style="width:28px;height:28px;border-radius:50%">
                                        <span style="font-weight:600;font-size:var(--text-sm)"><?= e($u['name']) ?></span>
                                    </div>
                                </td>
                                <td style="font-family:var(--font-latin);font-size:var(--text-sm)"><?= e($u['phone']) ?></td>
                                <td style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= time_ago($u['created_at']) ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <a href="<?= APP_URL ?>/admin/users.php" class="view-all-link" style="margin-top:12px;display:flex">All users →</a>
            </div>
        </div>
    </div>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
<?php function time_ago(string $dt): string { $d=time()-strtotime($dt); if($d<60)return 'just now'; if($d<3600)return intdiv($d,60).'m ago'; if($d<86400)return intdiv($d,3600).'h ago'; return intdiv($d,86400).'d ago'; } ?>
</body>
</html>
