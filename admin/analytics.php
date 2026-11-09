<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_admin();

$period = sanitize_string($_GET['period'] ?? '30', 5);
$days   = in_array((int) $period, [7, 30, 90, 365]) ? (int) $period : 30;
$since  = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));

// page_views is created lazily by track_page_view() on the first public page
// hit (see includes/functions.php) — nothing to import manually after upload.
$hasPageViews = (bool) db_val("SHOW TABLES LIKE 'page_views'");

// ── Traffic ──────────────────────────────────────────────────────────────
$totalPageviews  = $hasPageViews ? (int) db_val("SELECT COUNT(*) FROM page_views WHERE created_at >= ?", [$since]) : 0;
$uniqueVisitors  = $hasPageViews ? (int) db_val("SELECT COUNT(DISTINCT visitor_key) FROM page_views WHERE created_at >= ?", [$since]) : 0;
$pageviewsToday  = $hasPageViews ? (int) db_val("SELECT COUNT(*) FROM page_views WHERE DATE(created_at)=CURDATE()") : 0;
$visitorsToday   = $hasPageViews ? (int) db_val("SELECT COUNT(DISTINCT visitor_key) FROM page_views WHERE DATE(created_at)=CURDATE()") : 0;
$pageviewsAllTime = $hasPageViews ? (int) db_val("SELECT COUNT(*) FROM page_views") : 0;

