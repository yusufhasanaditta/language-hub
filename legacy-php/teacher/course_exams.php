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
    SELECT c.*
    FROM courses c
    WHERE c.id=? AND (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))
", [$course_id, $tpId, $tpId]);
if (!$course) { flash('error', 'You do not have access to this course.'); redirect(APP_URL.'/teacher/courses.php'); }

$mcq_exams = db_query("SELECT * FROM exams WHERE course_id=? ORDER BY created_at DESC", [$course_id]);
$cq_exams  = db_query("SELECT * FROM cq_exams WHERE course_id=? ORDER BY created_at DESC", [$course_id]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Exam Center: <?= e($course['title_en'] ?: $course['title_bn']) ?> — <?= APP_NAME ?></title>
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

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
        <h2 style="margin:0">Exam Center — <?= e($course['title_en'] ?: $course['title_bn']) ?></h2>
        <div style="display:flex;gap:12px">
            <a href="<?= APP_URL ?>/teacher/exams.php?course=<?= $course_id ?>&action=add" class="btn btn-outline">
                <span class="material-icons-round">add</span> New MCQ Exam
            </a>
            <a href="<?= APP_URL ?>/teacher/cq_builder.php?course=<?= $course_id ?>" class="btn btn-primary">
                <span class="material-icons-round">add</span> New CQ Exam
            </a>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-6)" class="exam-center-grid">

        <!-- CQ Exams (Creative Questions) -->
        <div class="card" style="padding:var(--sp-5)">
            <h3 style="margin-top:0;margin-bottom:var(--sp-5);display:flex;align-items:center;gap:8px">
                <span class="material-icons-round" style="color:var(--c-primary)">edit_document</span>
                CQ (Creative Question) Exams
            </h3>

            <?php if (empty($cq_exams)): ?>
            <div style="text-align:center;padding:var(--sp-8);background:var(--c-surface-hover);border-radius:var(--r-md)">
                <p style="color:var(--c-ink-muted);margin:0;font-size:var(--text-sm)">No CQ exams created yet.</p>
            </div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:12px">
                <?php foreach ($cq_exams as $cq): ?>
                <div style="padding:12px;border:1px solid var(--c-border);border-radius:var(--r-md);display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <div style="font-weight:600;margin-bottom:4px"><?= e($cq['title']) ?></div>
                        <div style="font-size:12px;color:var(--c-ink-muted)">
                            <?= (int)$cq['duration'] ?> mins • <?= (int)$cq['total_marks'] ?> marks
                            • <span class="<?= $cq['status']==='published'?'text-success':'text-warning' ?>"><?= ucfirst($cq['status']) ?></span>
                        </div>
                    </div>
                    <div style="display:flex;gap:4px">
                        <a href="<?= APP_URL ?>/teacher/cq_builder.php?id=<?= $cq['id'] ?>" class="btn btn-ghost btn-sm" title="Edit/Builder">
                            <span class="material-icons-round" style="font-size:16px">build</span>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- MCQ Exams -->
        <div class="card" style="padding:var(--sp-5)">
            <h3 style="margin-top:0;margin-bottom:var(--sp-5);display:flex;align-items:center;gap:8px">
                <span class="material-icons-round" style="color:var(--c-success)">check_circle</span>
                MCQ Exams
            </h3>

            <?php if (empty($mcq_exams)): ?>
            <div style="text-align:center;padding:var(--sp-8);background:var(--c-surface-hover);border-radius:var(--r-md)">
                <p style="color:var(--c-ink-muted);margin:0;font-size:var(--text-sm)">No MCQ exams created yet.</p>
            </div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:12px">
                <?php foreach ($mcq_exams as $ex): ?>
                <div style="padding:12px;border:1px solid var(--c-border);border-radius:var(--r-md);display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <div style="font-weight:600;margin-bottom:4px"><?= e($ex['title']) ?></div>
                        <div style="font-size:12px;color:var(--c-ink-muted)">
                            <?= (int)$ex['duration'] ?> mins • <?= (int)$ex['total_marks'] ?> marks
                            • <span class="<?= $ex['status']==='published'?'text-success':'text-warning' ?>"><?= ucfirst($ex['status']) ?></span>
                        </div>
                    </div>
                    <div style="display:flex;gap:4px">
                        <a href="<?= APP_URL ?>/teacher/exams.php?action=edit&id=<?= (int)$ex['id'] ?>" class="btn btn-ghost btn-sm" title="Settings">
                            <span class="material-icons-round" style="font-size:16px">settings</span>
                        </a>
                        <a href="<?= APP_URL ?>/teacher/exams.php?action=questions&id=<?= (int)$ex['id'] ?>" class="btn btn-ghost btn-sm" title="Questions">
                            <span class="material-icons-round" style="font-size:16px">list</span>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>
<style>
@media(max-width:900px){.exam-center-grid{grid-template-columns:1fr !important}}
</style>
</body>
</html>
