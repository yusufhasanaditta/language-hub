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
$uid  = (int)$user['id'];
$aid  = sanitize_int($_GET['attempt'] ?? 0);

if (!$aid) { flash('error', 'Exam information not found.'); redirect(APP_URL . '/exams.php'); }

$attempt = db_row("
    SELECT ea.*, ex.title as exam_title, ex.total_marks, ex.pass_marks,
           ex.duration, ex.negative_mark, ex.course_id,
           COALESCE(ex.exam_type, 'mcq') as exam_type,
           c.title_bn as course_title
    FROM exam_attempts ea
    JOIN exams ex ON ex.id = ea.exam_id
    LEFT JOIN courses c ON c.id = ex.course_id
    WHERE ea.id=? AND ea.user_id=? AND ea.status='in_progress'
", [$aid, $uid]);

if (!$attempt) {
    flash('info', 'Exam not found or already submitted.');
    redirect(APP_URL . '/exams.php');
}

$eid = (int)$attempt['exam_id'];

// ── CQ Exam Mode: handle completely separately, then exit ────────────────────
if (($attempt['exam_type'] ?? 'mcq') === 'cq') {
    $cqQuestions = db_query(
        "SELECT id, body, marks FROM exam_questions WHERE exam_id=? ORDER BY sort_order ASC, id ASC",
        [$eid]
    );
    $cqStartedAt = strtotime($attempt['started_at']);
    $cqTotalSecs = (int)($attempt['duration'] ?? 0) * 60;
    if ($cqTotalSecs === 0) $cqTotalSecs = 31536000; // 1 year representing unlimited
    $cqTotalMark = array_sum(array_column($cqQuestions, 'marks'));

    // POST: submit CQ (drive link)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
        $act  = sanitize_string($_POST['action'] ?? '', 20);
        $aid2 = sanitize_int($_POST['attempt_id'] ?? 0);
        if ($act === 'submit_cq' && $aid2 === $aid) {
            $chk = db_row("SELECT id, user_id, status FROM exam_attempts WHERE id=?", [$aid]);
            if (!$chk || (int)$chk['user_id'] !== $uid || $chk['status'] !== 'in_progress') {
                flash('info', 'This exam has already been submitted.');
                redirect(APP_URL . '/exams.php?exam=' . $eid);
            }
            $driveUrl = sanitize_string($_POST['drive_url'] ?? '', 500);
            $isValid  = (bool)preg_match(
                '#^https://drive\.google\.com/(drive/folders/|open\?id=|file/d/)[A-Za-z0-9_\-]+#i',
                $driveUrl
            );
            if (!$driveUrl || !$isValid) {
                flash('error', 'Please enter a valid Google Drive link.');
                redirect(APP_URL . '/exam-take.php?attempt=' . $aid);
            }
            $timeTaken = min((int)(time() - $cqStartedAt), $cqTotalSecs);
            db_exec(
                "UPDATE exam_attempts SET status='submitted', drive_url=?, submitted_at=NOW(), time_taken=? WHERE id=?",
                [$driveUrl, $timeTaken, $aid]
            );
            redirect(APP_URL . '/exam-result.php?attempt=' . $aid);
        }
    }

    $cqLabels = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($attempt['exam_title'] ?? 'Exam') ?> — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@700;800&family=Hind+Siliguri:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<style>
*{box-sizing:border-box}
body{margin:0;background:#F0EEF9;min-height:100vh;font-family:var(--font-body,'Hind Siliguri',sans-serif)}
.cq-top{background:linear-gradient(135deg,#1A1033,#2D1B8A);color:#fff;padding:0 20px;height:64px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;box-shadow:0 2px 16px rgba(0,0,0,.3)}
.cq-body{max-width:760px;margin:0 auto;padding:24px 20px 60px}
.card-cq{background:#fff;border-radius:16px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.07);margin-bottom:20px}
.cq-q-row{display:flex;gap:16px;padding:16px 0;border-bottom:1px solid #E8E6F5;align-items:flex-start}
.cq-q-row:last-child{border-bottom:none;padding-bottom:0}
.cq-lbl{flex-shrink:0;width:38px;height:38px;border-radius:50%;background:var(--c-primary,#5B21B6);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:17px}
.cq-q-text{flex:1;font-size:1.05rem;line-height:1.8;color:#1a1a2e}
.cq-mkbadge{flex-shrink:0;background:#F0EEF9;color:var(--c-primary,#5B21B6);padding:4px 10px;border-radius:999px;font-size:13px;font-weight:700;white-space:nowrap}
.drive-inp{width:100%;padding:12px 16px;border:2px solid #E2E0F0;border-radius:12px;font-size:15px;transition:border-color .2s;font-family:inherit;display:block}
.drive-inp:focus{outline:none;border-color:var(--c-primary,#5B21B6)}
.drive-inp.ok{border-color:#10B981}
.drive-inp.bad{border-color:#EF4444}
.step-list{background:#F8F7FF;border-radius:10px;padding:14px 20px;margin:12px 0;border:1px solid #E8E6F5}
.step-list ol{margin:0;padding-left:20px;color:#555;font-size:14px;line-height:2.2}
</style>
</head>
<body>
<div class="cq-top">
    <div style="display:flex;align-items:center;gap:12px">
        <div style="font-size:24px;font-weight:800">B</div>
        <div>
            <div style="font-size:10px;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.08em;font-weight:700">Creative Exam in Progress</div>
            <div style="font-weight:700;font-size:clamp(.85rem,2vw,1rem);max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($attempt['exam_title'] ?? '') ?></div>
        </div>
    </div>
    <div style="display:flex;gap:8px">
        <div style="background:rgba(255,255,255,.12);border-radius:8px;padding:6px 14px;text-align:center">
            <div style="font-size:10px;color:rgba(255,255,255,.5);font-weight:700">Total Marks</div>
            <div style="font-size:1.2rem;font-weight:800"><?= number_format($cqTotalMark) ?></div>
        </div>
        <div style="background:rgba(255,255,255,.12);border-radius:8px;padding:6px 14px;text-align:center">
            <div style="font-size:10px;color:rgba(255,255,255,.5);font-weight:700">Questions</div>
            <div style="font-size:1.2rem;font-weight:800"><?= count($cqQuestions) ?></div>
        </div>
    </div>
</div>

<div class="cq-body">
<?php foreach (get_flash() as $f): ?>
<div style="background:<?= $f['type']==='error'?'#FEF2F2':'#F0FFF4' ?>;border:1px solid <?= $f['type']==='error'?'#FECACA':'#BBF7D0' ?>;border-radius:10px;padding:12px 16px;margin-bottom:16px;color:<?= $f['type']==='error'?'#DC2626':'#065F46' ?>">
    <?= e($f['message']) ?>
</div>
<?php endforeach; ?>

<!-- Instructions -->
<div class="card-cq" style="border-left:4px solid var(--c-primary,#5B21B6)">
    <h3 style="margin:0 0 12px;color:var(--c-primary,#5B21B6);display:flex;align-items:center;gap:8px">
        <span class="material-icons-round">info</span> Exam Instructions
    </h3>
    <ul style="margin:0;padding-left:20px;line-height:2.2;color:#374151;font-size:15px">
        <li>Read the questions below carefully</li>
        <li>Write your answers on paper — mark the option letter (A, B, C, D) for each question</li>
        <li>Take photos of your answers and upload them to a Google Drive folder</li>
        <li>Paste the folder link below and <strong>submit</strong></li>
    </ul>
</div>

<!-- Questions -->
<div class="card-cq">
    <h3 style="margin:0 0 20px;display:flex;align-items:center;gap:8px">
        <span class="material-icons-round" style="color:var(--c-primary,#5B21B6)">quiz</span>
        Creative Questions
    </h3>
    <?php if (empty($cqQuestions)): ?>
    <p style="color:#9ca3af;text-align:center;padding:20px">No questions found</p>
    <?php else: ?>
    <?php foreach ($cqQuestions as $qi => $cqq): ?>
    <div class="cq-q-row">
        <div class="cq-lbl"><?= $cqLabels[$qi] ?? ($qi+1) ?></div>
        <div class="cq-q-text"><?= nl2br(e($cqq['body'])) ?></div>
        <div class="cq-mkbadge"><?= (float)$cqq['marks'] ?> Marks</div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Submission -->
<div class="card-cq">
    <h3 style="margin:0 0 12px;display:flex;align-items:center;gap:8px">
        <span class="material-icons-round" style="color:#10B981">upload_file</span>
        Submit Answer Sheet
    </h3>
    <details style="margin-bottom:16px">
        <summary style="cursor:pointer;font-weight:700;color:var(--c-primary,#5B21B6);font-size:14px;padding:8px 0">
            📋 How do I get a Google Drive link? (see steps)
        </summary>
        <div class="step-list">
            <ol>
                <li>Take a photo of each answer page on your phone (make sure it's clear and well-lit)</li>
                <li>Open Google Drive → "+ New" → "Folder" → name the folder</li>
                <li>Upload all the photos to that folder</li>
                <li>Long-press / right-click the folder → "Share" → "Anyone with the link" → "Copy link"</li>
                <li>Paste the link in the box below and click "Submit"</li>
            </ol>
        </div>
    </details>
    <form method="POST" id="cqForm" onsubmit="return confirmCQ()">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="submit_cq">
        <input type="hidden" name="attempt_id" value="<?= $aid ?>">
        <label style="display:block;font-weight:700;margin-bottom:8px;color:#374151">Google Drive Folder Link *</label>
        <input type="url" name="drive_url" id="driveInp" class="drive-inp"
               required placeholder="https://drive.google.com/drive/folders/..."
               oninput="checkDrive(this)">
        <div id="driveMsg" style="font-size:13px;margin-top:6px;min-height:20px"></div>
        <p style="font-size:12px;color:#9ca3af;margin:8px 0 16px">
            Example: <code>https://drive.google.com/drive/folders/1BxiMVs0XRA5nFM...</code>
        </p>
        <button type="submit" class="btn btn-primary" style="width:100%;padding:14px;font-size:16px" id="cqBtn">
            <span class="material-icons-round">send</span> Submit Exam
        </button>
    </form>
</div>
</div>

<!-- Confirm overlay -->
<div id="cqOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:20px;padding:36px;max-width:420px;width:90%;text-align:center;margin:20px">
        <span class="material-icons-round" style="font-size:52px;color:var(--c-primary,#5B21B6);display:block;margin-bottom:12px">assignment_turned_in</span>
        <h3 style="margin-bottom:8px">Submit Exam?</h3>
        <p style="color:#6b7280;margin-bottom:16px;font-size:15px">Your teacher will review your answer sheet and grade it. Once submitted, it cannot be changed.</p>
        <div id="confirmLinkShow" style="word-break:break-all;background:#F8F7FF;border-radius:8px;padding:10px;font-size:12px;color:var(--c-primary,#5B21B6);margin-bottom:20px;text-align:left"></div>
        <div style="display:flex;gap:12px;justify-content:center">
            <button onclick="document.getElementById('cqOverlay').style.display='none'" class="btn btn-ghost" style="min-width:120px">Cancel</button>
            <button onclick="document.getElementById('cqForm').submit();document.getElementById('cqBtn').disabled=true" class="btn btn-primary" style="min-width:120px">
                <span class="material-icons-round">send</span> Submit
            </button>
        </div>
    </div>
</div>

<script>
const DRIVE_RE = /^https:\/\/drive\.google\.com\/(drive\/folders\/|open\?id=|file\/d\/)[A-Za-z0-9_\-]+/i;
function checkDrive(el) {
    const v = el.value.trim();
    const msg = document.getElementById('driveMsg');
    if (!v) { el.className='drive-inp'; msg.textContent=''; return; }
    if (DRIVE_RE.test(v)) {
        el.className='drive-inp ok';
        msg.innerHTML='<span style="color:#10B981">✓ Valid Google Drive link</span>';
    } else {
        el.className='drive-inp bad';
        msg.innerHTML='<span style="color:#EF4444">✗ Not a valid Google Drive link — enter a link starting with drive.google.com</span>';
    }
}
function confirmCQ() {
    const v = document.getElementById('driveInp').value.trim();
    if (!DRIVE_RE.test(v)) {
        document.getElementById('driveInp').className='drive-inp bad';
        document.getElementById('driveMsg').innerHTML='<span style="color:#EF4444">✗ Please enter a valid Google Drive link</span>';
        document.getElementById('driveInp').focus();
        return false;
    }
    document.getElementById('confirmLinkShow').textContent = v;
    document.getElementById('cqOverlay').style.display = 'flex';
    return false;
}
</script>
</body>
</html>
<?php
    exit;
}
// ── MCQ mode continues below ──────────────────────────────────────────────────

$questions = db_query(
    "SELECT id, body, option_a, option_b, option_c, option_d, correct_option, marks, explanation
     FROM exam_questions WHERE exam_id=? ORDER BY sort_order ASC, id ASC",
    [$eid]
);

if (empty($questions)) {
    flash('error', 'This exam has no questions.');
    redirect(APP_URL . '/exams.php?exam=' . $eid);
}

$qCount = count($questions);

// Remaining time
$startedAt = strtotime($attempt['started_at']);
$elapsed   = max(0, time() - $startedAt);
$totalSecs = max(300, (int)($attempt['duration'] ?? 60) * 60);
$remaining = max(0, $totalSecs - $elapsed);

// Load saved answers (for resume after page refresh)
$savedAnswers = [];
$savedRows = db_query("SELECT question_id, chosen FROM exam_attempt_answers WHERE attempt_id=?", [$aid]);
foreach ($savedRows as $r) { if ($r['chosen']) $savedAnswers[(int)$r['question_id']] = $r['chosen']; }

// ── Force-submit if time expired ─────────────────────────────────────────────
if ($remaining <= 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['action']     = 'submit';
    $_POST['csrf_token'] = $_SESSION['csrf_token'] ?? '';
    $_POST['attempt_id'] = (string)$aid;
    // Answers from saved state
    foreach ($savedAnswers as $qid => $val) {
        $_POST['ans_' . $qid] = $val;
    }
}

// ── POST: submit exam ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $act  = sanitize_string($_POST['action'] ?? '', 20);
    $aid2 = sanitize_int($_POST['attempt_id'] ?? 0);

    if ($act === 'submit' && $aid2 === $aid) {
        // Re-verify ownership
        $chk = db_row("SELECT id, exam_id, user_id, status FROM exam_attempts WHERE id=?", [$aid]);
        if (!$chk || (int)$chk['user_id'] !== $uid || $chk['status'] !== 'in_progress') {
            flash('info', 'This exam has already been submitted.');
            redirect(APP_URL . '/exams.php?exam=' . $eid);
        }

        $gradeQs      = db_query("SELECT id, correct_option, marks FROM exam_questions WHERE exam_id=?", [$eid]);
        $negMark      = (float)($attempt['negative_mark'] ?? 0);
        $totalScore   = 0.0;
        $totalCorrect = 0;
        $totalWrong   = 0;
        $totalSkipped = 0;

        foreach ($gradeQs as $q) {
            $chosen = strtolower(sanitize_string($_POST['ans_' . $q['id']] ?? '', 1));
            $chosen = in_array($chosen, ['a', 'b', 'c', 'd']) ? $chosen : null;

            if ($chosen === null) {
                $totalSkipped++;
                $isCorrect = 0;
            } elseif ($chosen === strtolower((string)$q['correct_option'])) {
                $totalScore += (float)$q['marks'];
                $totalCorrect++;
                $isCorrect = 1;
            } else {
                if ($negMark > 0) $totalScore -= $negMark;
                $totalWrong++;
                $isCorrect = 0;
            }

            db_exec(
                "INSERT INTO exam_attempt_answers (attempt_id, question_id, chosen, is_correct)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE chosen=VALUES(chosen), is_correct=VALUES(is_correct)",
                [$aid, (int)$q['id'], $chosen, $isCorrect]
            );
        }

        $totalScore = max(0.0, $totalScore);
        $timeTaken  = min(time() - $startedAt, $totalSecs);

        db_exec(
            "UPDATE exam_attempts
             SET status='submitted', score=?, submitted_at=NOW(), time_taken=?,
                 total_correct=?, total_wrong=?, total_skipped=?
             WHERE id=?",
            [$totalScore, $timeTaken, $totalCorrect, $totalWrong, $totalSkipped, $aid]
        );

        // Compute rank among submitted attempts for this exam
        $rank = 1 + (int)db_val(
            "SELECT COUNT(DISTINCT user_id) FROM exam_attempts
             WHERE exam_id=? AND score>? AND status IN ('submitted','graded') AND id!=?",
            [$eid, $totalScore, $aid]
        );

        // Leaderboard: only the student's FIRST attempt counts as their official result.
        record_exam_leaderboard_result($eid, $uid, $aid, $totalScore);
        db_exec("UPDATE exam_attempts SET rank=? WHERE id=?", [$rank, $aid]);

        redirect(APP_URL . '/exam-result.php?attempt=' . $aid);
    }
}

// ── POST: autosave ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && (sanitize_string($_POST['action'] ?? '', 20) === 'autosave')
    && csrf_verify()) {
    $answers = $_POST['answers'] ?? [];
    if (is_array($answers)) {
        foreach ($answers as $qid => $val) {
            $qid = (int)$qid;
            $val = strtolower(sanitize_string($val, 1));
            if (!$qid || !in_array($val, ['a','b','c','d'])) continue;
            db_exec(
                "INSERT INTO exam_attempt_answers (attempt_id, question_id, chosen, is_correct) VALUES (?,?,?,0)
                 ON DUPLICATE KEY UPDATE chosen=VALUES(chosen)",
                [$aid, $qid, $val]
            );
        }
        db_exec("UPDATE exam_attempts SET updated_at=NOW() WHERE id=?", [$aid]);
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// Build JS question data
$jsQuestions = [];
foreach ($questions as $q) {
    $opts = [];
    foreach (['a', 'b', 'c', 'd'] as $k) {
        $v = $q['option_' . $k] ?? '';
        if ($v !== '') $opts[$k] = $v;
    }
    $jsQuestions[] = [
        'id'      => (int)$q['id'],
        'body'    => $q['body'] ?? '',
        'options' => $opts,
        'marks'   => (float)$q['marks'],
    ];
}

$page_title = 'Exam: ' . ($attempt['exam_title'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($attempt['exam_title'] ?? 'Exam') ?> — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@700;800&family=Hind+Siliguri:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<style>
*{box-sizing:border-box}
body{margin:0;background:#F0EEF9;min-height:100vh;font-family:var(--font-body,'Hind Siliguri',sans-serif)}
.exam-top{background:linear-gradient(135deg,#1A1033 0%,#2D1B8A 100%);color:#fff;padding:0 var(--sp-5,20px);height:64px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:200;box-shadow:0 2px 16px rgba(0,0,0,.3)}
.timer-wrap{display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.12);border:1.5px solid rgba(255,255,255,.2);border-radius:999px;padding:6px 18px;transition:.3s}
.timer-wrap.warn{background:rgba(245,158,11,.2);border-color:rgba(245,158,11,.5)}
.timer-wrap.danger{background:rgba(239,68,68,.3);border-color:rgba(239,68,68,.6);animation:timerPulse .7s infinite}
#timerVal{font-family:var(--font-latin,'Plus Jakarta Sans',sans-serif);font-size:1.3rem;font-weight:800;min-width:70px;text-align:center;letter-spacing:.04em}
.exam-body{display:grid;grid-template-columns:1fr 260px;min-height:calc(100vh - 64px)}
.question-pane{padding:var(--sp-6,24px);max-width:820px}
.q-card{background:#fff;border-radius:16px;padding:28px 32px;box-shadow:0 2px 12px rgba(0,0,0,.07)}
.q-num{font-size:12px;font-weight:800;color:var(--c-primary,#5B21B6);text-transform:uppercase;letter-spacing:.1em;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.q-body{font-size:1.05rem;font-weight:600;line-height:1.8;color:#1a1a2e;margin-bottom:24px}
.option-item{display:flex;align-items:center;gap:14px;padding:14px 18px;border:2px solid #E2E0F0;border-radius:12px;cursor:pointer;transition:all .18s;margin-bottom:10px;background:#fff;user-select:none}
.option-item:hover{border-color:var(--c-primary,#5B21B6);background:#F7F5FF}
.option-item.selected{border-color:var(--c-primary,#5B21B6);background:rgba(91, 33, 182,.07);font-weight:700}
.option-key{flex-shrink:0;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;border:2px solid #E2E0F0;transition:.18s}
.option-item.selected .option-key{background:var(--c-primary,#5B21B6);color:#fff;border-color:var(--c-primary,#5B21B6)}
.q-nav{display:flex;justify-content:space-between;align-items:center;margin-top:24px;gap:12px}
/* Sidebar */
.palette-pane{background:#fff;border-left:1px solid #E8E6F5;padding:20px;position:sticky;top:64px;height:calc(100vh - 64px);overflow-y:auto;display:flex;flex-direction:column;gap:16px}
.palette-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:6px}
.p-btn{aspect-ratio:1;border-radius:8px;border:2px solid #E2E0F0;background:#fff;font-weight:800;font-size:13px;font-family:var(--font-latin,'Plus Jakarta Sans',sans-serif);cursor:pointer;transition:all .15s;display:flex;align-items:center;justify-content:center}
.p-btn:hover{border-color:var(--c-primary,#5B21B6);color:var(--c-primary,#5B21B6)}
.p-btn.answered{background:var(--c-primary,#5B21B6);color:#fff;border-color:var(--c-primary,#5B21B6)}
.p-btn.current{box-shadow:0 0 0 3px rgba(91, 33, 182,.25)}
.legend{display:flex;gap:10px;flex-wrap:wrap;font-size:12px}
.legend span{display:flex;align-items:center;gap:5px}
.leg-dot{width:12px;height:12px;border-radius:3px;display:inline-block}
/* Negative mark warning */
.neg-badge{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:8px;padding:8px 14px;font-size:13px;color:#92400E;display:flex;align-items:center;gap:6px}
/* Submit modal */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;display:flex;align-items:center;justify-content:center;display:none}
.modal-box{background:#fff;border-radius:20px;padding:36px;max-width:420px;width:90%;text-align:center}
/* Progress */
.prog-bar{height:6px;background:#E8E6F5;border-radius:3px;overflow:hidden;margin-top:4px}
.prog-fill{height:100%;background:var(--c-primary,#5B21B6);border-radius:3px;transition:width .3s}
@keyframes timerPulse{0%,100%{opacity:1}50%{opacity:.55}}
@media(max-width:768px){
  .exam-body{grid-template-columns:1fr}
  .palette-pane{position:fixed;bottom:0;left:0;right:0;height:auto;max-height:40vh;border-left:none;border-top:1px solid #E8E6F5;z-index:150;flex-direction:row;flex-wrap:wrap;padding:12px 16px;gap:10px}
  .palette-pane > div:first-child{order:0}
  .palette-pane .palette-grid{order:1}
  .palette-pane > div:last-child{order:2;flex:0 0 100%;margin-top:0}
  .q-card{padding:20px}
  .question-pane{padding:16px;padding-bottom:180px}
  .q-nav{flex-wrap:wrap;justify-content:center}
  .q-nav #btnPrev,.q-nav #btnNext{flex:1 1 auto;min-width:45%}
  .q-nav #qProgress{order:-1;flex:0 0 100%;text-align:center;margin-bottom:4px}
}
</style>
</head>
<body>

<!-- ── Top bar ── -->
<div class="exam-top">
    <div style="display:flex;align-items:center;gap:12px;min-width:0">
        <div style="font-size:26px;font-weight:800;color:#fff;flex-shrink:0">B</div>
        <div style="overflow:hidden">
            <div style="font-size:11px;color:rgba(255,255,255,.5);font-weight:700;text-transform:uppercase;letter-spacing:.08em">Exam in Progress</div>
            <div style="color:#fff;font-weight:700;font-size:clamp(.85rem,2vw,1rem);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:300px"><?= e($attempt['exam_title'] ?? '') ?></div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:16px;flex-shrink:0">
        <div style="text-align:center">
            <div style="font-size:10px;color:rgba(255,255,255,.5);font-weight:700">Answered</div>
            <div style="font-family:var(--font-latin);font-size:1.1rem;font-weight:800;color:#fff"><span id="hdrAns">0</span>/<?= $qCount ?></div>
        </div>
        <div class="timer-wrap" id="timerWrap">
            <span class="material-icons-round" style="font-size:18px">timer</span>
            <span id="timerVal"><?= sprintf('%02d:%02d', intdiv($remaining, 60), $remaining % 60) ?></span>
        </div>
    </div>
</div>

<!-- ── Main layout ── -->
<div class="exam-body">

<!-- Question pane -->
<div class="question-pane" id="questionPane">
    <?php if ($attempt['negative_mark'] > 0): ?>
    <div class="neg-badge" style="margin-bottom:16px">
        <span class="material-icons-round" style="font-size:16px;color:#D97706">warning</span>
        Negative marking: <?= (float)$attempt['negative_mark'] ?> mark(s) will be deducted per wrong answer
    </div>
    <?php endif; ?>

    <div class="q-card" id="questionCard">
        <!-- JS fills this -->
        <div style="text-align:center;padding:40px;color:#9ca3af">
            <span class="material-icons-round" style="font-size:40px">hourglass_top</span>
            <p>Loading...</p>
        </div>
    </div>

    <div class="q-nav">
        <button class="btn btn-ghost" id="btnPrev" onclick="go(currentQ - 1)" disabled>
            <span class="material-icons-round">arrow_back</span> Previous Question
        </button>
        <div style="font-size:var(--text-sm);color:#6b7280" id="qProgress">1 / <?= $qCount ?></div>
        <button class="btn btn-primary" id="btnNext" onclick="go(currentQ + 1)">
            Next Question <span class="material-icons-round">arrow_forward</span>
        </button>
    </div>
</div>

<!-- Palette sidebar -->
<div class="palette-pane">
    <div>
        <div style="font-weight:700;font-size:14px;margin-bottom:10px">Question List</div>
        <div class="legend">
            <span><span class="leg-dot" style="background:var(--c-primary,#5B21B6)"></span>Answered</span>
            <span><span class="leg-dot" style="background:#E2E0F0;border:1px solid #ccc"></span>Remaining</span>
        </div>
    </div>
    <div class="palette-grid" id="palette">
        <?php foreach ($questions as $qi => $q): ?>
        <button class="p-btn <?= isset($savedAnswers[(int)$q['id']]) ? 'answered' : '' ?>"
                id="pb<?= $qi ?>" onclick="go(<?= $qi ?>)" title="Question <?= $qi+1 ?>"><?= $qi+1 ?></button>
        <?php endforeach; ?>
    </div>
    <div style="margin-top:auto">
        <div class="prog-bar"><div class="prog-fill" id="progFill" style="width:0%"></div></div>
        <div style="font-size:12px;color:#6b7280;margin-top:4px;text-align:center"><span id="progTxt">0</span> / <?= $qCount ?> answered</div>
        <button class="btn btn-primary" style="width:100%;margin-top:12px" onclick="openSubmitModal()">
            <span class="material-icons-round">check_circle</span> Submit Exam
        </button>
    </div>
</div>
</div>

<!-- ── Submit modal ── -->
<div class="modal-bg" id="submitModal">
    <div class="modal-box">
        <span class="material-icons-round" style="font-size:52px;color:#F59E0B;display:block;margin-bottom:8px">quiz</span>
        <h2 style="margin-bottom:8px">Submit Exam?</h2>
        <p style="color:#6b7280;margin-bottom:6px">
            Answered: <strong id="modalAns" style="color:var(--c-primary,#5B21B6)">0</strong> / <?= $qCount ?>
        </p>
        <p id="modalUnansweredMsg" style="color:#EF4444;font-size:14px;margin-bottom:20px"></p>
        <div style="display:flex;gap:12px;justify-content:center">
            <button class="btn btn-ghost" onclick="closeSubmitModal()" style="min-width:120px">Continue Exam</button>
            <button class="btn btn-primary" onclick="doSubmit()" id="confirmSubmitBtn" style="min-width:120px">
                <span class="material-icons-round">send</span> Submit
            </button>
        </div>
    </div>
</div>

<!-- ── Submitting overlay ── -->
<div id="submittingOverlay" style="display:none;position:fixed;inset:0;background:rgba(13,11,46,.85);z-index:2000;align-items:center;justify-content:center;flex-direction:column;gap:16px">
    <div style="width:60px;height:60px;border:5px solid rgba(255,255,255,.2);border-top-color:#fff;border-radius:50%;animation:spin 1s linear infinite"></div>
    <div style="color:#fff;font-size:18px;font-weight:700">Submitting exam...</div>
</div>

<!-- ── Hidden form for submission ── -->
<form method="POST" id="examForm" action="<?= APP_URL ?>/exam-take.php?attempt=<?= $aid ?>" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="submit">
    <input type="hidden" name="attempt_id" value="<?= $aid ?>">
    <?php foreach ($questions as $q): ?>
    <input type="hidden" name="ans_<?= (int)$q['id'] ?>" id="hidAns<?= (int)$q['id'] ?>" value="">
    <?php endforeach; ?>
</form>

<style>@keyframes spin{to{transform:rotate(360deg)}}</style>

<script>
/* ── Config ── */
const QUESTIONS = <?= json_encode($jsQuestions, JSON_UNESCAPED_UNICODE) ?>;
const SAVED     = <?= json_encode($savedAnswers, JSON_UNESCAPED_UNICODE) ?>;
const CSRF      = <?= json_encode(csrf_token()) ?>;
const AID       = <?= (int)$aid ?>;
const APP_URL   = <?= json_encode(APP_URL) ?>;
const REMAINING = <?= (int)$remaining ?>;
const OPT_LABELS = {a:'A', b:'B', c:'C', d:'D'};

/* ── State ── */
let currentQ = 0;
let answers  = {};  // { qid: 'a'|'b'|'c'|'d' }

// Pre-load saved answers
Object.keys(SAVED).forEach(qid => { answers[parseInt(qid)] = SAVED[qid]; });

/* ── Render question ── */
function renderQuestion(idx) {
    const q   = QUESTIONS[idx];
    const sel = answers[q.id] || null;
    const btnNext = document.getElementById('btnNext');
    const btnPrev = document.getElementById('btnPrev');

    const isLast = idx === QUESTIONS.length - 1;
    const isFirst = idx === 0;

    btnPrev.disabled = isFirst;
    btnNext.textContent = '';
    if (isLast) {
        btnNext.innerHTML = 'Review All <span class="material-icons-round">done_all</span>';
        btnNext.onclick = () => openSubmitModal();
    } else {
        btnNext.innerHTML = 'Next Question <span class="material-icons-round">arrow_forward</span>';
        btnNext.onclick = () => go(currentQ + 1);
    }

    let optsHtml = '';
    Object.entries(q.options).forEach(([k, v]) => {
        const isSel = sel === k;
        optsHtml += `
        <div class="option-item ${isSel ? 'selected' : ''}"
             onclick="selectOption(${q.id}, '${k}', this.closest('.option-item').parentNode)"
             role="button" tabindex="0" onkeydown="if(event.key==='Enter')selectOption(${q.id},'${k}',this.closest('.option-item').parentNode)">
            <div class="option-key">${OPT_LABELS[k]}</div>
            <div style="flex:1;line-height:1.65">${escHtml(v)}</div>
            ${isSel ? '<span class="material-icons-round" style="color:var(--c-primary,#5B21B6);flex-shrink:0;font-size:20px">check_circle</span>' : ''}
        </div>`;
    });

    document.getElementById('questionCard').innerHTML = `
        <div class="q-num">
            <span style="background:var(--c-primary,#5B21B6);color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px">${idx+1}</span>
            Question ${idx+1} / ${QUESTIONS.length}
            <span style="margin-left:auto;background:#F0EEF9;color:#5B21B6;padding:2px 10px;border-radius:999px;font-size:12px">${q.marks} Marks</span>
        </div>
        <div class="q-body">${nl2br(escHtml(q.body))}</div>
        <div id="optList">${optsHtml}</div>`;

    document.getElementById('qProgress').textContent = `${idx+1} / ${QUESTIONS.length}`;

    // Update palette active
    document.querySelectorAll('.p-btn').forEach((b, i) => b.classList.toggle('current', i === idx));

    // Scroll palette button into view
    const pb = document.getElementById('pb' + idx);
    if (pb) pb.scrollIntoView({block:'nearest', inline:'center'});
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function nl2br(s) { return s.replace(/\n/g,'<br>'); }

/* ── Select option ── */
function selectOption(qid, val, container) {
    answers[qid] = val;

    // Update hidden form input
    const inp = document.getElementById('hidAns' + qid);
    if (inp) inp.value = val;

    // Update palette button
    const qi = QUESTIONS.findIndex(q => q.id === qid);
    const pb = document.getElementById('pb' + qi);
    if (pb) pb.classList.add('answered');

    // Update UI
    renderQuestion(currentQ);
    updateProgress();
}

/* ── Navigate ── */
function go(idx) {
    if (idx < 0 || idx >= QUESTIONS.length) return;
    currentQ = idx;
    renderQuestion(idx);
}

/* ── Progress ── */
function updateProgress() {
    const cnt = Object.keys(answers).length;
    document.getElementById('hdrAns').textContent = cnt;
    document.getElementById('progTxt').textContent = cnt;
    const pct = QUESTIONS.length > 0 ? (cnt / QUESTIONS.length * 100) : 0;
    document.getElementById('progFill').style.width = pct + '%';
}

/* ── Submit modal ── */
function openSubmitModal() {
    const cnt = Object.keys(answers).length;
    const left = QUESTIONS.length - cnt;
    document.getElementById('modalAns').textContent = cnt;
    document.getElementById('modalUnansweredMsg').textContent =
        left > 0 ? `${left} question(s) unanswered.` : '✓ All questions answered.';
    const modal = document.getElementById('submitModal');
    modal.style.display = 'flex';
}
function closeSubmitModal() {
    document.getElementById('submitModal').style.display = 'none';
}

/* ── Final submit ── */
function doSubmit() {
    document.getElementById('confirmSubmitBtn').disabled = true;
    document.getElementById('submittingOverlay').style.display = 'flex';

    // Write all answers to hidden form inputs
    QUESTIONS.forEach(q => {
        const inp = document.getElementById('hidAns' + q.id);
        if (inp) inp.value = answers[q.id] || '';
    });

    document.getElementById('examForm').submit();
}

/* ── Auto-submit on timer expiry ── */
function forceSubmit() {
    // Write answers then force POST
    QUESTIONS.forEach(q => {
        const inp = document.getElementById('hidAns' + q.id);
        if (inp) inp.value = answers[q.id] || '';
    });
    document.getElementById('submittingOverlay').style.display = 'flex';
    document.getElementById('examForm').submit();
}

/* ── Countdown timer ── */
const startMs = Date.now();
function tickTimer() {
    const elapsed = Math.floor((Date.now() - startMs) / 1000);
    const left    = REMAINING - elapsed;
    const wrap    = document.getElementById('timerWrap');
    const val     = document.getElementById('timerVal');

    if (left <= 0) {
        val.textContent = '00:00';
        wrap.className = 'timer-wrap danger';
        forceSubmit();
        return;
    }

    const m = Math.floor(left / 60);
    const s = left % 60;
    val.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    wrap.className = 'timer-wrap' + (left <= 60 ? ' danger' : left <= 300 ? ' warn' : '');
}
tickTimer();
setInterval(tickTimer, 1000);

/* ── AJAX autosave every 30 seconds ── */
setInterval(() => {
    if (!Object.keys(answers).length) return;
    const fd = new FormData();
    fd.append('action', 'autosave');
    fd.append('csrf_token', CSRF);
    fd.append('attempt_id', AID);
    Object.entries(answers).forEach(([k, v]) => fd.append('answers[' + k + ']', v));
    fetch(APP_URL + '/exam-take.php?attempt=' + AID, {method:'POST', body:fd}).catch(() => {});
}, 30000);

/* ── Anti-cheat: tab switch warning ── */
let tabSwitches = 0;
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        tabSwitches++;
        if (tabSwitches >= 3) {
            // Show brief warning on return
            document.addEventListener('visibilitychange', function warnOnce() {
                if (!document.hidden) {
                    const div = Object.assign(document.createElement('div'), {
                        textContent: '⚠ Warning: Tab switching is being recorded.',
                        style: 'position:fixed;top:70px;left:50%;transform:translateX(-50%);background:#EF4444;color:#fff;padding:10px 20px;border-radius:8px;z-index:500;font-weight:700'
                    });
                    document.body.appendChild(div);
                    setTimeout(() => div.remove(), 3000);
                    document.removeEventListener('visibilitychange', warnOnce);
                }
            });
        }
    }
});

/* ── Init ── */
renderQuestion(0);
updateProgress();
</script>
</body>
</html>
