<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_admin();

$courseId = sanitize_int($_GET['id'] ?? 0);
$course = $courseId ? db_row("
    SELECT c.*, cat.name_bn as cat_name, u.name as teacher_name
    FROM courses c
    JOIN categories cat ON cat.id = c.category_id
    JOIN teacher_profiles tp ON tp.id = c.teacher_id
    JOIN users u ON u.id = tp.user_id
    WHERE c.id = ?
", [$courseId]) : null;

if (!$course) {
    http_response_code(404);
    die('Course not found.');
}

$period = sanitize_string($_GET['period'] ?? '30', 5);
$days   = in_array((int) $period, [7, 30, 90, 365]) ? (int) $period : 30;
$since  = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));

// course_views is created lazily by track_course_view() on the first public
// course-page hit (see includes/functions.php) — nothing to import by hand.
$courseViewsTableExists = (bool) db_val("SHOW TABLES LIKE 'course_views'");
// Table existing globally doesn't mean THIS course has been viewed since
// tracking started — check per-course so the "no data yet" notice is accurate
// instead of silently showing zeros for any course nobody's opened since deploy.
$hasCourseViews = $courseViewsTableExists && (bool) db_val("SELECT 1 FROM course_views WHERE course_id=? LIMIT 1", [$courseId]);

$totalViews    = $hasCourseViews ? (int) db_val("SELECT COUNT(*) FROM course_views WHERE course_id=? AND created_at >= ?", [$courseId, $since]) : 0;
$uniqueViewers = $hasCourseViews ? (int) db_val("SELECT COUNT(DISTINCT visitor_key) FROM course_views WHERE course_id=? AND created_at >= ?", [$courseId, $since]) : 0;
$viewsToday    = $hasCourseViews ? (int) db_val("SELECT COUNT(*) FROM course_views WHERE course_id=? AND DATE(created_at)=CURDATE()", [$courseId]) : 0;
$viewsAllTime  = $hasCourseViews ? (int) db_val("SELECT COUNT(*) FROM course_views WHERE course_id=?", [$courseId]) : 0;