$trafficTrend = $hasPageViews ? db_query("
    SELECT DATE(created_at) as day, COUNT(*) as views, COUNT(DISTINCT visitor_key) as visitors
    FROM page_views WHERE created_at >= ? GROUP BY DATE(created_at) ORDER BY day ASC
", [$since]) : [];

$deviceBreakdown = $hasPageViews ? db_query("
    SELECT COALESCE(device,'desktop') as device, COUNT(*) as cnt
    FROM page_views WHERE created_at >= ? GROUP BY device
", [$since]) : [];

$topPages = $hasPageViews ? db_query("
    SELECT path, COUNT(*) as views FROM page_views
    WHERE created_at >= ? GROUP BY path ORDER BY views DESC LIMIT 10
", [$since]) : [];

$topReferrers = $hasPageViews ? db_query("
    SELECT referrer, COUNT(*) as cnt FROM page_views
    WHERE created_at >= ? AND referrer IS NOT NULL AND referrer != '' AND referrer NOT LIKE CONCAT(?, '%')
    GROUP BY referrer ORDER BY cnt DESC LIMIT 10
", [$since, APP_URL]) : [];

// ── Users ────────────────────────────────────────────────────────────────
$totalStudents  = (int) db_val("SELECT COUNT(*) FROM users WHERE role_id=?", [ROLE_STUDENT]);
$totalTeachers  = (int) db_val("SELECT COUNT(*) FROM users WHERE role_id=?", [ROLE_TEACHER]);
$totalStaff     = (int) db_val("SELECT COUNT(*) FROM users WHERE role_id IN (?,?,?,?)", [ROLE_TA, ROLE_MODERATOR, ROLE_OPS, ROLE_ADMIN]);
$verifiedUsers  = (int) db_val("SELECT COUNT(*) FROM users WHERE is_verified=1");
$activeUsers    = (int) db_val("SELECT COUNT(*) FROM users WHERE is_active=1");
$newUsersPeriod = (int) db_val("SELECT COUNT(*) FROM users WHERE created_at >= ?", [$since]);
$newUsersTrend  = db_query("SELECT DATE(created_at) as day, COUNT(*) as cnt FROM users WHERE created_at >= ? GROUP BY DATE(created_at) ORDER BY day ASC", [$since]);

// ── Revenue / Orders ─────────────────────────────────────────────────────
$revenuePeriod  = (float) db_val("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='paid' AND paid_at >= ?", [$since]);
$revenueAllTime = (float) db_val("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='paid'");
$revenueTrend   = db_query("SELECT DATE(paid_at) as day, COALESCE(SUM(total),0) as amount FROM orders WHERE status='paid' AND paid_at >= ? GROUP BY DATE(paid_at) ORDER BY day ASC", [$since]);
$ordersFunnel   = db_query("SELECT status, COUNT(*) as cnt FROM orders GROUP BY status");

// ── Enrollments ──────────────────────────────────────────────────────────
$enrollTrend          = db_query("SELECT DATE(enrolled_at) as day, COUNT(*) as cnt FROM enrollments WHERE enrolled_at >= ? GROUP BY DATE(enrolled_at) ORDER BY day ASC", [$since]);
$activeEnrollments    = (int) db_val("SELECT COUNT(*) FROM enrollments WHERE status='active'");
$completedEnrollments = (int) db_val("SELECT COUNT(*) FROM enrollments WHERE progress_pct=100");

// ── Courses ──────────────────────────────────────────────────────────────
$totalCoursesPublished = (int) db_val("SELECT COUNT(*) FROM courses WHERE status='published'");
$totalCoursesDraft     = (int) db_val("SELECT COUNT(*) FROM courses WHERE status='draft'");
$totalCoursesArchived  = (int) db_val("SELECT COUNT(*) FROM courses WHERE status='archived'");
$coursesByType         = db_query("SELECT type, COUNT(*) as cnt FROM courses GROUP BY type");
$topCourses            = db_query("SELECT title_bn, thumbnail, enrolled_count, rating_avg FROM courses ORDER BY enrolled_count DESC LIMIT 8");

// ── Engagement (selected period) ────────────────────────────────────────
$examAttemptsPeriod = (int) db_val("SELECT COUNT(*) FROM exam_attempts WHERE started_at >= ?", [$since]);
$avgExamScorePeriod = (float) db_val("SELECT COALESCE(AVG(score),0) FROM exam_attempts WHERE started_at >= ? AND status IN ('submitted','graded')", [$since]);
$reviewsPeriod       = (int) db_val("SELECT COUNT(*) FROM reviews WHERE created_at >= ?", [$since]);
$avgRatingAll        = (float) db_val("SELECT COALESCE(AVG(rating),0) FROM reviews WHERE is_approved=1");
$discussionsPeriod   = (int) db_val("SELECT COUNT(*) FROM discussions WHERE created_at >= ?", [$since]);
$liveSessionsPeriod  = (int) db_val("SELECT COUNT(*) FROM live_sessions WHERE scheduled_at >= ?", [$since]);
$messagesPeriod      = (int) db_val("SELECT COUNT(*) FROM messages WHERE created_at >= ?", [$since]);

// ── Helpers ──────────────────────────────────────────────────────────────
function fill_day_trend(array $rows, int $days, string $dayKey, string $valKey): array {
    $map = [];
    foreach ($rows as $r) { $map[$r[$dayKey]] = $r[$valKey]; }
    $labels = []; $data = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $labels[] = date('d M', strtotime($d));
        $data[]   = isset($map[$d]) ? (float) $map[$d] : 0;
    }
    return [$labels, $data];
}

[$trafficLabels, $trafficViews]    = fill_day_trend($trafficTrend, $days, 'day', 'views');
[, $trafficVisitors]               = fill_day_trend($trafficTrend, $days, 'day', 'visitors');
[$usersLabels, $usersData]         = fill_day_trend($newUsersTrend, $days, 'day', 'cnt');
[$revenueLabels, $revenueData]     = fill_day_trend($revenueTrend, $days, 'day', 'amount');
[$enrollLabels, $enrollData]       = fill_day_trend($enrollTrend, $days, 'day', 'cnt');

$deviceMap = ['desktop' => 0, 'mobile' => 0, 'tablet' => 0];
foreach ($deviceBreakdown as $d) { $deviceMap[$d['device'] ?: 'desktop'] = (int) $d['cnt']; }

$funnelMap = ['pending' => 0, 'paid' => 0, 'failed' => 0, 'refunded' => 0, 'cancelled' => 0];
foreach ($ordersFunnel as $f) { $funnelMap[$f['status']] = (int) $f['cnt']; }

$typeMap = ['recorded' => 0, 'live' => 0, 'hybrid' => 0];
foreach ($coursesByType as $t) { $typeMap[$t['type']] = (int) $t['cnt']; }

$page_title = 'Site Analytics';
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="admin-content">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--sp-3);margin-bottom:var(--sp-6)">
            <div>
                <h1 style="font-size:var(--text-2xl)">Site Analytics</h1>
                <p style="color:var(--c-ink-muted);margin:0">Traffic, growth and engagement across the whole platform — admin only.</p>
            </div>
            <div style="display:flex;gap:6px">
                <?php foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'] as $p => $label): ?>
                <a href="?period=<?= $p ?>" class="btn btn-sm <?= $days === $p ? 'btn-primary' : 'btn-ghost' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (!$hasPageViews): ?>
        <div class="alert alert-warning" style="margin-bottom:var(--sp-6)">
            <span class="material-icons-round">info</span>
            <span>Traffic tracking just started collecting data — visitor charts below will fill in as people browse the site.</span>
        </div>
        <?php endif; ?>

        <!-- ── Traffic overview cards ── -->
        <h3 style="margin-bottom:var(--sp-4)">Website Traffic</h3>
        <div class="analytics-stats" style="display:grid;grid-template-columns:repeat(4,1fr);gap:var(--sp-4);margin-bottom:var(--sp-6)">
            <div class="stat-card">
                <div class="stat-card-icon primary"><span class="material-icons-round">visibility</span></div>
                <div><div class="stat-card-value"><?= format_number($totalPageviews) ?></div><div class="stat-card-label">Pageviews (<?= $days ?>d)</div>
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:4px"><?= format_number($pageviewsToday) ?> today</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon success"><span class="material-icons-round">group</span></div>
                <div><div class="stat-card-value"><?= format_number($uniqueVisitors) ?></div><div class="stat-card-label">Unique Visitors (<?= $days ?>d)</div>
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:4px"><?= format_number($visitorsToday) ?> today</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon accent"><span class="material-icons-round">bar_chart</span></div>
                <div><div class="stat-card-value"><?= format_number($pageviewsAllTime) ?></div><div class="stat-card-label">All-Time Pageviews</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon warning"><span class="material-icons-round">person_add</span></div>
                <div><div class="stat-card-value"><?= format_number($newUsersPeriod) ?></div><div class="stat-card-label">New Signups (<?= $days ?>d)</div></div>
            </div>
        </div>

        <!-- Traffic trend -->
        <div class="card" style="padding:var(--sp-6);margin-bottom:var(--sp-6)">
            <h3 style="margin-bottom:var(--sp-4)">Traffic Trend — last <?= $days ?> days</h3>
            <canvas id="trafficChart" style="max-height:260px"></canvas>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-6);margin-bottom:var(--sp-6)" class="two-col-grid">
            <!-- Device breakdown -->
            <div class="card" style="padding:var(--sp-6)">
                <h3 style="margin-bottom:var(--sp-4)">Device Breakdown</h3>
                <canvas id="deviceChart" style="max-height:220px"></canvas>
            </div>
            <!-- Top pages -->
            <div class="card" style="padding:var(--sp-6)">
                <h3 style="margin-bottom:var(--sp-4)">Most Visited Pages</h3>
                <?php if (empty($topPages)): ?>
                <p style="color:var(--c-ink-muted);text-align:center;padding:var(--sp-8) 0">No traffic data yet.</p>
                <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:var(--sp-2)">
                    <?php $maxViews = max(array_column($topPages, 'views')) ?: 1; ?>
                    <?php foreach ($topPages as $p): ?>
                    <div>
                        <div style="display:flex;justify-content:space-between;font-size:var(--text-xs);margin-bottom:2px">
                            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:70%;color:var(--c-ink-light)"><?= e($p['path']) ?></span>
                            <strong style="font-family:var(--font-latin);color:var(--c-primary)"><?= format_number((int) $p['views']) ?></strong>
                        </div>
                        <div class="progress-bar" style="height:6px"><div class="progress-fill" style="width:<?= round($p['views'] / $maxViews * 100) ?>%"></div></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($topReferrers)): ?>
        <div class="card" style="padding:var(--sp-6);margin-bottom:var(--sp-6)">
            <h3 style="margin-bottom:var(--sp-4)">Top External Referrers</h3>
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead><tr><th>Referrer</th><th>Visits</th></tr></thead>
                    <tbody>
                        <?php foreach ($topReferrers as $r): ?>
                        <tr>
                            <td style="font-size:var(--text-sm);max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($r['referrer']) ?></td>
                            <td style="font-family:var(--font-latin);font-weight:700"><?= format_number((int) $r['cnt']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Users ── -->
        <h3 style="margin-bottom:var(--sp-4)">Users &amp; Growth</h3>
        <div class="analytics-stats" style="display:grid;grid-template-columns:repeat(4,1fr);gap:var(--sp-4);margin-bottom:var(--sp-6)">
            <div class="stat-card">
                <div class="stat-card-icon primary"><span class="material-icons-round">school</span></div>
                <div><div class="stat-card-value"><?= format_number($totalStudents) ?></div><div class="stat-card-label">Students</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon accent"><span class="material-icons-round">cast_for_education</span></div>
                <div><div class="stat-card-value"><?= format_number($totalTeachers) ?></div><div class="stat-card-label">Teachers</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon success"><span class="material-icons-round">verified_user</span></div>
                <div><div class="stat-card-value"><?= format_number($verifiedUsers) ?></div><div class="stat-card-label">Verified Accounts</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon warning"><span class="material-icons-round">toggle_on</span></div>
                <div><div class="stat-card-value"><?= format_number($activeUsers) ?></div><div class="stat-card-label">Active Accounts</div></div>
            </div>
        </div>
        <div class="card" style="padding:var(--sp-6);margin-bottom:var(--sp-6)">
            <h3 style="margin-bottom:var(--sp-4)">New Signups — last <?= $days ?> days</h3>
            <canvas id="usersChart" style="max-height:220px"></canvas>
        </div>

        <!-- ── Revenue ── -->
        <h3 style="margin-bottom:var(--sp-4)">Revenue &amp; Orders</h3>
        <div class="analytics-stats" style="display:grid;grid-template-columns:repeat(4,1fr);gap:var(--sp-4);margin-bottom:var(--sp-6)">
            <div class="stat-card">
                <div class="stat-card-icon success"><span class="material-icons-round">payments</span></div>
                <div><div class="stat-card-value"><?= number_format($revenuePeriod, 0) ?></div><div class="stat-card-label">Revenue (<?= $days ?>d)</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon primary"><span class="material-icons-round">savings</span></div>
                <div><div class="stat-card-value"><?= number_format($revenueAllTime, 0) ?></div><div class="stat-card-label">All-Time Revenue</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon accent"><span class="material-icons-round">receipt_long</span></div>
                <div><div class="stat-card-value"><?= format_number($funnelMap['paid']) ?></div><div class="stat-card-label">Paid Orders</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon warning"><span class="material-icons-round">pending_actions</span></div>
                <div><div class="stat-card-value"><?= format_number($funnelMap['pending']) ?></div><div class="stat-card-label">Pending Orders</div></div>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:var(--sp-6);margin-bottom:var(--sp-6)" class="two-col-grid">
            <div class="card" style="padding:var(--sp-6)">
                <h3 style="margin-bottom:var(--sp-4)">Revenue Trend — last <?= $days ?> days</h3>
                <canvas id="revenueChart" style="max-height:240px"></canvas>
            </div>
            <div class="card" style="padding:var(--sp-6)">
                <h3 style="margin-bottom:var(--sp-4)">Order Funnel</h3>
                <div style="display:flex;flex-direction:column;gap:var(--sp-3)">
                    <?php
                    $funnelColors = ['paid' => 'var(--c-success)', 'pending' => 'var(--c-warning)', 'failed' => 'var(--c-error)', 'refunded' => 'var(--c-ink-muted)', 'cancelled' => 'var(--c-ink-faint)'];
                    foreach ($funnelMap as $status => $cnt): if ($status === 'cancelled' && $cnt === 0) continue; ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:var(--sp-2) 0;border-bottom:1px solid var(--c-border)">
                        <span style="text-transform:capitalize;font-size:var(--text-sm);color:<?= $funnelColors[$status] ?? 'var(--c-ink)' ?>;font-weight:700"><?= e($status) ?></span>
                        <span style="font-family:var(--font-latin);font-weight:800"><?= format_number($cnt) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── Enrollments ── -->
        <h3 style="margin-bottom:var(--sp-4)">Enrollments</h3>
        <div class="analytics-stats" style="display:grid;grid-template-columns:repeat(3,1fr);gap:var(--sp-4);margin-bottom:var(--sp-4)">
            <div class="stat-card">
                <div class="stat-card-icon primary"><span class="material-icons-round">how_to_reg</span></div>
                <div><div class="stat-card-value"><?= format_number($activeEnrollments) ?></div><div class="stat-card-label">Active Enrollments</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon success"><span class="material-icons-round">task_alt</span></div>
                <div><div class="stat-card-value"><?= format_number($completedEnrollments) ?></div><div class="stat-card-label">Completed Courses</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon accent"><span class="material-icons-round">trending_up</span></div>
                <div><div class="stat-card-value"><?= format_number((int) array_sum($enrollData)) ?></div><div class="stat-card-label">New Enrollments (<?= $days ?>d)</div></div>
            </div>
        </div>
        <div class="card" style="padding:var(--sp-6);margin-bottom:var(--sp-6)">
            <canvas id="enrollChart" style="max-height:220px"></canvas>
        </div>

        <!-- ── Courses ── -->
        <h3 style="margin-bottom:var(--sp-4)">Courses</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-6);margin-bottom:var(--sp-6)" class="two-col-grid">
            <div class="card" style="padding:var(--sp-6)">
                <h4 style="margin-bottom:var(--sp-4)">By Status &amp; Type</h4>
                <div style="display:flex;flex-direction:column;gap:8px;font-size:var(--text-sm)">
                    <div style="display:flex;justify-content:space-between"><span>Published</span><strong><?= format_number($totalCoursesPublished) ?></strong></div>
                    <div style="display:flex;justify-content:space-between"><span>Draft</span><strong><?= format_number($totalCoursesDraft) ?></strong></div>
                    <div style="display:flex;justify-content:space-between"><span>Archived</span><strong><?= format_number($totalCoursesArchived) ?></strong></div>
                    <div style="border-top:1px solid var(--c-border);margin:6px 0"></div>
                    <div style="display:flex;justify-content:space-between"><span>Recorded</span><strong><?= format_number($typeMap['recorded']) ?></strong></div>
                    <div style="display:flex;justify-content:space-between"><span>Live</span><strong><?= format_number($typeMap['live']) ?></strong></div>
                    <div style="display:flex;justify-content:space-between"><span>Hybrid</span><strong><?= format_number($typeMap['hybrid']) ?></strong></div>
                </div>
            </div>
            <div class="card" style="padding:var(--sp-6)">
                <h4 style="margin-bottom:var(--sp-4)">Top Courses by Enrollment</h4>
                <?php if (empty($topCourses)): ?>
                <p style="color:var(--c-ink-muted)">No courses yet.</p>
                <?php else: foreach ($topCourses as $c): ?>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                    <img src="<?= e(course_thumbnail_url($c['thumbnail'], $c['title_bn'], '60x34')) ?>" style="width:48px;height:27px;object-fit:cover;border-radius:4px;flex-shrink:0">
                    <div style="flex:1;min-width:0">
                        <div style="font-size:var(--text-sm);font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($c['title_bn']) ?></div>
                        <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= format_number((int) $c['enrolled_count']) ?> students · ★ <?= number_format((float) $c['rating_avg'], 1) ?></div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- ── Engagement ── -->
        <h3 style="margin-bottom:var(--sp-4)">Engagement (last <?= $days ?> days)</h3>
        <div class="analytics-stats" style="display:grid;grid-template-columns:repeat(4,1fr);gap:var(--sp-4)">
            <div class="stat-card">
                <div class="stat-card-icon primary"><span class="material-icons-round">quiz</span></div>
                <div><div class="stat-card-value"><?= format_number($examAttemptsPeriod) ?></div><div class="stat-card-label">Exam Attempts</div>
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:4px">Avg score <?= number_format($avgExamScorePeriod, 1) ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon warning"><span class="material-icons-round">star</span></div>
                <div><div class="stat-card-value"><?= format_number($reviewsPeriod) ?></div><div class="stat-card-label">New Reviews</div>
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:4px">Overall avg <?= number_format($avgRatingAll, 1) ?>★</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon accent"><span class="material-icons-round">forum</span></div>
                <div><div class="stat-card-value"><?= format_number($discussionsPeriod) ?></div><div class="stat-card-label">Discussion Posts</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon success"><span class="material-icons-round">live_tv</span></div>
                <div><div class="stat-card-value"><?= format_number($liveSessionsPeriod) ?></div><div class="stat-card-label">Live Classes</div>
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:4px"><?= format_number($messagesPeriod) ?> messages sent</div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media(max-width:1100px){.analytics-stats{grid-template-columns:repeat(2,1fr) !important}.two-col-grid{grid-template-columns:1fr !important}}
@media(max-width:600px){.analytics-stats{grid-template-columns:1fr !important}}
</style>

