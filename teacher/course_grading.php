<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_teacher();

$user = current_user();
$uid = (int)$user['id'];
$tp = db_row("SELECT * FROM teacher_profiles WHERE user_id=?", [$uid]);
if (!$tp) { die('Teacher profile not found.'); }
$tpId = (int)$tp['id'];

$course_id = sanitize_int($_GET['course'] ?? 0);
if (!$course_id) { redirect(APP_URL.'/teacher/courses.php'); }

$course = db_row("
    SELECT c.*,
           (SELECT COUNT(*) FROM enrollments WHERE course_id=c.id) as enroll_count
    FROM courses c
    WHERE c.id=? AND (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))
", [$course_id, $tpId, $tpId]);
if (!$course) { flash('error', 'You do not have access to this course.'); redirect(APP_URL.'/teacher/courses.php'); }

// Un-graded CQ submissions for this course
$ungraded_cq = db_query("
    SELECT s.id, s.submitted_at, s.is_late, e.title as exam_title, u.name as student_name
    FROM cq_submissions s
    JOIN cq_exams e ON e.id = s.exam_id
    JOIN users u ON u.id = s.user_id
    WHERE e.course_id = ? AND s.status = 'pending'
    ORDER BY s.submitted_at ASC
", [$course_id]);

// Course analytics
$total_students = (int)$course['enroll_count'];
$mcq_avg = db_val("
    SELECT AVG(ea.score)
    FROM exam_attempts ea
    JOIN exams e ON e.id = ea.exam_id
    WHERE e.course_id = ? AND ea.status = 'submitted'
", [$course_id]) ?? 0;

$cq_avg = db_val("
    SELECT AVG(s.score_total)
    FROM cq_submissions s
    JOIN cq_exams e ON e.id = s.exam_id
    WHERE e.course_id = ? AND s.status = 'graded'
", [$course_id]) ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Grading & Analytics: <?= e($course['title_en'] ?: $course['title_bn']) ?> — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<script>const APP_URL='<?= APP_URL ?>';const CSRF_TOKEN='<?= csrf_token() ?>';</script>
</head>
<body>
<div class="admin-layout">
<?php include __DIR__.'/includes/sidebar.php'; ?>
<div class="admin-content" style="background:#f8f9fe;padding:32px">
    <?php foreach(get_flash() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:24px;border-radius:12px">
        <span class="material-icons-round"><?= $f['type']==='success'?'check_circle':'error' ?></span>
        <span><?= e($f['message']) ?></span>
    </div>
    <?php endforeach; ?>

    <a href="<?= APP_URL ?>/teacher/classroom.php?id=<?= $course_id ?>" class="btn btn-ghost" style="color:var(--c-ink-light);margin-bottom:16px;font-weight:600;display:inline-flex;align-items:center;gap:4px">
        <span class="material-icons-round" style="font-size:18px">arrow_back</span> Back to Virtual Classrooms
    </a>

    <div style="display:flex;gap:var(--sp-6)" class="grading-desk-grid">

        <!-- Pending Grading -->
        <div style="flex:2">
            <h2 style="margin-top:0;margin-bottom:var(--sp-6)">Pending CQ Grading</h2>

            <?php if (empty($ungraded_cq)): ?>
            <div style="text-align:center;padding:var(--sp-12);background:var(--c-surface);border-radius:var(--r-xl);border:2px dashed var(--c-border)">
                <span class="material-icons-round" style="font-size:48px;color:var(--c-success)">check_circle</span>
                <h3 style="margin:12px 0 8px;color:var(--c-ink-light)">All caught up!</h3>
                <p style="color:var(--c-ink-muted)">No pending CQ submissions to grade.</p>
            </div>
            <?php else: ?>
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Exam</th>
                            <th>Submitted At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ungraded_cq as $sub): ?>
                        <tr>
                            <td style="font-weight:600"><?= e($sub['student_name']) ?></td>
                            <td><?= e($sub['exam_title']) ?></td>
                            <td>
                                <div style="font-size:12px"><?= date('d M, h:i A', strtotime($sub['submitted_at'])) ?></div>
                                <?php if ($sub['is_late']): ?>
                                <span class="chip" style="font-size:10px;color:var(--c-error);border-color:var(--c-error)">Late</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= APP_URL ?>/teacher/cq_grade.php?id=<?= $sub['id'] ?>" class="btn btn-primary btn-sm">
                                    <span class="material-icons-round" style="font-size:16px">grading</span> Grade
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Analytics -->
        <div style="flex:1;display:flex;flex-direction:column;gap:var(--sp-5)">
            <h2 style="margin-top:0;margin-bottom:4px">Analytics Desk</h2>

            <div class="card" style="padding:var(--sp-5);background:linear-gradient(135deg, var(--c-primary), #4338ca);color:white">
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;opacity:0.8;margin-bottom:8px">Total Enrolled</div>
                <div style="font-size:36px;font-weight:800;font-family:var(--font-latin);line-height:1"><?= number_format($total_students) ?></div>
            </div>

            <div class="card" style="padding:var(--sp-5)">
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:var(--c-ink-muted);margin-bottom:8px;font-weight:700">Average MCQ Score</div>
                <div style="display:flex;align-items:baseline;gap:8px">
                    <span style="font-size:32px;font-weight:800;font-family:var(--font-latin);color:var(--c-success)"><?= number_format((float)$mcq_avg, 1) ?></span>
                    <span style="color:var(--c-ink-muted);font-size:14px">points</span>
                </div>
            </div>

            <div class="card" style="padding:var(--sp-5)">
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:var(--c-ink-muted);margin-bottom:8px;font-weight:700">Average CQ Score</div>
                <div style="display:flex;align-items:baseline;gap:8px">
                    <span style="font-size:32px;font-weight:800;font-family:var(--font-latin);color:var(--c-primary)"><?= number_format((float)$cq_avg, 1) ?></span>
                    <span style="color:var(--c-ink-muted);font-size:14px">marks</span>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<style>
@media(max-width:900px){.grading-desk-grid{flex-direction:column !important}}
</style>
</body>
</html>
