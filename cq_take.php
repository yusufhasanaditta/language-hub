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
$uid = (int)$user['id'];

$exam_id = sanitize_int($_GET['id'] ?? 0);
if (!$exam_id) { redirect(APP_URL.'/dashboard.php'); }

$exam = db_row("SELECT * FROM cq_exams WHERE id=? AND status='published'", [$exam_id]);
if (!$exam) { flash('error', 'Exam not found or not published.'); redirect(APP_URL.'/dashboard.php'); }

$course_id = $exam['course_id'];
if (!is_enrolled($course_id)) {
    flash('error', 'You must be enrolled in this course to take the exam.');
    redirect(APP_URL.'/course.php?id='.$course_id);
}

// Check if already submitted
$submission = db_row("SELECT * FROM cq_submissions WHERE exam_id=? AND user_id=?", [$exam_id, $uid]);
// We no longer redirect if submitted, we just set a flag to hide the submission form.

$evaluations = [];
if ($submission && $submission['status'] === 'graded') {
    foreach (db_query("SELECT * FROM cq_evaluations WHERE submission_id=?", [$submission['id']]) as $ev) {
        $evaluations[$ev['question_id']] = $ev;
    }
}

// Leaderboard for CQ
$leaderboard = [];
if ($exam['show_leaderboard']) {
    $leaderboard = db_query("
        SELECT el.*, u.name as user_name, u.avatar as user_avatar 
        FROM cq_submissions el 
        JOIN users u ON el.user_id = u.id 
        WHERE el.exam_id=? AND el.status='graded' 
        ORDER BY el.score_total DESC, el.id ASC
    ", [$exam_id]);
}

$questions = db_query("SELECT * FROM cq_questions WHERE exam_id=? ORDER BY sort_order ASC", [$exam_id]);

// Process Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $file_link = sanitize_string($_POST['file_link'] ?? '', 1000);
    if (!filter_var($file_link, FILTER_VALIDATE_URL)) {
        flash('error', 'Please provide a valid Google Drive link.');
    } else {
        // We'll trust the timestamp as submitted_at, but we could check against a start time
        // For simplicity, we just mark is_late = 0
        db_insert("INSERT INTO cq_submissions (exam_id, user_id, drive_link, status, is_late) VALUES (?, ?, ?, 'pending', 0)",
            [$exam_id, $uid, $file_link]);
        flash('success', 'Your answer script has been submitted successfully.');
        redirect(APP_URL.'/dashboard.php');
    }
}

