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

// Which course filter
$courseFilter = sanitize_int($_GET['course'] ?? 0);
$tabFilter    = sanitize_string($_GET['tab'] ?? 'all', 20); // all|upcoming|active|past
$examId       = sanitize_int($_GET['exam'] ?? 0);

// Start or submit exam
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $act = sanitize_string($_POST['action'] ?? '', 20);

    if ($act === 'start') {
        $eid = sanitize_int($_POST['exam_id'] ?? 0);
        $exam = db_row("SELECT * FROM exams WHERE id=? AND status='published'", [$eid]);
        if (!$exam) { flash('error','Exam not found.'); redirect(APP_URL.'/exams.php'); }

        $isFree = (int)($exam['is_free'] ?? 0);
        // Check access: enrolled or free exam
        if (!$isFree && !db_val("SELECT 1 FROM enrollments WHERE user_id=? AND course_id=? AND status='active'", [$uid, $exam['course_id']])) {
            flash('error','You are not enrolled in this course.'); redirect(APP_URL.'/exams.php');
        }
        // No pending attempt
        $pending = db_val("SELECT id FROM exam_attempts WHERE user_id=? AND exam_id=? AND status='in_progress'", [$uid, $eid]);
        if ($pending) {
            $examType = $exam['exam_type'] ?? 'mcq';
            redirect($examType === 'cq' ? APP_URL.'/exams.php?exam='.$eid.'&mode=cq&attempt='.(int)$pending : APP_URL.'/exam-take.php?attempt='.(int)$pending);
        }
        // Attempt limit
        $attempts = (int)db_val("SELECT COUNT(*) FROM exam_attempts WHERE user_id=? AND exam_id=? AND status IN ('submitted','graded','completed')", [$uid, $eid]);
        if ($exam['max_attempts'] > 0 && $attempts >= $exam['max_attempts']) {
            flash('error','You have used up all your attempts.'); redirect(APP_URL.'/exams.php?exam='.$eid);
        }
        $aid = db_insert("INSERT INTO exam_attempts (user_id, exam_id, started_at, status) VALUES (?,?,NOW(),'in_progress')", [$uid, $eid]);
        $examType = $exam['exam_type'] ?? 'mcq';
        redirect($examType === 'cq' ? APP_URL.'/exams.php?exam='.$eid.'&mode=cq&attempt='.(int)$aid : APP_URL.'/exam-take.php?attempt='.(int)$aid);

    } elseif ($act === 'submit_cq') {
        $eid      = sanitize_int($_POST['exam_id'] ?? 0);
        $aid      = sanitize_int($_POST['attempt_id'] ?? 0);
        $driveUrl = sanitize_string($_POST['drive_url'] ?? '', 500);
        if (!filter_var($driveUrl, FILTER_VALIDATE_URL)) {
            flash('error','Please enter a valid Google Drive link.'); redirect(APP_URL.'/exams.php?exam='.$eid.'&mode=cq&attempt='.$aid);
        }
        $attempt = db_row("SELECT * FROM exam_attempts WHERE id=? AND user_id=? AND exam_id=? AND status='in_progress'", [$aid, $uid, $eid]);
        if (!$attempt) { flash('error','Attempt not found.'); redirect(APP_URL.'/exams.php'); }
        db_exec("UPDATE exam_attempts SET status='submitted',drive_url=?,submitted_at=NOW(),score=0 WHERE id=?", [$driveUrl, $aid]);
        flash('success','Your answer has been submitted. Your teacher will review it soon.');
        redirect(APP_URL.'/exams.php?exam='.$eid);
    }
}

