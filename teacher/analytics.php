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

$courseFilter = sanitize_int($_GET['course'] ?? 0);
$period       = sanitize_string($_GET['period'] ?? '30', 5);
$days         = in_array((int)$period, [7,30,90,365]) ? (int)$period : 30;

$myCourses = db_query("SELECT id,title_bn FROM courses WHERE teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=courses.id AND ct.teacher_id=?) ORDER BY title_bn", [$tpId, $tpId]);

// Overall stats (co-teachers count the same as the primary teacher)
$totalStudents = (int)db_val("SELECT COUNT(DISTINCT e.user_id) FROM enrollments e JOIN courses c ON c.id=e.course_id WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)) AND e.status='active'", [$tpId, $tpId]);
$totalCourses  = (int)db_val("SELECT COUNT(*) FROM courses WHERE (teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=courses.id AND ct.teacher_id=?)) AND status='published'", [$tpId, $tpId]);
$avgProgress   = (float)db_val("SELECT COALESCE(AVG(e.progress_pct),0) FROM enrollments e JOIN courses c ON c.id=e.course_id WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)) AND e.status='active'", [$tpId, $tpId]);
$completedCount= (int)db_val("SELECT COUNT(*) FROM enrollments e JOIN courses c ON c.id=e.course_id WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)) AND e.progress_pct=100", [$tpId, $tpId]);
$avgRating     = (float)db_val("SELECT COALESCE(AVG(r.rating),0) FROM reviews r JOIN courses c ON c.id=r.course_id WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))", [$tpId, $tpId]);
$totalReviews  = (int)db_val("SELECT COUNT(*) FROM reviews r JOIN courses c ON c.id=r.course_id WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))", [$tpId, $tpId]);

// New enrollments per day (last N days)
$cWhere = $courseFilter ? "AND c.id=$courseFilter" : '';
$enrollTrend = db_query("
    SELECT DATE(e.enrolled_at) as day, COUNT(*) as cnt
    FROM enrollments e JOIN courses c ON c.id=e.course_id
    WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)) $cWhere AND e.enrolled_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY DATE(e.enrolled_at)
    ORDER BY day ASC
", [$tpId, $tpId, $days]);

// Per-course stats
$courseStats = db_query("
    SELECT c.id, c.title_bn, c.thumbnail, c.status,
           (SELECT COUNT(*) FROM enrollments e WHERE e.course_id=c.id AND e.status='active') as students,
           (SELECT COUNT(*) FROM enrollments e WHERE e.course_id=c.id AND e.progress_pct=100) as completed,
           (SELECT COALESCE(AVG(progress_pct),0) FROM enrollments e WHERE e.course_id=c.id) as avg_progress,
           (SELECT COALESCE(AVG(rating),0) FROM reviews r WHERE r.course_id=c.id) as rating,
           (SELECT COUNT(*) FROM reviews r WHERE r.course_id=c.id) as review_count,
           (SELECT COUNT(*) FROM discussions d WHERE d.course_id=c.id AND d.parent_id IS NULL AND d.is_deleted=0) as question_count
    FROM courses c
    WHERE c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)
    ORDER BY students DESC
", [$tpId, $tpId]);

// Exam performance
$examStats = db_query("
    SELECT ex.title, COUNT(DISTINCT ea.user_id) as attempts,
           COALESCE(AVG(ea.score),0) as avg_score,
           COALESCE(MAX(ea.score),0) as top_score
    FROM exams ex
    JOIN courses c ON c.id=ex.course_id
    LEFT JOIN exam_attempts ea ON ea.exam_id=ex.id AND ea.status IN ('submitted','graded')
    WHERE c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)
    GROUP BY ex.id, ex.title
    ORDER BY attempts DESC
    LIMIT 10