$_active = 'exams';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($exam['title']) ?> — <?= APP_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@700;800&family=Hind+Siliguri:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<script>const APP_URL='<?= APP_URL ?>';</script>
<style>
.exam-header { background: #111827; color: white; padding: var(--sp-6) 0; position: sticky; top: 0; z-index: 100; border-bottom: 2px solid var(--c-primary); }
.timer-box { font-family: var(--font-latin); font-size: 32px; font-weight: 800; color: #34d399; }
.timer-box.warning { color: #fbbf24; }
.timer-box.danger { color: #f87171; animation: pulse 1s infinite; }
.cq-box { background: white; border: 1px solid var(--c-border); border-radius: var(--r-lg); padding: var(--sp-6); margin-bottom: var(--sp-6); box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
.cq-part { margin-bottom: 16px; }
.cq-part-label { font-weight: 700; display: inline-block; width: 30px; }
.marks-badge { float: right; font-size: 14px; font-weight: 700; font-family: var(--font-latin); }
@media (max-width: 900px) {
    .cq-layout { flex-direction: column; }
    .cq-sidebar, .cq-sidebar > div { position: static !important; top: auto !important; max-height: none; }
}
@media (max-width: 480px) {
    .timer-box { font-size: 20px; }
    .exam-header .container { flex-wrap: wrap; gap: 8px; }
    .cq-box { padding: var(--sp-4); }
    .cq-part-label { width: 24px; }
}
</style>
</head>
<body style="background:var(--c-surface-hover)">

<div class="exam-header">
    <div class="container" style="display:flex;align-items:center;justify-content:space-between">
        <div>
            <h1 style="margin:0;font-size:20px"><?= e($exam['title']) ?></h1>
            <div style="font-size:14px;color:#9ca3af">Total Marks: <?= (int)$exam['total_marks'] ?> • Duration: <?= (int)$exam['duration'] ?> minutes</div>
        </div>
        <div style="text-align:right">
            <div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#9ca3af;font-weight:700">Time Remaining</div>
            <div class="timer-box" id="timerDisplay">--:--:--</div>
        </div>
    </div>
</div>

<main class="container" style="padding-top:var(--sp-8);padding-bottom:var(--sp-16)">
    
    <?php foreach(get_flash() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:24px">
        <span><?= e($f['message']) ?></span>
    </div>
    <?php endforeach; ?>

    <div class="cq-layout" style="display:flex;gap:var(--sp-8)">

        <!-- Questions Area -->
        <div style="flex:2">
            <?php if ($exam['instructions']): ?>
            <div class="card" style="padding:var(--sp-5);background:var(--c-primary-light);border:1px dashed var(--c-primary);margin-bottom:var(--sp-6)">
                <strong>Instructions:</strong><br>
                <?= nl2br(e($exam['instructions'])) ?>
            </div>
            <?php endif; ?>

            <?php foreach ($questions as $i => $q): 
                $ev = $evaluations[$q['id']] ?? null;
                $qTotal = $ev ? (float)$ev['score_k'] + (float)$ev['score_c'] + (float)$ev['score_a'] + (float)$ev['score_h'] : 0;
            ?>
            <div class="cq-box">
                <div style="display:flex;justify-content:space-between;margin-bottom:20px;border-bottom:2px solid var(--c-border);padding-bottom:12px">
                    <span style="font-weight:800;font-size:20px">Question <?= $i+1 ?></span>
                    <span style="font-weight:700;font-family:var(--font-latin);color:var(--c-ink-muted)">
                        <?= $ev ? "Score: {$qTotal} / " : "" ?>10 Marks
                    </span>
                </div>
                
                <div style="font-size:16px;line-height:1.8;margin-bottom:24px;color:var(--c-ink-dark)">
                    <?= nl2br(e($q['stimulus'])) ?>
                </div>
                
                <div class="cq-part">
                    <span class="marks-badge">1<?= $ev ? " <span style='color:var(--c-primary)'>• Score: ".(float)$ev['score_k']."</span>" : "" ?></span>
                    <span class="cq-part-label">(a)</span> <span style="font-size:16px"><?= e($q['q_k']) ?></span>
                    <?php if ($ev && !empty($ev['note_k'])): ?>
                        <div style="margin-top:8px; margin-left:34px; padding:8px; background:#fffbeb; border-left:3px solid #fbbf24; font-size:14px; color:#92400e;">
                            <strong>Note:</strong> <?= nl2br(e($ev['note_k'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="cq-part">
                    <span class="marks-badge">2<?= $ev ? " <span style='color:var(--c-primary)'>• Score: ".(float)$ev['score_c']."</span>" : "" ?></span>
                    <span class="cq-part-label">(b)</span> <span style="font-size:16px"><?= e($q['q_c']) ?></span>
                    <?php if ($ev && !empty($ev['note_c'])): ?>
                        <div style="margin-top:8px; margin-left:34px; padding:8px; background:#fffbeb; border-left:3px solid #fbbf24; font-size:14px; color:#92400e;">
                            <strong>Note:</strong> <?= nl2br(e($ev['note_c'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="cq-part">
                    <span class="marks-badge">3<?= $ev ? " <span style='color:var(--c-primary)'>• Score: ".(float)$ev['score_a']."</span>" : "" ?></span>
                    <span class="cq-part-label">(c)</span> <span style="font-size:16px"><?= e($q['q_a']) ?></span>
                    <?php if ($ev && !empty($ev['note_a'])): ?>
                        <div style="margin-top:8px; margin-left:34px; padding:8px; background:#fffbeb; border-left:3px solid #fbbf24; font-size:14px; color:#92400e;">
                            <strong>Note:</strong> <?= nl2br(e($ev['note_a'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="cq-part">
                    <span class="marks-badge">4<?= $ev ? " <span style='color:var(--c-primary)'>• Score: ".(float)$ev['score_h']."</span>" : "" ?></span>
                    <span class="cq-part-label">(d)</span> <span style="font-size:16px"><?= e($q['q_h']) ?></span>
                    <?php if ($ev && !empty($ev['note_h'])): ?>
                        <div style="margin-top:8px; margin-left:34px; padding:8px; background:#fffbeb; border-left:3px solid #fbbf24; font-size:14px; color:#92400e;">
                            <strong>Note:</strong> <?= nl2br(e($ev['note_h'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Submission Sidebar -->
        <div class="cq-sidebar" style="flex:1">
            <?php if ($submission): ?>
            <div class="card" style="padding:var(--sp-6);position:sticky;top:100px;background:#f0fdf4;border:1px solid #bbf7d0;">
                <h3 style="margin-top:0;margin-bottom:var(--sp-4);display:flex;align-items:center;gap:8px;color:#166534;">
                    <span class="material-icons-round">check_circle</span> Answer Submitted
                </h3>
                <p style="font-size:14px;color:#166534;margin-bottom:16px;">
                    You have successfully submitted your answer script for this exam. You can view the questions but cannot submit again.
                </p>
                <div style="margin-bottom:16px;">
                    <strong>Your Submitted File:</strong><br>
                    <a href="<?= e($submission['drive_link']) ?>" target="_blank" style="color:var(--c-primary);font-weight:700;word-break:break-all;font-size:14px;"><?= e($submission['drive_link']) ?></a>
                </div>
                <?php if ($submission['status'] === 'graded'): ?>
                <div style="background:#fff;padding:12px;border-radius:8px;border:1px solid #e2e8f0;">
                    <div style="font-weight:800;font-size:18px;margin-bottom:8px;">Score: <?= (float)$submission['score_total'] ?> / <?= (int)$exam['total_marks'] ?></div>
                    <?php if ($submission['teacher_note']): ?>
                        <div style="font-size:14px;color:var(--c-ink-dark);"><strong>Teacher's Note:</strong><br><?= nl2br(e($submission['teacher_note'])) ?></div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div style="font-size:14px;color:#854d0e;background:#fef08a;padding:8px 12px;border-radius:8px;">Your submission is pending grading by the instructor.</div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="card" style="padding:var(--sp-6);position:sticky;top:100px">
                <h3 style="margin-top:0;margin-bottom:var(--sp-4);display:flex;align-items:center;gap:8px">
                    <span class="material-icons-round" style="color:var(--c-primary)">cloud_upload</span> Submit Answer
                </h3>
                <p style="font-size:14px;color:var(--c-ink-light);margin-bottom:24px;line-height:1.6">
                    1. Write your answers on physical paper.<br>
                    2. Scan your papers using CamScanner or similar.<br>
                    3. Save as a single PDF.<br>
                    4. Upload the PDF to your Google Drive.<br>
                    5. Ensure the link access is set to "Anyone with the link".<br>
                    6. Paste the link below.
                </p>

                <form method="POST" id="submissionForm">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label">Google Drive Link *</label>
                        <input type="url" name="file_link" class="form-control" required placeholder="https://drive.google.com/file/d/.../view?usp=sharing" style="font-size:14px">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;font-size:16px;padding:12px">
                        Submit Answer Script
                    </button>
                    <p style="text-align:center;font-size:12px;color:var(--c-ink-muted);margin-top:12px">Once submitted, it cannot be changed.</p>
                </form>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($leaderboard) || !$exam['show_leaderboard']): ?>
            <div class="card" style="padding:var(--sp-6); margin-top:var(--sp-6); position:sticky; top:<?= $submission ? '350px' : '450px' ?>;">
                <h3 style="margin-top:0;margin-bottom:var(--sp-4);display:flex;align-items:center;gap:8px">
                    <span class="material-icons-round" style="color:var(--c-primary)">leaderboard</span> Leaderboard
                </h3>
                
                <?php if (!$exam['show_leaderboard']): ?>
                    <div style="font-size:14px;color:var(--c-ink-light);">Your teacher has not published the leaderboard yet.</div>
                <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:12px;">
                    <?php 
                    $rank = 1;
                    $prevScore = -1;
                    $actualRank = 1;
                    foreach ($leaderboard as $i => $lb): 
                        if ($lb['score_total'] != $prevScore) {
                            $rank = $actualRank;
                            $prevScore = $lb['score_total'];
                        }
                        $actualRank++;
                        $isMe = ($lb['user_id'] == $uid);
                    ?>
                    <div style="display:flex;align-items:center;padding:8px;border-radius:8px;<?= $isMe ? 'background:var(--c-primary-light);border:1px solid var(--c-primary);' : 'background:#f8fafc;' ?>">
                        <div style="width:24px;font-weight:800;color:<?= $rank<=3 ? 'var(--c-primary)' : 'var(--c-ink-muted)' ?>;font-size:14px;">#<?= $rank ?></div>
                        <img src="<?= APP_URL ?>/uploads/avatars/<?= e($lb['user_avatar'] ?: 'default.png') ?>" style="width:32px;height:32px;border-radius:50%;margin-left:8px;object-fit:cover;">
                        <div style="margin-left:12px;flex:1;">
                            <div style="font-weight:600;font-size:14px;<?= $isMe ? 'color:var(--c-primary)' : '' ?>"><?= e($lb['user_name']) ?></div>
                        </div>
                        <div style="font-weight:800;font-family:var(--font-latin);color:var(--c-ink-dark);"><?= (float)$lb['score_total'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>
</main>

<script>
// Timer Logic
const durationMinutes = <?= (int)$exam['duration'] ?>;
let timeRemaining = durationMinutes * 60;
const timerDisplay = document.getElementById('timerDisplay');
const form = document.getElementById('submissionForm');

function updateTimer() {
    if (timeRemaining <= 0) {
        timerDisplay.textContent = "00:00:00";
        timerDisplay.className = "timer-box danger";
        alert("Time is up! Please submit your answer script immediately.");
        return;
    }
    
    const h = Math.floor(timeRemaining / 3600).toString().padStart(2, '0');
    const m = Math.floor((timeRemaining % 3600) / 60).toString().padStart(2, '0');
    const s = (timeRemaining % 60).toString().padStart(2, '0');
    
    timerDisplay.textContent = `${h}:${m}:${s}`;
    
    if (timeRemaining < 300) { // 5 minutes warning
        timerDisplay.className = "timer-box danger";
    } else if (timeRemaining < 900) { // 15 minutes warning
        timerDisplay.className = "timer-box warning";
    }
    
    timeRemaining--;
    setTimeout(updateTimer, 1000);
}

// Start timer only if not submitted
<?php if (!$submission): ?>
updateTimer();
<?php else: ?>
timerDisplay.textContent = "--:--:--";
timerDisplay.style.color = "#166534";
<?php endif; ?>
</script>

</body>
</html>