// Get enrolled courses for filter
$enrolledCourses = db_query("
    SELECT c.id, c.title_bn, c.slug
    FROM enrollments e JOIN courses c ON c.id=e.course_id
    WHERE e.user_id=? AND e.status='active'
    ORDER BY c.title_bn
", [$uid]);

// Get exams: enrolled courses + free exams open to all
$courseIds = array_column($enrolledCourses, 'id');
$inList = $courseIds ? implode(',', array_map('intval', $courseIds)) : '0';
$courseWhere = $courseFilter
    ? "AND ex.course_id = $courseFilter"
    : "AND (ex.is_free=1 OR ex.course_id IN ($inList))";

$now = date('Y-m-d H:i:s');
$tabWhere = match($tabFilter) {
    'upcoming' => "AND ex.starts_at > '$now'",
    'active'   => "AND ex.starts_at <= '$now' AND (ex.ends_at IS NULL OR ex.ends_at >= '$now')",
    'past'     => "AND ex.ends_at IS NOT NULL AND ex.ends_at < '$now'",
    default    => '',
};

$allExams = db_query("
    SELECT ex.*, c.title_bn as course_title, c.id as course_id,
           (SELECT COUNT(*) FROM exam_questions WHERE exam_id=ex.id) as q_count,
           (SELECT COUNT(*) FROM exam_attempts ea WHERE ea.exam_id=ex.id AND ea.status='submitted') as attempt_count,
           (SELECT COUNT(*) FROM exam_attempts ea WHERE ea.exam_id=ex.id AND ea.user_id=? AND ea.status='submitted') as my_attempts,
           (SELECT el.score FROM exam_leaderboard el WHERE el.exam_id=ex.id AND el.user_id=?) as my_best_score,
           (SELECT ea.id FROM exam_attempts ea WHERE ea.exam_id=ex.id AND ea.user_id=? AND ea.status='in_progress' LIMIT 1) as pending_attempt_id,
           (SELECT el.rank FROM exam_leaderboard el WHERE el.exam_id=ex.id AND el.user_id=?) as my_rank
    FROM exams ex
    JOIN courses c ON c.id=ex.course_id
    WHERE ex.status='published' $courseWhere $tabWhere
    ORDER BY ex.starts_at DESC
", [$uid, $uid, $uid, $uid]);

// CQ exam view
$cqMode      = sanitize_string($_GET['mode'] ?? '', 10) === 'cq';
$cqAttemptId = sanitize_int($_GET['attempt'] ?? 0);
$cqExam      = null;
$cqQuestions = [];
if ($cqMode && $examId && $cqAttemptId) {
    $cqExam = db_row("SELECT ex.*, c.title_bn as course_title FROM exams ex JOIN courses c ON c.id=ex.course_id WHERE ex.id=? AND ex.exam_type='cq' AND ex.status='published'", [$examId]);
    if ($cqExam) {
        $cqQuestions = db_query("SELECT * FROM exam_questions WHERE exam_id=? ORDER BY sort_order ASC", [$examId]);
        // Verify attempt belongs to user
        $cqAttempt = db_row("SELECT * FROM exam_attempts WHERE id=? AND user_id=? AND exam_id=? AND status='in_progress'", [$cqAttemptId, $uid, $examId]);
        if (!$cqAttempt) { $cqExam = null; flash('error','Attempt not found.'); }
    }
}

// Single exam detail + leaderboard
$examDetail   = null;
$leaderboard  = [];
$myAttempts   = [];
if ($examId && !$cqMode) {
    $examDetail = db_row("SELECT ex.*, c.title_bn as course_title FROM exams ex JOIN courses c ON c.id=ex.course_id WHERE ex.id=?", [$examId]);
    if ($examDetail) {
        $leaderboard = db_query("
            SELECT u.name, u.avatar, el.score, el.rank, el.updated_at as submitted_at,
                   CASE WHEN u.id=? THEN 1 ELSE 0 END as is_me
            FROM exam_leaderboard el
            JOIN users u ON u.id=el.user_id
            WHERE el.exam_id=?
            ORDER BY el.score DESC, el.updated_at ASC
            LIMIT 20
        ", [$uid, $examId]);

        $myAttempts = db_query("
            SELECT * FROM exam_attempts WHERE user_id=? AND exam_id=? ORDER BY started_at DESC
        ", [$uid, $examId]);
    }
}

// Stats
$totalExams     = count($allExams);
$activeExams    = count(array_filter($allExams, function($e) {
    $now = time();
    $s = $e['starts_at'] ? strtotime($e['starts_at']) : 0;
    $en = $e['ends_at'] ? strtotime($e['ends_at']) : PHP_INT_MAX;
    return $s <= $now && $now <= $en;
}));
$myTotalAttempts = (int)db_val("SELECT COUNT(*) FROM exam_attempts WHERE user_id=? AND status='submitted'", [$uid]);
$myAvgScore      = (float)db_val("SELECT COALESCE(AVG(score),0) FROM exam_leaderboard WHERE user_id=?", [$uid]);

$page_title = 'Exam Center';
$extra_css  = '<style>
.exam-center-hero{background:linear-gradient(135deg,#0A0E27 0%,#162040 50%,#1B0842 100%);padding:var(--sp-12) 0 var(--sp-10);position:relative;overflow:hidden}
.exam-center-hero::before{content:"";position:absolute;top:-20%;left:-5%;width:500px;height:500px;background:radial-gradient(circle,rgba(91, 33, 182,.35) 0%,transparent 70%);pointer-events:none}
.exam-center-hero::after{content:"";position:absolute;bottom:-20%;right:-5%;width:400px;height:400px;background:radial-gradient(circle,rgba(244,114,182,.25) 0%,transparent 70%);pointer-events:none}
.exam-card{background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--r-xl);overflow:hidden;transition:transform .2s,box-shadow .2s;display:flex;flex-direction:column}
.exam-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg)}
.exam-card-header{padding:var(--sp-5) var(--sp-5) var(--sp-4);border-bottom:1px solid var(--c-border)}
.exam-card-body{padding:var(--sp-5);flex:1;display:flex;flex-direction:column;gap:var(--sp-3)}
.exam-status-live{display:inline-flex;align-items:center;gap:6px;background:#FF2D2D;color:#fff;padding:3px 10px;border-radius:999px;font-size:var(--text-xs);font-weight:700}
.exam-status-live::before{content:"";width:6px;height:6px;border-radius:50%;background:#fff;animation:pulse 1.2s infinite}
.exam-status-upcoming{display:inline-flex;align-items:center;gap:6px;background:var(--c-primary-light);color:var(--c-primary);padding:3px 10px;border-radius:999px;font-size:var(--text-xs);font-weight:700}
.exam-status-past{display:inline-flex;align-items:center;gap:6px;background:var(--c-surface-2);color:var(--c-ink-muted);padding:3px 10px;border-radius:999px;font-size:var(--text-xs);font-weight:700}
.exam-meta-row{display:flex;align-items:center;gap:6px;font-size:var(--text-xs);color:var(--c-ink-muted)}
.exam-meta-row .material-icons-round{font-size:14px}
.countdown-box{background:linear-gradient(135deg,var(--c-primary),#7C3AED);border-radius:var(--r-lg);padding:var(--sp-3) var(--sp-4);color:#fff;text-align:center;font-size:var(--text-xs)}
.lb-row{display:grid;grid-template-columns:40px 1fr auto;align-items:center;gap:var(--sp-3);padding:var(--sp-3) var(--sp-4);border-radius:var(--r-lg);margin-bottom:4px;transition:background .15s}
.lb-row:hover{background:var(--c-surface)}
.lb-row.is-me{background:var(--c-primary-light);border:1.5px solid var(--c-primary-light-border)}
.lb-rank-1{color:#F59E0B;font-weight:800;font-size:var(--text-lg)}
.lb-rank-2{color:#9CA3AF;font-weight:800;font-size:var(--text-lg)}
.lb-rank-3{color:#CD7C2F;font-weight:800;font-size:var(--text-lg)}
.tab-filter{display:flex;gap:0;border-bottom:2px solid var(--c-border);margin-bottom:var(--sp-6)}
.tab-filter a{padding:var(--sp-2) var(--sp-4);font-size:var(--text-sm);font-weight:700;color:var(--c-ink-muted);border-bottom:3px solid transparent;margin-bottom:-2px;text-decoration:none;transition:all .2s}
.tab-filter a.active,.tab-filter a:hover{color:var(--c-primary);border-bottom-color:var(--c-primary)}
@media(max-width:768px){.exam-grid{grid-template-columns:1fr !important}}
@media(max-width:900px){.exam-detail-grid{grid-template-columns:1fr !important}}
@media(max-width:480px){.exam-info-grid{grid-template-columns:1fr !important}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
</style>';
include __DIR__ . '/includes/header.php';
?>

<!-- Hero -->
<div class="exam-center-hero">
    <div class="container" style="position:relative;z-index:1">
        <div style="display:flex;align-items:flex-start;gap:var(--sp-6);flex-wrap:wrap">
            <div style="flex:1;min-width:280px">
                <div style="display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:999px;padding:6px 16px;font-size:var(--text-xs);color:rgba(255,255,255,.7);font-weight:700;margin-bottom:var(--sp-4)">
                    <span class="material-icons-round" style="font-size:14px">quiz</span>
                    Exam Center
                </div>
                <h1 style="color:#fff;font-size:clamp(1.75rem,4vw,2.5rem);margin-bottom:var(--sp-3);line-height:1.2">
                    Take your exams,<br>
                    <span style="background:linear-gradient(90deg,#A78BFA,#F472B6);-webkit-background-clip:text;-webkit-text-fill-color:transparent">prove your skills</span>
                </h1>
                <p style="color:rgba(255,255,255,.65);font-size:var(--text-base);max-width:500px">Find exams for your enrolled courses here. Take each exam and climb the leaderboard.</p>
            </div>
            <!-- Stats row -->
            <div style="display:flex;gap:var(--sp-3);flex-wrap:wrap">
                <?php foreach ([
                    ['quiz', $totalExams, 'Exams Available'],
                    ['bolt', $activeExams, 'Live Now'],
                    ['history', $myTotalAttempts, 'My Attempts'],
                    ['trending_up', number_format($myAvgScore,1).'%', 'Average Score'],
                ] as [$icon, $val, $lab]): ?>
                <div style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:var(--r-xl);padding:var(--sp-4) var(--sp-5);text-align:center;min-width:90px">
                    <span class="material-icons-round" style="font-size:20px;color:rgba(255,255,255,.5);display:block;margin-bottom:4px"><?= $icon ?></span>
                    <div style="font-family:var(--font-latin);font-size:var(--text-xl);font-weight:800;color:#fff"><?= $val ?></div>
                    <div style="font-size:var(--text-xs);color:rgba(255,255,255,.5);white-space:nowrap"><?= $lab ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($cqMode && $cqExam): ?>
<!-- CQ EXAM SUBMISSION VIEW -->
<div class="container" style="padding:var(--sp-10) 0 var(--sp-16);max-width:760px">
    <div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:var(--sp-6)">
        <a href="<?= APP_URL ?>/exams.php?exam=<?= $examId ?>" class="btn btn-ghost btn-sm"><span class="material-icons-round">arrow_back</span></a>
        <div>
            <h1 style="font-size:var(--text-2xl)"><?= e($cqExam['title']) ?></h1>
            <p style="color:var(--c-ink-muted);margin:0"><?= e($cqExam['course_title']) ?></p>
        </div>
    </div>

    <div class="card" style="padding:var(--sp-6);margin-bottom:var(--sp-5);background:linear-gradient(135deg,rgba(91, 33, 182,.08),rgba(244,114,182,.05));border-color:var(--c-primary-light)">
        <div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:var(--sp-3)">
            <span class="material-icons-round" style="color:var(--c-primary)">info</span>
            <h4>Creative Exam — Instructions</h4>
        </div>
        <ol style="color:var(--c-ink-light);font-size:var(--text-sm);line-height:2;padding-left:20px;margin:0">
            <li>Read the questions below and write your answers</li>
            <li>Take photos of your answer sheet and upload them to Google Drive</li>
            <li>Share the Drive folder as <strong>Anyone with the link</strong></li>
            <li>Paste the share link below and submit</li>
        </ol>
    </div>

    <!-- Questions -->
    <?php foreach ($cqQuestions as $qi => $q): ?>
    <div class="card" style="padding:var(--sp-5);margin-bottom:var(--sp-4)">
        <div style="display:flex;gap:var(--sp-3);align-items:flex-start">
            <span style="background:var(--c-primary);color:#fff;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;font-family:var(--font-latin);font-weight:800;font-size:var(--text-sm);flex-shrink:0"><?= $qi+1 ?></span>
            <div>
                <div style="font-weight:700;font-size:var(--text-base);line-height:1.7"><?= nl2br(e($q['question'])) ?></div>
                <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:var(--sp-2)">Marks: <?= (float)$q['marks'] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Submit form -->
    <div class="card" style="padding:var(--sp-6)">
        <h3 style="margin-bottom:var(--sp-4)">Submit Answer</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="submit_cq">
            <input type="hidden" name="exam_id" value="<?= $examId ?>">
            <input type="hidden" name="attempt_id" value="<?= $cqAttemptId ?>">
            <div class="form-group">
                <label class="form-label">Google Drive Folder Link *</label>
                <input type="url" name="drive_url" class="form-control" required
                       placeholder="https://drive.google.com/drive/folders/..."
                       style="font-family:var(--font-latin)">
                <small style="color:var(--c-ink-muted)">Set the folder to "Anyone with the link can view", then paste the link.</small>
            </div>
            <button type="submit" class="btn btn-primary btn-lg" onclick="return confirm('Submit your answer? Once submitted, it cannot be changed.')">
                <span class="material-icons-round">send</span> Submit Answer
            </button>
        </form>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; exit; endif; ?>

<!-- Main content -->
<div class="container" style="padding-top:var(--sp-10);padding-bottom:var(--sp-16)">

<?php if ($examDetail): ?>
<!-- ===== Single Exam Detail ===== -->
<div style="margin-bottom:var(--sp-6)">
    <a href="<?= APP_URL ?>/exams.php" style="color:var(--c-ink-muted);font-size:var(--text-sm);text-decoration:none;display:inline-flex;align-items:center;gap:4px">
        <span class="material-icons-round" style="font-size:16px">arrow_back</span> All Exams
    </a>
</div>
<div style="display:grid;grid-template-columns:1fr 360px;gap:var(--sp-8)" class="exam-detail-grid">
    <div>
        <div class="card" style="padding:var(--sp-7);margin-bottom:var(--sp-5)">
            <?php
            $now = time();
            $starts = strtotime($examDetail['starts_at']);
            $ends   = $examDetail['ends_at'] ? strtotime($examDetail['ends_at']) : null;
            $isLive = $starts <= $now && (!$ends || $now <= $ends);
            $isPast = $ends && $now > $ends;
            $isUpcoming = $starts > $now;
            ?>
            <div style="margin-bottom:var(--sp-4)">
                <?php if ($isLive): ?>
                <span class="exam-status-live">LIVE Now</span>
                <?php elseif ($isUpcoming): ?>
                <span class="exam-status-upcoming">Coming Soon</span>
                <?php else: ?>
                <span class="exam-status-past">Ended</span>
                <?php endif; ?>
            </div>
            <h2 style="margin-bottom:var(--sp-3)"><?= e($examDetail['title']) ?></h2>
            <div style="color:var(--c-ink-muted);font-size:var(--text-sm);margin-bottom:var(--sp-5)"><?= e($examDetail['course_title']) ?></div>

            <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:var(--sp-3)" class="exam-info-grid">
                <?php $infoRows = [
                    ['schedule','Starts', date('d M Y, h:i A', $starts)],
                    ['schedule','Ends', $ends ? date('d M Y, h:i A', $ends) : 'Open'],
                    ['timer','Duration', ($examDetail['duration'] ?? 0).' min'],
                    ['quiz','Questions', ($examDetail['total_questions'] ?? 0)],
                    ['star','Total Marks', ($examDetail['total_marks'] ?? 0)],
                    ['check_circle','Pass Marks', ($examDetail['pass_marks'] ?? 0)],
                ]; foreach ($infoRows as [$icon, $lab, $val]): ?>
                <div style="background:var(--c-surface-2);border-radius:var(--r-md);padding:var(--sp-3) var(--sp-4);display:flex;align-items:center;gap:var(--sp-3)">
                    <span class="material-icons-round" style="font-size:18px;color:var(--c-primary);flex-shrink:0"><?= $icon ?></span>
                    <div>
                        <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= $lab ?></div>
                        <div style="font-weight:700;font-size:var(--text-sm)"><?= $val ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($examDetail['description'])): ?>
            <div style="margin-top:var(--sp-5);padding-top:var(--sp-5);border-top:1px solid var(--c-border)">
                <h4 style="margin-bottom:var(--sp-3)">Exam Description</h4>
                <p style="color:var(--c-ink-light);line-height:1.8"><?= nl2br(e($examDetail['description'])) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($isLive): ?>
            <form method="POST" style="margin-top:var(--sp-6)">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="start">
                <input type="hidden" name="exam_id" value="<?= $examId ?>">
                <button type="submit" class="btn btn-primary" style="font-size:var(--text-base);padding:14px 32px">
                    <span class="material-icons-round">play_arrow</span>
                    Start Exam
                </button>
            </form>
            <?php elseif ($isUpcoming): ?>
            <div class="countdown-box" style="margin-top:var(--sp-6);display:inline-block" id="countdownBox">
                <div style="font-size:var(--text-xs);margin-bottom:4px;opacity:.8">Exam starts in</div>
                <div id="countdownTimer" style="font-family:var(--font-latin);font-size:var(--text-xl);font-weight:800">--:--:--</div>
            </div>
            <?php else: ?>
            <div style="margin-top:var(--sp-5);padding:var(--sp-4);background:var(--c-surface-2);border-radius:var(--r-lg);color:var(--c-ink-muted);font-size:var(--text-sm)">
                <span class="material-icons-round" style="font-size:16px;vertical-align:middle">lock</span>
                This exam has ended.
            </div>
            <?php endif; ?>
        </div>

        <!-- My attempts -->
        <?php if ($myAttempts): ?>
        <div class="card" style="padding:var(--sp-6)">
            <h4 style="margin-bottom:var(--sp-4)">My Attempts</h4>
            <?php foreach ($myAttempts as $i => $at):
                $pct = $examDetail['total_marks'] > 0 ? round($at['score']/$examDetail['total_marks']*100) : 0;
                $color = $pct >= 80 ? 'var(--c-success)' : ($pct >= 50 ? 'var(--c-warning)' : 'var(--c-error)');
                $bg = $pct >= 80 ? 'var(--c-success-light)' : ($pct >= 50 ? 'var(--c-warning-light)' : 'var(--c-error-light)');
            ?>
            <div style="display:flex;align-items:center;gap:var(--sp-4);padding:var(--sp-3);border-bottom:1px solid var(--c-border)">
                <div style="width:44px;height:44px;border-radius:50%;background:<?= $bg ?>;border:3px solid <?= $color ?>;display:flex;align-items:center;justify-content:center;font-family:var(--font-latin);font-weight:800;font-size:var(--text-xs);color:<?= $color ?>;flex-shrink:0"><?= $pct ?>%</div>
                <div style="flex:1">
                    <div style="font-weight:700;font-size:var(--text-sm)">Attempt #<?= $i+1 ?></div>
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= date('d M Y, h:i A', strtotime($at['started_at'])) ?></div>
                </div>
                <div style="text-align:right">
                    <div style="font-family:var(--font-latin);font-weight:800;color:<?= $color ?>"><?= number_format((float)$at['score'],0) ?>/<?= (int)($examDetail['total_marks'] ?? 0) ?></div>
                    <?php if ($at['rank']): ?><div style="font-size:var(--text-xs);color:var(--c-ink-muted)">Rank #<?= (int)$at['rank'] ?></div><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Leaderboard -->
    <div>
        <div class="card" style="padding:var(--sp-6)">
            <div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:var(--sp-5)">
                <span class="material-icons-round" style="color:#F59E0B;font-size:24px">emoji_events</span>
                <h3>Leaderboard</h3>
                <span style="margin-left:auto;font-size:var(--text-xs);color:var(--c-ink-muted)"><?= count($leaderboard) ?> people</span>
            </div>

            <?php if (!($examDetail['show_leaderboard'] ?? 0)): ?>
            <div style="text-align:center;padding:var(--sp-8);color:var(--c-ink-muted)">
                <span class="material-icons-round" style="font-size:44px;display:block;margin-bottom:10px;color:var(--c-ink-faint)">lock</span>
                <div style="font-weight:700;margin-bottom:4px">Leaderboard Locked</div>
                <div style="font-size:var(--text-sm)">It will show here once your teacher publishes it.</div>
            </div>
            <?php elseif (empty($leaderboard)): ?>
            <div style="text-align:center;padding:var(--sp-10);color:var(--c-ink-muted)">
                <span class="material-icons-round" style="font-size:40px;display:block;margin-bottom:8px">leaderboard</span>
                No one has taken this exam yet.
            </div>
            <?php else: ?>
            <!-- Top 3 podium -->
            <?php if (count($leaderboard) >= 3): ?>
            <div style="display:flex;align-items:flex-end;justify-content:center;gap:var(--sp-3);margin-bottom:var(--sp-5);padding:var(--sp-4) 0">
                <?php $podiumOrder = [1=>$leaderboard[1], 0=>$leaderboard[0], 2=>$leaderboard[2]];
                      $heights = [1=>56, 0=>72, 2=>44];
                      $medals  = [1=>'🥈', 0=>'🥇', 2=>'🥉'];
                      foreach ($podiumOrder as $pos => $pl):
                ?>
                <div style="flex:1;text-align:center">
                    <img src="<?= e(avatar_url($pl['avatar'],$pl['name'])) ?>"
                         style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:3px solid <?= $pos===0?'#F59E0B':($pos===1?'#9CA3AF':'#CD7C2F') ?>;margin:0 auto 6px">
                    <div style="font-size:var(--text-xs);font-weight:700;color:var(--c-ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e(mb_substr($pl['name'],0,12,'UTF-8')) ?></div>
                    <div style="font-family:var(--font-latin);font-weight:800;font-size:var(--text-sm);color:var(--c-ink)"><?= number_format((float)$pl['score'],0) ?></div>
                    <div style="background:<?= $pos===0?'linear-gradient(#F59E0B,#D97706)':($pos===1?'linear-gradient(#9CA3AF,#6B7280)':'linear-gradient(#CD7C2F,#A65D1B)') ?>;height:<?= $heights[$pos] ?>px;border-radius:var(--r-sm) var(--r-sm) 0 0;margin-top:8px;display:flex;align-items:center;justify-content:center;font-size:20px">
                        <?= $medals[$pos] ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Full list -->
            <?php foreach ($leaderboard as $ri => $lb): ?>
            <div class="lb-row <?= $lb['is_me'] ? 'is-me' : '' ?>">
                <div style="font-family:var(--font-latin);font-weight:800;text-align:center;<?= $ri===0?'color:#F59E0B':($ri===1?'color:#9CA3AF':($ri===2?'color:#CD7C2F':'color:var(--c-ink-muted)')) ?>">
                    <?= $ri < 3 ? ['🥇','🥈','🥉'][$ri] : '#'.($ri+1) ?>
                </div>
                <div style="display:flex;align-items:center;gap:var(--sp-2);min-width:0">
                    <img src="<?= e(avatar_url($lb['avatar'],$lb['name'])) ?>"
                         style="width:28px;height:28px;border-radius:50%;object-fit:cover;flex-shrink:0">
                    <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:var(--text-sm);font-weight:<?= $lb['is_me']?'700':'600' ?>;color:<?= $lb['is_me']?'var(--c-primary)':'var(--c-ink)' ?>">
                        <?= e($lb['name']) ?><?= $lb['is_me'] ? ' (You) 🙋' : '' ?>
                    </div>
                </div>
                <div style="font-family:var(--font-latin);font-weight:800;color:var(--c-ink);flex-shrink:0"><?= number_format((float)$lb['score'],0) ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ===== Exam List ===== -->

<!-- Filters -->
<div style="display:flex;align-items:center;gap:var(--sp-4);flex-wrap:wrap;margin-bottom:var(--sp-6)">
    <div class="tab-filter" style="margin-bottom:0;border-bottom:none;gap:var(--sp-1)">
        <?php foreach (['all'=>'All', 'active'=>'Live Now', 'upcoming'=>'Upcoming', 'past'=>'Ended'] as $k=>$l): ?>
        <a href="?tab=<?= $k ?><?= $courseFilter?"&course=$courseFilter":'' ?>" class="<?= $tabFilter===$k?'active':'' ?>" style="border-radius:var(--r-md);border-bottom:none !important;background:<?= $tabFilter===$k?'var(--c-primary)':'var(--c-surface-2)' ?>;color:<?= $tabFilter===$k?'#fff':'var(--c-ink-muted)' ?>;padding:6px 16px"><?= $l ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($enrolledCourses): ?>
    <form method="GET" style="margin-left:auto">
        <input type="hidden" name="tab" value="<?= e($tabFilter) ?>">
        <select name="course" class="form-control" style="min-width:200px" onchange="this.form.submit()">
            <option value="0">All Courses</option>
            <?php foreach ($enrolledCourses as $ec): ?>
            <option value="<?= (int)$ec['id'] ?>" <?= $courseFilter==(int)$ec['id']?'selected':'' ?>><?= e(mb_substr($ec['title_bn'],0,40,'UTF-8')) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>
</div>

<?php if (empty($courseIds)): ?>
<div style="text-align:center;padding:var(--sp-20);background:var(--c-surface);border-radius:var(--r-2xl);border:2px dashed var(--c-border)">
    <span class="material-icons-round" style="font-size:64px;color:var(--c-ink-faint);display:block;margin-bottom:16px">quiz</span>
    <h3 style="margin-bottom:8px">Not Enrolled in Any Course</h3>
    <p style="color:var(--c-ink-muted)">Enroll in a course first to take exams.</p>
    <a href="<?= APP_URL ?>/courses.php" class="btn btn-primary" style="margin-top:16px">View Courses</a>
</div>
<?php elseif (empty($allExams)): ?>
<div style="text-align:center;padding:var(--sp-16);background:var(--c-surface);border-radius:var(--r-xl);border:2px dashed var(--c-border)">
    <span class="material-icons-round" style="font-size:56px;color:var(--c-ink-faint);display:block;margin-bottom:12px">quiz</span>
    <h3 style="color:var(--c-ink-light)">No Exams in This Category</h3>
    <a href="?tab=all" class="btn btn-ghost" style="margin-top:12px">View All Exams</a>
</div>
<?php else: ?>
<div class="exam-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(320px,100%),1fr));gap:var(--sp-5)">
    <?php foreach ($allExams as $ex):
        $now = time();
        $starts = $ex['starts_at'] ? strtotime($ex['starts_at']) : 0;
        $ends   = $ex['ends_at'] ? strtotime($ex['ends_at']) : null;
        $isLive = $starts <= $now && (!$ends || $now <= $ends);
        $isPast = $ends && $now > $ends;
        $isUpcoming = $starts > $now;
        $hasDone = (int)$ex['my_attempts'] > 0;
        $bestPct = $ex['total_marks'] > 0 && $hasDone ? round($ex['my_best_score']/$ex['total_marks']*100) : 0;
    ?>
    <div class="exam-card">
        <div class="exam-card-header">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--sp-2)">
                <div>
                    <?php if ($isLive): ?><span class="exam-status-live">LIVE</span>
                    <?php elseif ($isUpcoming): ?><span class="exam-status-upcoming">Upcoming</span>
                    <?php else: ?><span class="exam-status-past">Ended</span><?php endif; ?>
                </div>
                <?php if ($hasDone): ?>
                <div style="background:var(--c-success-light);border-radius:999px;padding:2px 10px;font-size:var(--text-xs);font-weight:700;color:var(--c-success)">
                    ✓ Taken <?= (int)$ex['my_attempts'] ?>x
                </div>
                <?php endif; ?>
            </div>
            <h3 style="font-size:var(--text-base);margin-top:var(--sp-2);margin-bottom:var(--sp-1)"><?= e($ex['title']) ?></h3>
            <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= e($ex['course_title']) ?></div>
        </div>
        <div class="exam-card-body">
            <div style="display:flex;gap:var(--sp-4);flex-wrap:wrap">
                <div class="exam-meta-row"><span class="material-icons-round">quiz</span><?= (int)$ex['q_count'] ?> Questions</div>
                <div class="exam-meta-row"><span class="material-icons-round">timer</span><?= (int)($ex['duration']??0) ?> min</div>
                <div class="exam-meta-row"><span class="material-icons-round">people</span><?= (int)$ex['attempt_count'] ?> people</div>
                <?php if ($ex['total_marks']): ?>
                <div class="exam-meta-row"><span class="material-icons-round">star</span><?= (int)$ex['total_marks'] ?> Marks</div>
                <?php endif; ?>
            </div>

            <?php if ($hasDone && $ex['total_marks'] > 0): ?>
            <div>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
                    <span style="font-size:var(--text-xs);color:var(--c-ink-muted)">My Best Score</span>
                    <span style="font-family:var(--font-latin);font-size:var(--text-xs);font-weight:700;color:var(--c-primary)"><?= $bestPct ?>%</span>
                </div>
                <div class="progress-bar"><div class="progress-fill" style="width:<?= $bestPct ?>%"></div></div>
                <?php if ($ex['my_rank']): ?>
                <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:4px">Leaderboard Rank: <strong>#<?= (int)$ex['my_rank'] ?></strong></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($ex['pending_attempt_id']): ?>
            <a href="<?= APP_URL ?>/exam-take.php?attempt=<?= (int)$ex['pending_attempt_id'] ?>" class="btn btn-warning btn-sm btn-block">
                <span class="material-icons-round">timer</span> Exam in Progress — Continue
            </a>
            <?php elseif ($isLive): ?>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="start">
                <input type="hidden" name="exam_id" value="<?= (int)$ex['id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm btn-block">
                    <span class="material-icons-round">play_arrow</span>
                    <?= $hasDone ? 'Retake' : 'Take Exam' ?>
                </button>
            </form>
            <?php elseif ($isUpcoming): ?>
            <a href="<?= APP_URL ?>/exams.php?exam=<?= (int)$ex['id'] ?>" class="btn btn-outline btn-sm btn-block">
                <span class="material-icons-round">info</span> View Details
            </a>
            <?php else: ?>
            <a href="<?= APP_URL ?>/exams.php?exam=<?= (int)$ex['id'] ?>" class="btn btn-ghost btn-sm btn-block">
                <span class="material-icons-round">leaderboard</span> View Leaderboard
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
</div>

<?php if (isset($isUpcoming) && $isUpcoming && isset($starts)): ?>
<script>
const startTs = <?= (int)$starts * 1000 ?>;
function updateCountdown() {
    const diff = startTs - Date.now();
    if (diff <= 0) { location.reload(); return; }
    const h = Math.floor(diff/3600000);
    const m = Math.floor((diff%3600000)/60000);
    const s = Math.floor((diff%60000)/1000);
    document.getElementById('countdownTimer').textContent =
        String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
}
updateCountdown();
setInterval(updateCountdown, 1000);
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