$viewsTrend = $hasCourseViews ? db_query("
    SELECT DATE(created_at) as day, COUNT(*) as views, COUNT(DISTINCT visitor_key) as visitors
    FROM course_views WHERE course_id=? AND created_at >= ? GROUP BY DATE(created_at) ORDER BY day ASC
", [$courseId, $since]) : [];

$deviceBreakdown = $hasCourseViews ? db_query("
    SELECT COALESCE(device,'desktop') as device, COUNT(*) as cnt
    FROM course_views WHERE course_id=? AND created_at >= ? GROUP BY device
", [$courseId, $since]) : [];

$topReferrers = $hasCourseViews ? db_query("
    SELECT referrer, COUNT(*) as cnt FROM course_views
    WHERE course_id=? AND created_at >= ? AND referrer IS NOT NULL AND referrer != '' AND referrer NOT LIKE CONCAT(?, '%')
    GROUP BY referrer ORDER BY cnt DESC LIMIT 10
", [$courseId, $since, APP_URL]) : [];

// ── Enrollments & completion ────────────────────────────────────────────
$totalEnrollments  = (int) db_val("SELECT COUNT(*) FROM enrollments WHERE course_id=?", [$courseId]);
$newEnrollments    = (int) db_val("SELECT COUNT(*) FROM enrollments WHERE course_id=? AND enrolled_at >= ?", [$courseId, $since]);
$completedCount    = (int) db_val("SELECT COUNT(*) FROM enrollments WHERE course_id=? AND progress_pct=100", [$courseId]);
$avgProgress       = (float) db_val("SELECT COALESCE(AVG(progress_pct),0) FROM enrollments WHERE course_id=?", [$courseId]);
$enrollTrend       = db_query("SELECT DATE(enrolled_at) as day, COUNT(*) as cnt FROM enrollments WHERE course_id=? AND enrolled_at >= ? GROUP BY DATE(enrolled_at) ORDER BY day ASC", [$courseId, $since]);

$conversionRate = $uniqueViewers > 0 ? round($newEnrollments / $uniqueViewers * 100, 1) : 0.0;

// ── Reviews / exams ──────────────────────────────────────────────────────
$reviewCount = (int) db_val("SELECT COUNT(*) FROM reviews WHERE course_id=? AND is_approved=1", [$courseId]);
$examAttempts = (int) db_val("
    SELECT COUNT(*) FROM exam_attempts ea JOIN exams ex ON ex.id=ea.exam_id
    WHERE ex.course_id=? AND ea.started_at >= ?
", [$courseId, $since]);

// ── Helpers (same fill-in pattern as admin/analytics.php) ─────────────────
function fill_day_trend_ca(array $rows, int $days, string $dayKey, string $valKey): array {
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

[$viewLabels, $viewData]       = fill_day_trend_ca($viewsTrend, $days, 'day', 'views');
[, $visitorData]               = fill_day_trend_ca($viewsTrend, $days, 'day', 'visitors');
[$enrollLabels, $enrollData]   = fill_day_trend_ca($enrollTrend, $days, 'day', 'cnt');

$deviceMap = ['desktop' => 0, 'mobile' => 0, 'tablet' => 0];
foreach ($deviceBreakdown as $d) { $deviceMap[$d['device'] ?: 'desktop'] = (int) $d['cnt']; }

$page_title = $course['title_bn'] . ' — Analytics';
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
        <div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:var(--sp-2)">
            <a href="<?= APP_URL ?>/admin/courses.php" class="btn btn-ghost btn-sm"><span class="material-icons-round">arrow_back</span></a>
            <?php $thumbUrl = course_thumbnail_url($course['thumbnail'] ?? '', $course['title_bn'] ?? '', '80x45', 'e2e8f0'); ?>
            <img src="<?= e($thumbUrl) ?>" alt="" style="width:56px;height:32px;object-fit:cover;border-radius:6px;flex-shrink:0">
            <div>
                <h1 style="font-size:var(--text-xl);line-height:1.2"><?= e($course['title_bn']) ?></h1>
                <p style="color:var(--c-ink-muted);margin:0;font-size:var(--text-sm)"><?= e($course['cat_name']) ?> · <?= e($course['teacher_name']) ?></p>
            </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:6px;margin-bottom:var(--sp-6)">
            <?php foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'] as $p => $label): ?>
            <a href="?id=<?= $courseId ?>&period=<?= $p ?>" class="btn btn-sm <?= $days === $p ? 'btn-primary' : 'btn-ghost' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <?php if (!$hasCourseViews): ?>
        <div class="alert alert-warning" style="margin-bottom:var(--sp-6)">
            <span class="material-icons-round">info</span>
            <span>View tracking just started collecting data for this course — the charts below will fill in as people browse the course page.</span>
        </div>
        <?php endif; ?>

        <!-- ── Views ── -->
        <h3 style="margin-bottom:var(--sp-4)">Course Page Views</h3>
        <div class="analytics-stats" style="display:grid;grid-template-columns:repeat(4,1fr);gap:var(--sp-4);margin-bottom:var(--sp-6)">
            <div class="stat-card">
                <div class="stat-card-icon primary"><span class="material-icons-round">visibility</span></div>
                <div><div class="stat-card-value"><?= format_number($totalViews) ?></div><div class="stat-card-label">Views (<?= $days ?>d)</div>
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:4px"><?= format_number($viewsToday) ?> today</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon success"><span class="material-icons-round">group</span></div>
                <div><div class="stat-card-value"><?= format_number($uniqueViewers) ?></div><div class="stat-card-label">Unique Visitors (<?= $days ?>d)</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon accent"><span class="material-icons-round">bar_chart</span></div>
                <div><div class="stat-card-value"><?= format_number($viewsAllTime) ?></div><div class="stat-card-label">All-Time Views</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon warning"><span class="material-icons-round">swap_horiz</span></div>
                <div><div class="stat-card-value"><?= $conversionRate ?>%</div><div class="stat-card-label">View → Enroll Rate</div></div>
            </div>
        </div>

        <div class="card" style="padding:var(--sp-6);margin-bottom:var(--sp-6)">
            <h3 style="margin-bottom:var(--sp-4)">Views Trend — last <?= $days ?> days</h3>
            <canvas id="viewsChart" style="max-height:260px"></canvas>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-6);margin-bottom:var(--sp-6)" class="two-col-grid">
            <div class="card" style="padding:var(--sp-6)">
                <h3 style="margin-bottom:var(--sp-4)">Device Breakdown</h3>
                <canvas id="deviceChart" style="max-height:220px"></canvas>
            </div>
            <div class="card" style="padding:var(--sp-6)">
                <h3 style="margin-bottom:var(--sp-4)">Top Referrers</h3>
                <?php if (empty($topReferrers)): ?>
                <p style="color:var(--c-ink-muted);text-align:center;padding:var(--sp-8) 0">No referrer data yet.</p>
                <?php else: ?>
                <div class="data-table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Referrer</th><th>Visits</th></tr></thead>
                        <tbody>
                            <?php foreach ($topReferrers as $r): ?>
                            <tr>
                                <td style="font-size:var(--text-sm);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($r['referrer']) ?></td>
                                <td style="font-family:var(--font-latin);font-weight:700"><?= format_number((int) $r['cnt']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Enrollments ── -->
        <h3 style="margin-bottom:var(--sp-4)">Enrollments &amp; Completion</h3>
        <div class="analytics-stats" style="display:grid;grid-template-columns:repeat(4,1fr);gap:var(--sp-4);margin-bottom:var(--sp-6)">
            <div class="stat-card">
                <div class="stat-card-icon primary"><span class="material-icons-round">how_to_reg</span></div>
                <div><div class="stat-card-value"><?= format_number($totalEnrollments) ?></div><div class="stat-card-label">Total Enrollments</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon accent"><span class="material-icons-round">person_add</span></div>
                <div><div class="stat-card-value"><?= format_number($newEnrollments) ?></div><div class="stat-card-label">New Enrollments (<?= $days ?>d)</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon success"><span class="material-icons-round">task_alt</span></div>
                <div><div class="stat-card-value"><?= format_number($completedCount) ?></div><div class="stat-card-label">Completed</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon warning"><span class="material-icons-round">trending_up</span></div>
                <div><div class="stat-card-value"><?= number_format($avgProgress,0) ?>%</div><div class="stat-card-label">Avg. Progress</div></div>
            </div>
        </div>
        <div class="card" style="padding:var(--sp-6);margin-bottom:var(--sp-6)">
            <h3 style="margin-bottom:var(--sp-4)">Enrollments Trend — last <?= $days ?> days</h3>
            <canvas id="enrollChart" style="max-height:220px"></canvas>
        </div>

        <!-- ── Engagement ── -->
        <h3 style="margin-bottom:var(--sp-4)">Engagement</h3>
        <div class="analytics-stats" style="display:grid;grid-template-columns:repeat(3,1fr);gap:var(--sp-4)">
            <div class="stat-card">
                <div class="stat-card-icon primary"><span class="material-icons-round">star</span></div>
                <div><div class="stat-card-value"><?= format_number($reviewCount) ?></div><div class="stat-card-label">Approved Reviews</div>
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:4px">★ <?= number_format((float)$course['rating_avg'],1) ?> avg</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon accent"><span class="material-icons-round">quiz</span></div>
                <div><div class="stat-card-value"><?= format_number($examAttempts) ?></div><div class="stat-card-label">Exam Attempts (<?= $days ?>d)</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon success"><span class="material-icons-round">payments</span></div>
                <div><div class="stat-card-value"><?= $course['is_free'] ? 'Free' : number_format((float)($course['sale_price'] ?: $course['price']),0) ?></div><div class="stat-card-label">Price</div></div>
            </div>
        </div>
    </div>
</div>

<style>
@media(max-width:1100px){.analytics-stats{grid-template-columns:repeat(2,1fr) !important}.two-col-grid{grid-template-columns:1fr !important}}
@media(max-width:600px){.analytics-stats{grid-template-columns:1fr !important}}
</style>

<script>
new Chart(document.getElementById('viewsChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($viewLabels) ?>,
        datasets: [
            { label: 'Views', data: <?= json_encode($viewData) ?>, borderColor: '#5B21B6', backgroundColor: '#5B21B614', fill: true, tension: .35, pointRadius: 2 },
            { label: 'Unique Visitors', data: <?= json_encode($visitorData) ?>, borderColor: '#15BE8B', backgroundColor: '#15BE8B14', fill: true, tension: .35, pointRadius: 2 },
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

new Chart(document.getElementById('enrollChart'), {
    type: 'line',
    data: { labels: <?= json_encode($enrollLabels) ?>, datasets: [{
        label: 'Enrollments', data: <?= json_encode($enrollData) ?>,
        borderColor: '#F59E0B', backgroundColor: '#F59E0B14', fill: true, tension: .35, pointRadius: 2,
    }] },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } },
});
</script>

<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>