<script>
const CHART_PURPLE = '#5B21B6';
function makeLine(id, label, labels, data, color, fillAlpha) {
    return new Chart(document.getElementById(id), {
        type: 'line',
        data: { labels, datasets: [{
            label, data,
            borderColor: color, fill: true,
            backgroundColor: color + (fillAlpha || '14'),
            tension: .35, pointRadius: 2, pointBackgroundColor: color,
        }] },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } },
    });
}

makeLine('usersChart', 'New Signups', <?= json_encode($usersLabels) ?>, <?= json_encode($usersData) ?>, '#5B21B6');
makeLine('revenueChart', 'Revenue', <?= json_encode($revenueLabels) ?>, <?= json_encode($revenueData) ?>, '#15BE8B');
makeLine('enrollChart', 'Enrollments', <?= json_encode($enrollLabels) ?>, <?= json_encode($enrollData) ?>, '#F59E0B');

new Chart(document.getElementById('trafficChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($trafficLabels) ?>,
        datasets: [
            { label: 'Pageviews', data: <?= json_encode($trafficViews) ?>, borderColor: '#5B21B6', backgroundColor: '#5B21B614', fill: true, tension: .35, pointRadius: 2 },
            { label: 'Unique Visitors', data: <?= json_encode($trafficVisitors) ?>, borderColor: '#15BE8B', backgroundColor: '#15BE8B14', fill: true, tension: .35, pointRadius: 2 },
        ],
    },
    options: { responsive: true, plugins: { legend: { display: true, position: 'top' } }, scales: { y: { beginAtZero: true } } },
});

new Chart(document.getElementById('deviceChart'), {
    type: 'doughnut',
    data: {
        labels: ['Desktop', 'Mobile', 'Tablet'],
        datasets: [{
            data: [<?= $deviceMap['desktop'] ?>, <?= $deviceMap['mobile'] ?>, <?= $deviceMap['tablet'] ?>],
            backgroundColor: ['#5B21B6', '#F59E0B', '#15BE8B'],
            borderWidth: 0,
        }],
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } },
});
</script>

<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>
