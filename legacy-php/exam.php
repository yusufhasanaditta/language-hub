<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_login();

$examId    = sanitize_int($_GET['exam'] ?? 0);
$attemptId = sanitize_int($_GET['attempt'] ?? 0);
$userId    = (int)$_SESSION['user_id'];

if (!$examId) { flash('error', 'Exam not found.'); redirect(APP_URL . '/dashboard.php'); }

$exam = db_row("SELECT * FROM exams WHERE id=? AND status='published'", [$examId]);
if (!$exam) { flash('error', 'This exam is not available.'); redirect(APP_URL . '/dashboard.php'); }

if (!empty($exam['course_id']) && !is_enrolled((int)$exam['course_id'])) {
    flash('error', 'You do not have access to this exam.');
    redirect(APP_URL . '/course.php?slug=' . ($exam['course_slug'] ?? ''));
}

$maxAttempts = (int)($exam['max_attempts'] ?? 0);
if ($maxAttempts > 0) {
    $doneCount = (int)db_val(
        "SELECT COUNT(*) FROM exam_attempts WHERE user_id=? AND exam_id=? AND status IN ('submitted','graded')",
        [$userId, $examId]
    );
    if ($doneCount >= $maxAttempts && !$attemptId) {
        flash('error', 'You have reached the maximum number of attempts for this exam.');
        redirect(APP_URL . '/dashboard.php');
    }
}

$durationSec = (int)($exam['duration'] ?? 0) * 60;
$isUnlimited = ($durationSec === 0);

$attempt = null;
if ($attemptId) {
    $attempt = db_row("SELECT * FROM exam_attempts WHERE id=? AND user_id=? AND exam_id=?", [$attemptId, $userId, $examId]);
}
if (!$attempt) {
    $attempt = db_row("SELECT * FROM exam_attempts WHERE user_id=? AND exam_id=? AND status='in_progress' ORDER BY started_at DESC LIMIT 1", [$userId, $examId]);
}

// If we picked up an in-progress attempt whose time has actually run out (e.g. it
// was abandoned mid-exam), auto-finalize it with whatever was autosaved instead of
// resuming it with a dead 00:00 timer — then start a genuinely fresh attempt below.
if ($attempt && $attempt['status'] === 'in_progress' && !$isUnlimited) {
    $elapsedSoFar = time() - strtotime($attempt['started_at']);
    if ($elapsedSoFar >= $durationSec) {
        finalize_overdue_mcq_attempt((int)$attempt['id']);
        $attempt = null;
    }
}

if (!$attempt) {
    $newId = db_insert(
        "INSERT INTO exam_attempts (user_id,exam_id,status,started_at) VALUES (?,?,'in_progress',NOW())",
        [$userId, $examId]
    );
    $attempt = db_row("SELECT * FROM exam_attempts WHERE id=?", [$newId]);
}
if (in_array($attempt['status'], ['submitted', 'graded'])) {
    redirect(APP_URL . '/exam-result.php?attempt=' . $attempt['id']);
}

$questions = db_query("SELECT * FROM exam_questions WHERE exam_id=? ORDER BY sort_order, id", [$examId]);
if (empty($questions)) {
    flash('error', 'This exam has no questions.');
    redirect(APP_URL . '/dashboard.php');
}
$totalQ = count($questions);

$savedAnswers = [];
$savedRows = db_query("SELECT question_id, chosen FROM exam_attempt_answers WHERE attempt_id=?", [$attempt['id']]);
foreach ($savedRows as $r) { if ($r['chosen']) $savedAnswers[(int)$r['question_id']] = $r['chosen']; }
if ($isUnlimited) {
    $remaining = 31536000; // 1 year representing unlimited
} else {
    $elapsed   = max(0, time() - strtotime($attempt['started_at']));
    $remaining = max(0, $durationSec - $elapsed);
}