", [$tpId, $tpId]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Analytics — <?= APP_NAME ?></title>
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
            <h1 style="font-size:var(--text-2xl)">Analytics</h1>
            <form method="GET" style="display:flex;gap:var(--sp-3)">
                <select name="course" class="form-control form-control-sm" style="max-width:220px">
                    <option value="">All Courses</option>
                    <?php foreach ($myCourses as $mc): ?>
                    <option value="<?= (int)$mc['id'] ?>" <?= $courseFilter===$mc['id']?'selected':'' ?>><?= e($mc['title_bn']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="period" class="form-control form-control-sm" style="max-width:140px" onchange="this.form.submit()">
                    <?php foreach (['7'=>'Last 7 days','30'=>'Last 30 days','90'=>'Last 90 days','365'=>'Last 1 year'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $period==$v?'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-sm"><span class="material-icons-round">filter_list</span></button>
            </form>
        </div>

        <!-- Overview stats -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:var(--sp-4);margin-bottom:var(--sp-8)" class="analytics-stats">
            <div class="stat-card">
                <div class="stat-card-icon primary"><span class="material-icons-round">people</span></div>
                <div><div class="stat-card-value"><?= number_format($totalStudents) ?></div><div class="stat-card-label">Total Students</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon success"><span class="material-icons-round">trending_up</span></div>
                <div><div class="stat-card-value"><?= number_format($avgProgress,1) ?>%</div><div class="stat-card-label">Average Progress</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon accent"><span class="material-icons-round">check_circle</span></div>
                <div><div class="stat-card-value"><?= number_format($completedCount) ?></div><div class="stat-card-label">Courses Completed</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon warning"><span class="material-icons-round">star</span></div>
                <div><div class="stat-card-value"><?= number_format($avgRating,1) ?></div><div class="stat-card-label">Average Rating</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon primary"><span class="material-icons-round">rate_review</span></div>
                <div><div class="stat-card-value"><?= number_format($totalReviews) ?></div><div class="stat-card-label">Total Reviews</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon success"><span class="material-icons-round">play_circle</span></div>
                <div><div class="stat-card-value"><?= number_format($totalCourses) ?></div><div class="stat-card-label">Published Courses</div></div>
            </div>
        </div>

        <!-- Enrollment chart -->
        <div class="card" style="padding:var(--sp-6);margin-bottom:var(--sp-6)">
            <h3 style="margin-bottom:var(--sp-4)">New Enrollments (last <?= $days ?> days)</h3>
            <?php
            // Fill dates
            $trendMap = [];
            foreach ($enrollTrend as $t) { $trendMap[$t['day']] = (int)$t['cnt']; }
            $labels = []; $data = [];
            for ($i=$days-1; $i>=0; $i--) {
                $d = date('Y-m-d', strtotime("-$i days"));
                $labels[] = date('d M', strtotime($d));
                $data[]   = $trendMap[$d] ?? 0;
            }
            ?>
            <canvas id="enrollChart" style="max-height:240px"></canvas>
            <script>
            new Chart(document.getElementById('enrollChart'), {
                type:'line',
                data:{
                    labels:<?= json_encode($labels) ?>,
                    datasets:[{
                        label:'New Enrollments',
                        data:<?= json_encode($data) ?>,
                        borderColor:'#5B21B6',fill:true,
                        backgroundColor:'rgba(91, 33, 182,0.08)',
                        tension:.35,pointRadius:3,pointBackgroundColor:'#5B21B6'
                    }]
                },
                options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
            });
            </script>
        </div>

        <!-- Course-level stats -->
        <h3 style="font-size:var(--text-xl);margin-bottom:var(--sp-4)">Course Performance</h3>
        <?php if (empty($courseStats)): ?>
        <div style="text-align:center;padding:var(--sp-12);background:var(--c-surface);border-radius:var(--r-lg);color:var(--c-ink-muted)">
            No courses yet
        </div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:var(--sp-4);margin-bottom:var(--sp-8)">
            <?php foreach ($courseStats as $cs): ?>
            <div style="background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--r-lg);padding:var(--sp-5)">
                <div style="display:flex;gap:var(--sp-4);align-items:center;margin-bottom:var(--sp-4)">
                    <img src="<?= e(course_thumbnail_url($cs['thumbnail'], $cs['title_bn'] ?? 'Course', '80x45')) ?>"
                         style="width:80px;height:45px;object-fit:cover;border-radius:var(--r-sm);flex-shrink:0">
                    <div style="flex:1;min-width:0">
                        <div style="font-weight:800;font-size:var(--text-base);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($cs['title_bn']) ?></div>
                        <div style="font-size:var(--text-xs);color:var(--c-ink-muted)">
                            <span style="color:var(--c-warning)">★</span> <?= number_format((float)$cs['rating'],1) ?>
                            (<?= (int)$cs['review_count'] ?> reviews) ·
                            <span class="material-icons-round" style="font-size:12px;vertical-align:middle">forum</span>
                            <?= (int)$cs['question_count'] ?> questions
                        </div>
                    </div>
                    <span class="status-badge <?= $cs['status']==='published'?'status-active':'status-draft' ?>">
                        <?= $cs['status']==='published' ? 'Published' : 'Draft' ?>
                    </span>
                </div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:var(--sp-4)">
                    <div style="text-align:center;padding:var(--sp-3);background:var(--c-surface);border-radius:var(--r-md)">
                        <div style="font-family:var(--font-latin);font-size:var(--text-xl);font-weight:800;color:var(--c-primary)"><?= number_format((int)$cs['students']) ?></div>
                        <div style="font-size:var(--text-xs);color:var(--c-ink-muted)">Students</div>
                    </div>
                    <div style="text-align:center;padding:var(--sp-3);background:var(--c-surface);border-radius:var(--r-md)">
                        <div style="font-family:var(--font-latin);font-size:var(--text-xl);font-weight:800;color:var(--c-success)"><?= number_format((int)$cs['completed']) ?></div>
                        <div style="font-size:var(--text-xs);color:var(--c-ink-muted)">Completed</div>
                    </div>
                    <div style="text-align:center;padding:var(--sp-3);background:var(--c-surface);border-radius:var(--r-md)">
                        <div style="font-family:var(--font-latin);font-size:var(--text-xl);font-weight:800;color:var(--c-accent)"><?= number_format((float)$cs['avg_progress'],0) ?>%</div>
                        <div style="font-size:var(--text-xs);color:var(--c-ink-muted)">Average Progress</div>
                    </div>
                </div>
                <!-- Progress bar for avg completion -->
                <div style="margin-top:var(--sp-3)">
                    <div class="progress-bar" style="height:6px">
                        <div class="progress-fill" style="width:<?= min(100,(float)$cs['avg_progress']) ?>%"></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Exam performance table -->
        <?php if (!empty($examStats)): ?>
        <h3 style="font-size:var(--text-xl);margin-bottom:var(--sp-4)">Exam Performance</h3>
        <div class="data-table-wrap">
            <table class="data-table">
                <thead><tr><th>Exam</th><th>Attempts</th><th>Average Score</th><th>Top Score</th></tr></thead>
                <tbody>
                    <?php foreach ($examStats as $es): ?>
                    <tr>
                        <td style="font-weight:600;font-size:var(--text-sm)"><?= e($es['title']) ?></td>
                        <td style="font-family:var(--font-latin);font-weight:700"><?= (int)$es['attempts'] ?></td>
                        <td>
                            <span style="font-family:var(--font-latin);font-weight:700;color:var(--c-primary)"><?= number_format((float)$es['avg_score'],1) ?></span>
                        </td>
                        <td>
                            <span style="font-family:var(--font-latin);font-weight:700;color:var(--c-success)"><?= number_format((float)$es['top_score'],1) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<style>
@media(max-width:900px){.analytics-stats{grid-template-columns:repeat(2,1fr) !important}}
@media(max-width:480px){.analytics-stats{grid-template-columns:1fr !important}}
</style>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
