<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_login();

$uid = (int)current_user()['id'];
$cid = sanitize_int($_GET['course'] ?? 0);
if (!$cid) redirect(APP_URL.'/dashboard.php');

// Verify enrollment
$enrollment = db_row("SELECT * FROM enrollments WHERE user_id=? AND course_id=? AND status='active'", [$uid, $cid]);
if (!$enrollment) { flash('error','You are not enrolled in this course.'); redirect(APP_URL.'/dashboard.php'); }

$course = db_row("SELECT * FROM courses WHERE id=?", [$cid]);

$mcq_exams = db_query("SELECT * FROM exams WHERE course_id=? AND status='published' ORDER BY created_at DESC", [$cid]);
$cq_exams  = db_query("SELECT * FROM cq_exams WHERE course_id=? AND status='published' ORDER BY created_at DESC", [$cid]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Course Exams: <?= e($course['title_en']?:$course['title_bn']) ?> — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<style>
body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8f9fe; }
.ce-header-wrap { background: linear-gradient(135deg, #f8f9fe 0%, #f1f5f9 100%); padding: 40px 0; margin-bottom: 40px; border-bottom: 1px solid rgba(0,0,0,0.02); }
.ce-header { background: #fff; border-radius: 24px; margin: 0 auto; max-width: 1200px; padding: 32px; box-shadow: 0 24px 48px rgba(0,0,0,0.04), 0 4px 12px rgba(0,0,0,0.02); border: 1px solid rgba(255,255,255,0.8); }
.ce-wrap { max-width: 1200px; margin: 0 auto 64px; padding: 0 var(--sp-6); }
.ce-section-title { display:flex; align-items:center; gap:8px; font-weight:800; font-size: 22px; color: #1a1a2e; margin: 0 0 20px; }
.ce-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-bottom: 48px; }
.ce-card { background: #fff; border-radius: 20px; padding: 24px; border: 1px solid rgba(0,0,0,0.05); box-shadow: 0 4px 12px rgba(0,0,0,0.04); text-decoration: none; color: #333; display: flex; flex-direction: column; gap: 12px; transition: all .25s ease; }
.ce-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(0,0,0,0.08); }
.ce-card h3 { margin: 0; font-size: 17px; font-weight: 800; color: #1a1a2e; line-height: 1.3; }
.ce-meta { display: flex; gap: 14px; font-size: 13px; color: #64748b; font-weight: 700; }
.ce-btn { margin-top: auto; align-self: flex-start; background: rgba(91, 33, 182,0.1); color: var(--c-primary); padding: 8px 18px; border-radius: 20px; font-size: 13px; font-weight: 700; }
.ce-empty { padding: 48px; text-align: center; color: #999; background: #fff; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
</style>
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="ce-header-wrap">
        <div class="ce-header">
            <div style="font-size:14px;text-transform:uppercase;letter-spacing:2px;color:var(--c-primary);margin-bottom:12px;font-weight:800; display:flex; justify-content:space-between; align-items:center;">
                <span><span class="material-icons-round" style="font-size:16px;vertical-align:-3px;margin-right:4px;">quiz</span> Course Exams</span>
                <a href="<?= APP_URL ?>/learn.php?course=<?= $cid ?>" class="btn btn-ghost btn-sm" style="color:#64748b;"><span class="material-icons-round" style="font-size:18px">arrow_back</span> Back to Study Area</a>
            </div>
            <h1 style="margin:0;font-size:32px;font-weight:800;color:#1a1a2e"><?= e($course['title_en'] ?: $course['title_bn']) ?></h1>
            <p style="color:#64748b;margin:8px 0 0;font-size:15px;font-weight:600">All MCQ and written (CQ) exams available across every classroom in this course.</p>
        </div>
    </div>

    <div class="ce-wrap">
        <?php foreach(get_flash() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:24px;border-radius:12px">
            <span class="material-icons-round"><?= $f['type']==='success'?'check_circle':'error' ?></span>
            <span><?= e($f['message']) ?></span>
        </div>
        <?php endforeach; ?>

        <h2 class="ce-section-title"><span class="material-icons-round" style="color:#10b981">check_circle</span> MCQ Exams</h2>
        <?php if (empty($mcq_exams)): ?>
        <div class="ce-empty">No MCQ exams have been published for this course yet.</div>
        <?php else: ?>
        <div class="ce-grid">
            <?php foreach ($mcq_exams as $ex): ?>
            <a href="<?= APP_URL ?>/exams.php?exam=<?= (int)$ex['id'] ?>" class="ce-card">
                <h3><?= e($ex['title']) ?></h3>
                <div class="ce-meta">
                    <span><span class="material-icons-round" style="font-size:15px;vertical-align:-3px">schedule</span> <?= (int)$ex['duration'] ?> min</span>
                    <span><span class="material-icons-round" style="font-size:15px;vertical-align:-3px">military_tech</span> <?= (int)$ex['total_marks'] ?> marks</span>
                </div>
                <span class="ce-btn">Start Exam</span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <h2 class="ce-section-title"><span class="material-icons-round" style="color:var(--c-primary)">edit_document</span> CQ / Written Exams</h2>
        <?php if (empty($cq_exams)): ?>
        <div class="ce-empty">No CQ exams have been published for this course yet.</div>
        <?php else: ?>
        <div class="ce-grid">
            <?php foreach ($cq_exams as $cq): ?>
            <a href="<?= APP_URL ?>/cq_take.php?id=<?= (int)$cq['id'] ?>" class="ce-card">
                <h3><?= e($cq['title']) ?></h3>
                <div class="ce-meta">
                    <span><span class="material-icons-round" style="font-size:15px;vertical-align:-3px">schedule</span> <?= (int)$cq['duration'] ?> min</span>
                    <span><span class="material-icons-round" style="font-size:15px;vertical-align:-3px">military_tech</span> <?= (int)$cq['total_marks'] ?> marks</span>
                </div>
                <span class="ce-btn">Start Exam</span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