$page_title = $exam['title'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <style>
        :root {
            --verif-bg: linear-gradient(135deg, #74a896, #406c74);
            --verif-outer: #e6f0ed;
            --verif-btn-dark: #254030;
            --font-main: 'Plus Jakarta Sans', sans-serif;
        }
        body {
            background: var(--verif-bg);
            font-family: var(--font-main);
            color: #1e293b;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            box-sizing: border-box;
        }
        
        .outer-wrapper {
            background: var(--verif-outer);
            border-radius: 24px;
            width: 100%;
            max-width: 1100px;
            padding: 32px 40px;
            box-sizing: border-box;
            position: relative;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }

        /* Abstract decorative lines on outer wrapper */
        .outer-wrapper::before {
            content: '';
            position: absolute;
            top: -10%; left: -10%;
            width: 120%; height: 120%;
            background-image: linear-gradient(45deg, transparent 48%, rgba(0,0,0,0.03) 49%, rgba(0,0,0,0.03) 51%, transparent 52%);
            background-size: 800px 800px;
            pointer-events: none;
            z-index: 0;
        }

        .header-brand {
            position: relative;
            z-index: 10;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .brand-logo { font-weight: 800; font-size: 28px; color: #1e293b; letter-spacing: -1px; }
        
        .user-profile { display: flex; align-items: center; gap: 12px; }
        .user-avatar { width: 44px; height: 44px; border-radius: 50%; background: #ff9800; border: 2px solid #fff; }
        .user-info { display: flex; flex-direction: column; }
        .user-name { font-weight: 700; font-size: 15px; color: #1e293b; }
        .user-id { font-weight: 600; font-size: 13px; color: #64748b; }

        .inner-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 40px;
            position: relative;
            z-index: 10;
            box-shadow: 0 10px 30px rgba(0,0,0,0.04);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
        }
        
        .timer-box { display: flex; align-items: center; gap: 12px; }
        .timer-icon { color: #000; font-size: 28px; }
        .timer-text-col { display: flex; flex-direction: column; }
        .timer-label { font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .timer-time { font-size: 18px; font-weight: 800; color: #000; letter-spacing: 1px; font-variant-numeric: tabular-nums; }
        
        .btn-submit {
            background: var(--verif-btn-dark);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 12px 32px;
            font-family: var(--font-main);
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .btn-submit:hover { opacity: 0.9; }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 240px;
            gap: 40px;
            align-items: center;
        }

        .q-meta { font-size: 14px; font-weight: 700; color: #000; margin-bottom: 16px; }
        .q-text { font-size: 18px; font-weight: 600; color: #1e293b; line-height: 1.6; margin-bottom: 32px; }

        .options-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .option-label {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            background: #fff;
        }
        .option-label:hover { border-color: #cbd5e1; }
        .option-label.selected { border-color: var(--verif-btn-dark); border-width: 2px; }
        .opt-letter {
            font-weight: 800; font-size: 16px; color: #64748b; width: 20px;
        }
        .option-label.selected .opt-letter { color: var(--verif-btn-dark); }
        .opt-text { font-size: 15px; font-weight: 700; color: #1e293b; }

        /* Progress Circle */
        .progress-circle-wrap {
            position: relative;
            width: 180px; height: 180px;
            margin: 0 auto;
        }
        .progress-circle-wrap svg {
            transform: rotate(-90deg);
            width: 180px; height: 180px;
        }
        .progress-bg { fill: none; stroke: #e2e8f0; stroke-width: 16; }
        .progress-bar { 
            fill: none; stroke: var(--verif-btn-dark); stroke-width: 16; 
            stroke-dasharray: 471; /* 2*pi*75 */
            stroke-dashoffset: 471;
            stroke-linecap: round;
            transition: stroke-dashoffset 0.4s ease;
        }
        .progress-text {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 800;
            color: #000;
        }

        /* Pagination */
        .pagination-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 48px;
            justify-content: space-between;
        }
        .page-btn {
            background: #fff;
            border: 2px solid #e2e8f0;
            color: #1e293b;
            height: 44px; min-width: 44px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 15px;
            font-family: var(--font-main);
            cursor: pointer; transition: all 0.2s;
        }
        .page-btn:hover { border-color: #cbd5e1; }
        .page-btn.answered { background: var(--verif-btn-dark); border-color: var(--verif-btn-dark); color: #fff; }
        .page-btn.active { border-color: var(--verif-btn-dark); color: var(--verif-btn-dark); }
        .page-btn.answered.active { border-color: #000; }
        .page-btn-wide { padding: 0 24px; min-width: 80px; }
        
        .page-numbers-scroll {
            display: flex; gap: 8px; flex: 1; overflow-x: auto; padding-bottom: 4px;
            justify-content: center;
        }
        
        .page-numbers-scroll::-webkit-scrollbar { height: 4px; }
        .page-numbers-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        @media (max-width: 900px) {
            .content-grid { grid-template-columns: 1fr; gap: 32px; }
            .progress-circle-wrap { order: -1; width: 140px; height: 140px; }
            .progress-circle-wrap svg { width: 140px; height: 140px; }
            .progress-text { font-size: 24px; }
        }

        @media (max-width: 768px) {
            body { padding: 20px 12px; }
            .outer-wrapper { padding: 24px 16px; border-radius: 20px; }
            .inner-card { padding: 24px 16px; }
            .header-brand { flex-direction: column; align-items: flex-start; gap: 16px; margin-bottom: 16px; }
            .options-grid { grid-template-columns: 1fr; }
            .page-numbers-scroll { -webkit-overflow-scrolling: touch; }
            .card-header { flex-wrap: wrap; gap: 12px; margin-bottom: 24px; }
        }

        @media (max-width: 480px) {
            .brand-logo { font-size: 22px; }
            .btn-submit { padding: 10px 20px; font-size: 14px; }
            .q-text { font-size: 16px; }
            .pagination-row { gap: 6px; }
            .page-btn-wide { min-width: 64px; padding: 0 14px; }
        }
        
        .hidden { display: none !important; }
    </style>
    <script>
        const APP_URL = '<?= APP_URL ?>';
        const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?? '' ?>';
        const EXAM_CONFIG = {
            attemptId : <?= (int)$attempt['id'] ?>,
            examId    : <?= (int)$examId ?>,
            totalQ    : <?= $totalQ ?>,
            remaining : <?= (int)$remaining ?>,
            autosaveInterval: 30,
            savedAnswers: <?= json_encode($savedAnswers, JSON_UNESCAPED_UNICODE) ?>,
            submitUrl : '<?= APP_URL ?>/api/exam.php',
            resultUrl : '<?= APP_URL ?>/exam-result.php'
        };
    </script>
</head>
<body>

<div class="outer-wrapper">
    <div class="header-brand">
        <div class="brand-logo" style="display:flex;align-items:center;gap:10px;">
            <img src="<?= APP_URL ?>/image/language-hub-logo.png" alt="Logo" style="height:32px; width:auto; object-fit:contain; filter:drop-shadow(0 2px 4px rgba(0,0,0,0.1));">
            <span style="background:linear-gradient(135deg, #1e293b, #5B21B6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;">B academy.</span>
        </div>
        <div class="user-profile">
            <div class="user-avatar" style="background: url('<?= APP_URL ?>/assets/img/avatar.png') center/cover"></div>
            <div class="user-info">
                <span class="user-name"><?= e($_SESSION['user_name'] ?? 'Student') ?></span>
                <span class="user-id">ID: <?= $userId ?></span>
            </div>
        </div>
    </div>

    <div class="inner-card">
        <div class="card-header">
            <div class="timer-box">
                <span class="material-icons-round timer-icon">schedule</span>
                <div class="timer-text-col">
                    <span class="timer-label">Time remaining</span>
                    <span class="timer-time" id="timerDisplay">--:--:--</span>
                </div>
            </div>
            <button onclick="ExamEngine.confirmSubmit()" class="btn-submit">Submit</button>
        </div>

        <div class="content-grid">
            <!-- Questions container -->
            <div id="questionsContainer">
                <?php foreach ($questions as $qi => $q): ?>
                <div class="question-card <?= $qi === 0 ? '' : 'hidden' ?>" id="q<?= (int)$q['id'] ?>">
                    <div class="q-meta">Question <?= $qi + 1 ?> of <?= $totalQ ?></div>
                    <div class="q-text">
                        <?= nl2br(e($q['body'] ?? '')) ?>
                        <?php if (!empty($q['question_image'])): ?>
                            <img src="<?= e($q['question_image']) ?>" alt="" style="display:block;max-width:100%;margin-top:20px;border-radius:12px">
                        <?php endif; ?>
                    </div>
                    
                    <div class="options-grid">
                        <?php
                        $opts = ['a'=>($q['option_a']??''), 'b'=>($q['option_b']??''), 'c'=>($q['option_c']??''), 'd'=>($q['option_d']??'')];
                        $valMap = ['a'=>1, 'b'=>2, 'c'=>3, 'd'=>4];
                        foreach ($opts as $let => $optText):
                            if (!$optText) continue;
                            $optIdx = $valMap[$let];
                            $savedVal = $savedAnswers[(string)$q['id']] ?? null;
                            $isSelected = $savedVal == $optIdx;
                        ?>
                        <label class="option-label <?= $isSelected?'selected':'' ?>" onclick="ExamEngine.selectOption(<?= (int)$q['id'] ?>, <?= $optIdx ?>, this)">
                            <input type="radio" name="q_<?= (int)$q['id'] ?>" value="<?= $optIdx ?>" style="display:none" <?= $isSelected?'checked':'' ?>>
                            <span class="opt-letter"><?= strtoupper($let) ?>.</span>
                            <span class="opt-text"><?= nl2br(e($optText)) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Progress Ring -->
            <div class="progress-circle-wrap">
                <svg>
                    <circle cx="50%" cy="50%" r="75" class="progress-bg"></circle>
                    <circle cx="50%" cy="50%" r="75" class="progress-bar" id="progressRing"></circle>
                </svg>
                <div class="progress-text">
                    <span id="answeredCount">0</span>/<?= $totalQ ?>
                </div>
            </div>
        </div>

        <div class="pagination-row">
            <button class="page-btn page-btn-wide" id="btnPrev" onclick="ExamEngine.goTo(ExamEngine.current - 1)">Prev</button>
            <div class="page-numbers-scroll">
                <?php foreach ($questions as $qi => $q): 
                    $isAns = isset($savedAnswers[(string)$q['id']]);
                ?>
                <button onclick="ExamEngine.goTo(<?= $qi ?>)" 
                        id="pageBtn_<?= (int)$q['id'] ?>"
                        class="page-btn <?= $isAns?'answered':'' ?> <?= $qi===0?'active':'' ?>">
                    <?= $qi + 1 ?>
                </button>
                <?php endforeach; ?>
            </div>
            <button class="page-btn page-btn-wide" id="btnNext" onclick="ExamEngine.goTo(ExamEngine.current + 1)">Next</button>
        </div>
    </div>
</div>

<!-- Submit Confirm Modal (reusing styles from earlier) -->
<div id="submitModal" style="position:fixed;inset:0;background:rgba(30,41,59,0.8);backdrop-filter:blur(8px);z-index:1000;display:none;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:24px;padding:40px;max-width:440px;width:90%;text-align:center;box-shadow:0 24px 64px rgba(0,0,0,0.2)">
        <h2 style="margin:0 0 16px;font-size:24px;font-weight:800;color:var(--verif-btn-dark)">Submit Exam?</h2>
        <p style="color:#64748b;margin:0 0 32px;font-size:16px;line-height:1.6" id="submitSummary">
            You have answered <strong style="color:#1e293b" id="modalAnswered">0</strong> question(s).<br>
            <strong style="color:#ef4444" id="modalUnanswered">0</strong> question(s) remain unanswered.
        </p>
        <div style="display:flex;gap:16px;justify-content:center">
            <button onclick="document.getElementById('submitModal').style.display='none'" class="page-btn page-btn-wide" style="flex:1">Review</button>
            <button onclick="ExamEngine.doSubmit()" class="btn-submit" style="flex:1">Submit</button>
        </div>
    </div>
</div>

<script>
const EXAM_QUESTIONS = <?= json_encode(array_map(fn($q) => ['id'=>(int)$q['id']], $questions), JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= APP_URL ?>/assets/js/exam.js?v=<?= APP_VERSION ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    ExamEngine.init();

    // Override formatting timer to HH:MM:SS if needed
    ExamEngine.updateTimerDisplay = function() {
        if(EXAM_CONFIG.remaining > 31000000) {
            document.getElementById('timerDisplay').innerText = 'Unlimited';
            return;
        }
        if(EXAM_CONFIG.remaining <= 0) {
            document.getElementById('timerDisplay').innerText = '00:00:00';
            return;
        }
        const h = Math.floor(EXAM_CONFIG.remaining / 3600);
        const m = Math.floor((EXAM_CONFIG.remaining % 3600) / 60);
        const s = EXAM_CONFIG.remaining % 60;
        document.getElementById('timerDisplay').innerText = 
            String(h).padStart(2,'0') + ':' + 
            String(m).padStart(2,'0') + ':' + 
            String(s).padStart(2,'0');
    };

    // Override UI navigation logic
    const oldGoTo = ExamEngine.goTo;
    ExamEngine.goTo = function(idx) {
        if (idx < 0 || idx >= EXAM_QUESTIONS.length) return;
        
        if (oldGoTo) oldGoTo.call(ExamEngine, idx);
        
        // Hide all
        document.querySelectorAll('.question-card').forEach(el => {
            el.classList.add('hidden');
            el.style.display = '';
        });
        document.querySelectorAll('.page-btn').forEach(el => el.classList.remove('active'));
        
        // Show current
        ExamEngine.current = idx;
        const qid = EXAM_QUESTIONS[idx].id;
        const activeCard = document.getElementById('q' + qid);
        if (activeCard) {
            activeCard.classList.remove('hidden');
            activeCard.style.display = ''; // Clear inline display so it relies on class removal
        }
        
        const pb = document.getElementById('pageBtn_' + qid);
        if(pb) pb.classList.add('active');
        
        // Scroll active button into view if needed
        if(pb) pb.scrollIntoView({behavior: "smooth", inline: "center", block: "nearest"});
    };

    // Override option selection UI
    const oldSelect = ExamEngine.selectOption;
    ExamEngine.selectOption = function(qid, optVal, labelEl) {
        if(oldSelect) oldSelect.call(ExamEngine, qid, optVal);
        
        if(labelEl) {
            const container = labelEl.closest('.options-grid');
            container.querySelectorAll('.option-label').forEach(lbl => lbl.classList.remove('selected'));
            labelEl.classList.add('selected');
        }
        
        const pb = document.getElementById('pageBtn_' + qid);
        if(pb) pb.classList.add('answered');
        
        updateProgress();
    };

    function updateProgress() {
        const total = EXAM_QUESTIONS.length;
        let ans = 0;
        EXAM_QUESTIONS.forEach(q => {
            if (document.getElementById('pageBtn_' + q.id)?.classList.contains('answered')) ans++;
        });
        document.getElementById('answeredCount').innerText = ans;
        
        // Update SVG circle
        const ring = document.getElementById('progressRing');
        const circumference = window.innerWidth > 900 ? (2 * Math.PI * 75) : (2 * Math.PI * 55); 
        // Note: For responsive SVG, we use 180x180 svg vs 140x140 svg.
        // Actually, CSS transforms the size, so viewBox handles scaling, but I didn't set viewBox.
        // Let's fix SVG sizing: The circle r=75. Circumference = 471.
        const offset = 471 - (ans / total) * 471;
        ring.style.strokeDashoffset = offset;
    }
    
    updateProgress();
    ExamEngine.updateTimerDisplay();
});
</script>
</body>
</html>