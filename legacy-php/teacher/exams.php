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
$uid  = (int)$user['id'];
$tp   = db_row("SELECT * FROM teacher_profiles WHERE user_id=?", [$uid]);
if (!$tp) { $tpId = db_insert("INSERT INTO teacher_profiles (user_id,rating_avg,total_students) VALUES (?,0,0)", [$uid]); $tp = db_row("SELECT * FROM teacher_profiles WHERE id=?", [$tpId]); }
$tpId = (int)$tp['id'];

$action = sanitize_string($_GET['action'] ?? 'list', 20);
$editId = sanitize_int($_GET['id'] ?? 0);

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $postAction = sanitize_string($_POST['action'] ?? '', 20);

    if ($postAction === 'save_exam') {
        $id         = sanitize_int($_POST['id'] ?? 0);
        $title      = sanitize_string($_POST['title'] ?? '', 255);
        $courseId   = sanitize_int($_POST['course_id'] ?? 0);
        $classroomId= sanitize_int($_POST['classroom_id'] ?? 0) ?: null;
        $duration   = sanitize_int($_POST['duration'] ?? 30);
        $pass_marks = sanitize_int($_POST['pass_marks'] ?? 0);
        $total_marks= sanitize_int($_POST['total_marks'] ?? 0);
        $neg_mark   = max(0, (float)($_POST['negative_mark'] ?? 0));
        $status     = in_array($_POST['status'] ?? '', ['draft','published','archived']) ? $_POST['status'] : 'draft';
        $start_at   = sanitize_string($_POST['start_at'] ?? '', 30) ?: null;
        $end_at     = sanitize_string($_POST['end_at'] ?? '', 30) ?: null;
        $exam_type  = in_array($_POST['exam_type'] ?? '', ['mcq','cq']) ? $_POST['exam_type'] : 'mcq';
        $is_free    = isset($_POST['is_free']) ? 1 : 0;
        $max_attempts = max(0, sanitize_int($_POST['max_attempts'] ?? 0));

        if (!$title || !$courseId) { flash('error','Title and course are required.'); redirect(APP_URL.'/teacher/exams.php?action='.($id?'edit&id='.$id:'add')); }

        // Verify teacher owns course
        $ownsCourse = teacher_owns_course($courseId, $tpId);
        if (!$ownsCourse) { flash('error','You do not have permission for this course.'); redirect(APP_URL.'/teacher/exams.php'); }

        // Verify the classroom (if any) actually belongs to this course
        if ($classroomId && !db_val("SELECT 1 FROM virtual_classrooms WHERE id=? AND course_id=?", [$classroomId, $courseId])) {
            $classroomId = null;
        }

        if ($id) {
            $ownsExam = teacher_owns_exam($id, $tpId);
            if (!$ownsExam) { flash('error','You do not have permission.'); redirect(APP_URL.'/teacher/exams.php'); }
            db_exec("UPDATE exams SET title=?,course_id=?,duration=?,pass_marks=?,total_marks=?,negative_mark=?,status=?,start_at=?,end_at=?,exam_type=?,is_free=?,max_attempts=?,updated_at=NOW() WHERE id=?",
                [$title,$courseId,$duration,$pass_marks,$total_marks,$neg_mark,$status,$start_at,$end_at,$exam_type,$is_free,$max_attempts,$id]);
            flash('success','Exam updated.');
        } else {
            $newId = db_insert("INSERT INTO exams (title,course_id,classroom_id,duration,pass_marks,total_marks,negative_mark,status,start_at,end_at,exam_type,is_free,max_attempts,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())",
                [$title,$courseId,$classroomId,$duration,$pass_marks,$total_marks,$neg_mark,$status,$start_at,$end_at,$exam_type,$is_free,$max_attempts]);
            flash('success','Exam created. Now add questions.');
            redirect(APP_URL.'/teacher/exams.php?action=questions&id='.$newId);
        }
        redirect(APP_URL.'/teacher/exams.php');

    } elseif ($postAction === 'save_question') {
        $examId   = sanitize_int($_POST['exam_id'] ?? 0);
        $qId      = sanitize_int($_POST['q_id'] ?? 0);
        $body     = sanitize_string($_POST['body'] ?? '', 1000);
        $optA     = sanitize_string($_POST['opt_a'] ?? '', 255);
        $optB     = sanitize_string($_POST['opt_b'] ?? '', 255);
        $optC     = sanitize_string($_POST['opt_c'] ?? '', 255);
        $optD     = sanitize_string($_POST['opt_d'] ?? '', 255);
        $correct  = sanitize_string($_POST['correct'] ?? '', 1);
        $marks    = max(1, (float)($_POST['marks'] ?? 1));
        $explain  = sanitize_string($_POST['explanation'] ?? '', 1000);

        // For CQ exams, MCQ options not required
        $examType = db_val("SELECT exam_type FROM exams WHERE id=?", [$examId]) ?? 'mcq';
        if ($examType === 'mcq' && (!$body || !$optA || !$optB || !in_array($correct, ['a','b','c','d']))) {
            flash('error','Question, options A/B, and correct answer are required.');
            redirect(APP_URL.'/teacher/exams.php?action=questions&id='.$examId);
        }
        if ($examType === 'cq' && !$body) {
            flash('error','Question is required.');
            redirect(APP_URL.'/teacher/exams.php?action=questions&id='.$examId);
        }
        // CQ: clear MCQ-specific fields
        if ($examType === 'cq') { $optA=$optB=$optC=$optD=''; $correct=''; }

        $ownsExam = teacher_owns_exam($examId, $tpId);
        if (!$ownsExam) { flash('error','You do not have permission.'); redirect(APP_URL.'/teacher/exams.php'); }

        if ($qId) {
            db_exec("UPDATE exam_questions SET body=?,option_a=?,option_b=?,option_c=?,option_d=?,correct_option=?,marks=?,explanation=? WHERE id=? AND exam_id=?",
                [$body,$optA,$optB,$optC,$optD,$correct,$marks,$explain,$qId,$examId]);
            flash('success','Question updated.');
        } else {
            $maxOrder = (int)db_val("SELECT COALESCE(MAX(sort_order),0) FROM exam_questions WHERE exam_id=?", [$examId]);
            db_insert("INSERT INTO exam_questions (exam_id,body,option_a,option_b,option_c,option_d,correct_option,marks,explanation,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)",
                [$examId,$body,$optA,$optB,$optC,$optD,$correct,$marks,$explain,$maxOrder+1]);
            // Update total_marks on exam
            db_exec("UPDATE exams SET total_marks=(SELECT COALESCE(SUM(marks),0) FROM exam_questions WHERE exam_id=?) WHERE id=?", [$examId,$examId]);
            flash('success','Question added.');
        }
        redirect(APP_URL.'/teacher/exams.php?action=questions&id='.$examId);

    } elseif ($postAction === 'delete_question') {
        $examId = sanitize_int($_POST['exam_id'] ?? 0);
        $qId    = sanitize_int($_POST['q_id'] ?? 0);
        $ownsExam = teacher_owns_exam($examId, $tpId);
        if ($ownsExam && $qId) {
            db_exec("DELETE FROM exam_questions WHERE id=? AND exam_id=?", [$qId, $examId]);
            db_exec("UPDATE exams SET total_marks=(SELECT COALESCE(SUM(marks),0) FROM exam_questions WHERE exam_id=?) WHERE id=?", [$examId,$examId]);
            flash('success','Question deleted.');
        }
        redirect(APP_URL.'/teacher/exams.php?action=questions&id='.$examId);

    } elseif ($postAction === 'delete_exam') {
        $id = sanitize_int($_POST['id'] ?? 0);
        $ownsExam = teacher_owns_exam($id, $tpId);
        if ($ownsExam) {
            db_exec("DELETE FROM exam_questions WHERE exam_id=?", [$id]);
            db_exec("DELETE FROM exams WHERE id=?", [$id]);
            flash('success','Exam deleted.');
        } else { flash('error','You do not have permission.'); }
        redirect(APP_URL.'/teacher/exams.php');

    } elseif ($postAction === 'grade_cq') {
        $attemptId = sanitize_int($_POST['attempt_id'] ?? 0);
        $examId    = sanitize_int($_POST['exam_id'] ?? 0);
        $score     = max(0.0, (float)($_POST['score'] ?? 0));
        $ownsExam  = teacher_owns_exam($examId, $tpId);
        if ($ownsExam && $attemptId) {
            db_exec("UPDATE exam_attempts SET score=?, status='graded' WHERE id=? AND exam_id=?", [$score, $attemptId, $examId]);
            // Leaderboard: this re-scores the SAME attempt (a grading correction),
            // so it always applies — but a different (retake) attempt still can't
            // overwrite the student's official first-attempt result.
            $ownerId = (int)db_val("SELECT user_id FROM exam_attempts WHERE id=?", [$attemptId]);
            record_exam_leaderboard_result($examId, $ownerId, $attemptId, $score);
            $examTitle = db_val("SELECT title FROM exams WHERE id=?", [$examId]) ?? 'your exam';
            create_notification(
                $ownerId, 'exam', 'Your CQ answer has been graded',
                $examTitle . ' — score: ' . rtrim(rtrim(number_format($score, 1), '0'), '.'),
                APP_URL . '/exam-result.php?attempt=' . $attemptId
            );
            flash('success', 'Score saved.');
        }
        redirect(APP_URL.'/teacher/exams.php?action=cq_submissions&id='.$examId);

    } elseif ($postAction === 'toggle_leaderboard') {
        $id = sanitize_int($_POST['id'] ?? 0);
        $ownsExam = teacher_owns_exam($id, $tpId);
        if ($ownsExam) {
            db_exec("UPDATE exams SET show_leaderboard = 1 - COALESCE(show_leaderboard,0), updated_at=NOW() WHERE id=?", [$id]);
            $state = (int)db_val("SELECT show_leaderboard FROM exams WHERE id=?", [$id]);
            flash('success', $state ? 'Leaderboard is now visible to students.' : 'Leaderboard hidden.');
        }
        redirect(APP_URL.'/teacher/exams.php');
    }
}

// Load data for forms
$myCourses = db_query("SELECT id,title_bn FROM courses WHERE teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=courses.id AND ct.teacher_id=?) ORDER BY title_bn", [$tpId, $tpId]);
$lockedCourseId = sanitize_int($_GET['course'] ?? 0);
if ($lockedCourseId && !teacher_owns_course($lockedCourseId, $tpId)) {
    $lockedCourseId = 0;
}
$lockedClassroomId = sanitize_int($_GET['classroom'] ?? 0);
if ($lockedClassroomId && !db_val("SELECT 1 FROM virtual_classrooms WHERE id=? AND course_id=?", [$lockedClassroomId, $lockedCourseId])) {
    $lockedClassroomId = 0;
}
$editExam  = null;
if (in_array($action, ['edit','questions']) && $editId) {
    $editExam = db_row("SELECT ex.*, c.title_bn as course_title FROM exams ex JOIN courses c ON c.id=ex.course_id WHERE ex.id=? AND (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))", [$editId, $tpId, $tpId]);
    if (!$editExam) { flash('error','Exam not found.'); redirect(APP_URL.'/teacher/exams.php'); }
}

// Exam list
$exams = db_query("
    SELECT ex.*, c.title_bn as course_title,
           (SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_id=ex.id) as q_count,
           (SELECT COUNT(*) FROM exam_attempts ea WHERE ea.exam_id=ex.id AND ea.status IN ('submitted','graded')) as attempt_count
    FROM exams ex
    JOIN courses c ON c.id=ex.course_id
    WHERE c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)
    ORDER BY ex.created_at DESC
", [$tpId, $tpId]);

// Questions for edit
$questions = [];
if ($action === 'questions' && $editExam) {
    $questions = db_query("SELECT * FROM exam_questions WHERE exam_id=? ORDER BY sort_order ASC", [$editId]);
}

// CQ submissions view
$cqSubmissions = [];
if ($action === 'cq_submissions' && $editId) {
    if (!$editExam) { $editExam = db_row("SELECT ex.*, c.title_bn as course_title FROM exams ex JOIN courses c ON c.id=ex.course_id WHERE ex.id=? AND (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))", [$editId, $tpId, $tpId]); }
    if ($editExam) {
        $cqSubmissions = db_query("
            SELECT ea.id as attempt_id, ea.score, ea.drive_url, ea.submitted_at, ea.status,
                   u.name, u.avatar, u.roll_number, u.phone
            FROM exam_attempts ea
            JOIN users u ON u.id = ea.user_id
            WHERE ea.exam_id=? AND ea.status IN ('submitted','graded') AND ea.drive_url IS NOT NULL
            ORDER BY ea.submitted_at ASC
        ", [$editId]);
        $cqQuestions = db_query("SELECT id, body, marks FROM exam_questions WHERE exam_id=? ORDER BY sort_order", [$editId]);
    }
}

// MCQ results view
$mcqResults      = [];
$mcqViewAttempt  = null;
$mcqViewAnswers  = [];
if ($action === 'mcq_results' && $editId) {
    if (!$editExam) {
        $editExam = db_row("SELECT ex.*, c.title_bn as course_title FROM exams ex JOIN courses c ON c.id=ex.course_id WHERE ex.id=? AND (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))", [$editId, $tpId, $tpId]);
    }
    if ($editExam) {
        $viewAid = sanitize_int($_GET['view'] ?? 0);
        if ($viewAid) {
            $mcqViewAttempt = db_row("
                SELECT ea.*, u.name, u.roll_number, u.phone
                FROM exam_attempts ea JOIN users u ON u.id=ea.user_id
                WHERE ea.id=? AND ea.exam_id=?
            ", [$viewAid, $editId]);
            if ($mcqViewAttempt) {
                $mcqViewAnswers = db_query("
                    SELECT eaa.chosen, eq.body as q_body,
                           eq.option_a, eq.option_b, eq.option_c, eq.option_d,
                           eq.correct_option, eq.marks, eq.sort_order
                    FROM exam_attempt_answers eaa
                    JOIN exam_questions eq ON eq.id=eaa.question_id
                    WHERE eaa.attempt_id=?
                    ORDER BY eq.sort_order ASC
                ", [$viewAid]);
            }
        }
        $mcqResults = db_query("
            SELECT ea.id as attempt_id, ea.score, ea.total_correct, ea.total_wrong, ea.total_skipped,
                   ea.time_taken, ea.status, ea.submitted_at,
                   u.id as user_id, u.name, u.roll_number, u.avatar, u.phone,
                   ex.total_marks
            FROM exam_attempts ea
            JOIN users u ON u.id=ea.user_id
            JOIN exams ex ON ex.id=ea.exam_id
            WHERE ea.exam_id=? AND ea.status IN ('submitted','graded')
            ORDER BY ea.score DESC, ea.submitted_at ASC
        ", [$editId]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Exams — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@700;800&family=Hind+Siliguri:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<script>const APP_URL='<?= APP_URL ?>';const CSRF_TOKEN='<?= csrf_token() ?>';</script>
<?php if (isset($_GET['layout']) && $_GET['layout'] === 'modal'): ?>
<style>
.admin-sidebar { display: none !important; }
.admin-content { margin-left: 0 !important; width: 100% !important; padding: 24px !important; }
.header-box, .header-nav { display: none !important; }
</style>
<?php endif; ?>
</head>
<body <?= (isset($_GET['layout']) && $_GET['layout'] === 'modal') ? 'style="background:#fff;"' : '' ?>>
<div class="admin-layout">
    <?php include __DIR__.'/includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php foreach(get_flash() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:16px">
            <span class="material-icons-round"><?= $f['type']==='success'?'check_circle':'error' ?></span>
            <span><?= e($f['message']) ?></span>
        </div>
        <?php endforeach; ?>

        <?php if ($action === 'add' || $action === 'edit'): ?>
        <!-- ── Exam Form ── -->
        <?php $backCourseId = (int)($editExam['course_id'] ?? $lockedCourseId); ?>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:var(--sp-6)">
            <a href="<?= $backCourseId ? APP_URL.'/teacher/course_exams.php?course='.$backCourseId : APP_URL.'/teacher/exams.php' ?>" class="btn btn-ghost btn-sm"><span class="material-icons-round">arrow_back</span></a>
            <h1 style="font-size:var(--text-2xl)"><?= $action==='edit' ? 'Edit Exam' : 'New Exam' ?></h1>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_exam">
            <input type="hidden" name="id" value="<?= (int)($editExam['id'] ?? 0) ?>">
            <?php if ($action === 'add' && $lockedClassroomId): ?>
            <input type="hidden" name="classroom_id" value="<?= (int)$lockedClassroomId ?>">
            <?php endif; ?>
            <div style="display:grid;grid-template-columns:1fr 280px;gap:var(--sp-6)" class="exam-form-grid">
                <div class="card" style="padding:var(--sp-6)">
                    <h3 style="margin-bottom:var(--sp-4)">Basic Info</h3>
                    <div class="form-group">
                        <label class="form-label">Exam Name *</label>
                        <input type="text" name="title" class="form-control" required value="<?= e($editExam['title'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Course *</label>
                        <?php if ($action === 'add' && $lockedCourseId): ?>
                        <?php $lockedName = ''; foreach ($myCourses as $mc) { if ((int)$mc['id']===$lockedCourseId) { $lockedName = $mc['title_bn']; break; } } ?>
                        <input type="text" class="form-control" value="<?= e($lockedName) ?>" disabled>
                        <input type="hidden" name="course_id" value="<?= (int)$lockedCourseId ?>">
                        <small style="color:var(--c-ink-muted)">This exam will only appear in this classroom.</small>
                        <?php else: ?>
                        <select name="course_id" class="form-control" required>
                            <option value="">Select</option>
                            <?php foreach ($myCourses as $mc): ?>
                            <option value="<?= (int)$mc['id'] ?>" <?= ($editExam['course_id']??0)==$mc['id']?'selected':'' ?>><?= e($mc['title_bn']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)">
                        <div class="form-group">
                            <label class="form-label">Duration (minutes)</label>
                            <input type="number" name="duration" class="form-control" min="1" value="<?= (int)($editExam['duration'] ?? 30) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Negative Mark</label>
                            <input type="number" name="negative_mark" class="form-control" min="0" step="0.25" value="<?= (float)($editExam['negative_mark'] ?? 0) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Total Marks</label>
                            <input type="number" name="total_marks" class="form-control" min="0" value="<?= (int)($editExam['total_marks'] ?? 0) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Pass Marks</label>
                            <input type="number" name="pass_marks" class="form-control" min="0" value="<?= (int)($editExam['pass_marks'] ?? 0) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Maximum Attempts</label>
                            <select name="max_attempts" class="form-control">
                                <option value="0" <?= (int)($editExam['max_attempts']??0)===0?'selected':'' ?>>Unlimited (Infinity)</option>
                                <option value="1" <?= (int)($editExam['max_attempts']??0)===1?'selected':'' ?>>1 time</option>
                                <option value="2" <?= (int)($editExam['max_attempts']??0)===2?'selected':'' ?>>2 times</option>
                                <option value="3" <?= (int)($editExam['max_attempts']??0)===3?'selected':'' ?>>3 times</option>
                                <option value="5" <?= (int)($editExam['max_attempts']??0)===5?'selected':'' ?>>5 times</option>
                                <option value="10" <?= (int)($editExam['max_attempts']??0)===10?'selected':'' ?>>10 times</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Start Time</label>
                            <input type="datetime-local" name="start_at" class="form-control" value="<?= $editExam['start_at'] ? date('Y-m-d\TH:i', strtotime($editExam['start_at'])) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">End Time</label>
                            <input type="datetime-local" name="end_at" class="form-control" value="<?= $editExam['end_at'] ? date('Y-m-d\TH:i', strtotime($editExam['end_at'])) : '' ?>">
                        </div>
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;gap:var(--sp-5)">
                    <div class="card" style="padding:var(--sp-6)">
                        <h3 style="margin-bottom:var(--sp-4)">Publishing</h3>
                        <div class="form-group">
                            <label class="form-label">Exam Type</label>
                            <select name="exam_type" class="form-control">
                                <option value="mcq" <?= ($editExam['exam_type']??'mcq')==='mcq'?'selected':'' ?>>MCQ (Multiple Choice)</option>
                                <option value="cq"  <?= ($editExam['exam_type']??'mcq')==='cq' ?'selected':'' ?>>CQ (Creative) — Google Drive</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:700">
                                <input type="checkbox" name="is_free" value="1" <?= ($editExam['is_free']??0)?'checked':'' ?>>
                                Free Exam (anyone can take it)
                            </label>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-control">
                                <?php foreach (['draft'=>'Draft','published'=>'Published','archived'=>'Archived'] as $v=>$l): ?>
                                <option value="<?= $v ?>" <?= ($editExam['status']??'draft')===$v?'selected':'' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%">
                        <span class="material-icons-round">save</span>
                        <?= $action==='edit' ? 'Save Changes' : 'Create Exam' ?>
                    </button>
                    <?php if ($action==='edit'): ?>
                    <a href="<?= APP_URL ?>/teacher/exams.php?action=questions&id=<?= (int)$editExam['id'] ?>"
                       class="btn btn-ghost" style="width:100%;text-align:center">Manage Questions</a>
                    <?php endif; ?>
                    <a href="<?= $backCourseId ? APP_URL.'/teacher/course_exams.php?course='.$backCourseId : APP_URL.'/teacher/exams.php' ?>" class="btn btn-ghost" style="width:100%;text-align:center">Cancel</a>
                </div>
            </div>
        </form>

        <?php elseif ($action === 'questions' && $editExam): ?>
        <!-- ── Questions Manager ── -->
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:var(--sp-2)">
            <a href="<?= APP_URL ?>/teacher/exams.php" class="btn btn-ghost btn-sm"><span class="material-icons-round">arrow_back</span></a>
            <div>
                <h1 style="font-size:var(--text-2xl)">Questions</h1>
                <p style="color:var(--c-ink-muted);margin:2px 0 0"><?= e($editExam['title']) ?> · <?= count($questions) ?> questions</p>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 360px;gap:var(--sp-6);margin-top:var(--sp-6)" class="q-grid">
            <!-- Question list -->
            <div>
                <?php if (empty($questions)): ?>
                <div style="text-align:center;padding:var(--sp-12);background:var(--c-surface);border-radius:var(--r-lg);border:2px dashed var(--c-border)">
                    <span class="material-icons-round" style="font-size:48px;color:var(--c-ink-faint)">help_outline</span>
                    <h4 style="margin:12px 0 4px;color:var(--c-ink-light)">No questions yet</h4>
                    <p style="color:var(--c-ink-muted)">Add a question using the form on the right.</p>
                </div>
                <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:var(--sp-4)">
                    <?php foreach ($questions as $qi => $q): ?>
                    <div style="background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--r-lg);padding:var(--sp-5)">
                        <div style="display:flex;gap:var(--sp-3);align-items:flex-start;margin-bottom:var(--sp-3)">
                            <span style="background:var(--c-primary);color:#fff;border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-family:var(--font-latin);font-weight:800;font-size:var(--text-xs);flex-shrink:0"><?= $qi+1 ?></span>
                            <div style="flex:1">
                                <div style="font-weight:700;font-size:var(--text-sm);color:var(--c-ink)"><?= e($q['body']) ?></div>
                                <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:4px">
                                    Marks: <strong><?= (float)$q['marks'] ?></strong>
                                </div>
                            </div>
                            <div style="display:flex;gap:4px;flex-shrink:0">
                                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this question?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_question">
                                    <input type="hidden" name="exam_id" value="<?= (int)$editExam['id'] ?>">
                                    <input type="hidden" name="q_id" value="<?= (int)$q['id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--c-error)">
                                        <span class="material-icons-round" style="font-size:15px">delete</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-2)">
                            <?php foreach (['a'=>$q['option_a'],'b'=>$q['option_b'],'c'=>$q['option_c'],'d'=>$q['option_d']] as $opt=>$val): if (!$val) continue; ?>
                            <div style="padding:var(--sp-2) var(--sp-3);border-radius:var(--r-md);font-size:var(--text-xs);
                                        <?= $q['correct_option']===$opt ? 'background:var(--c-success-light,#d1fae5);color:var(--c-success);font-weight:700;border:1px solid var(--c-success)' : 'background:var(--c-surface);border:1px solid var(--c-border);color:var(--c-ink-light)' ?>">
                                <strong><?= strtoupper($opt) ?>.</strong> <?= e($val) ?>
                                <?= $q['correct_option']===$opt ? ' ✓' : '' ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($q['explanation']): ?>
                        <div style="margin-top:var(--sp-3);padding:var(--sp-3);background:var(--c-primary-light);border-radius:var(--r-md);font-size:var(--text-xs);color:var(--c-ink-light)">
                            <strong>Explanation:</strong> <?= e($q['explanation']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Add question form -->
            <div style="position:sticky;top:20px">
                <div class="card" style="padding:var(--sp-6)">
                    <h4 style="margin-bottom:var(--sp-4)">Add New Question</h4>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_question">
                        <input type="hidden" name="exam_id" value="<?= (int)$editExam['id'] ?>">
                        <div class="form-group">
                            <label class="form-label">Question *</label>
                            <textarea name="body" class="form-control" rows="3" required placeholder="Write the question..."></textarea>
                        </div>
                        <?php foreach (['a'=>'Option A *','b'=>'Option B *','c'=>'Option C','d'=>'Option D'] as $opt=>$lbl): ?>
                        <div class="form-group">
                            <label class="form-label"><?= $lbl ?></label>
                            <input type="text" name="opt_<?= $opt ?>" class="form-control" <?= in_array($opt,['a','b'])?'required':'' ?>>
                        </div>
                        <?php endforeach; ?>
                        <div class="form-group">
                            <label class="form-label">Correct Answer *</label>
                            <select name="correct" class="form-control" required>
                                <option value="">Select</option>
                                <option value="a">A</option>
                                <option value="b">B</option>
                                <option value="c">C</option>
                                <option value="d">D</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Marks</label>
                            <input type="number" name="marks" class="form-control" min="0.25" step="0.25" value="1">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Explanation (optional)</label>
                            <textarea name="explanation" class="form-control" rows="2"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%">
                            <span class="material-icons-round">add</span> Add Question
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <?php elseif ($action === 'cq_submissions' && $editExam): ?>
        <!-- ── CQ Submissions ── -->
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:var(--sp-5)">
            <a href="<?= APP_URL ?>/teacher/exams.php" class="btn btn-ghost btn-sm"><span class="material-icons-round">arrow_back</span></a>
            <div>
                <h1 style="font-size:var(--text-2xl)">CQ Submissions</h1>
                <p style="color:var(--c-ink-muted);margin:2px 0 0"><?= e($editExam['title']) ?> · <?= count($cqSubmissions) ?> submitted</p>
            </div>
        </div>

        <?php if (!empty($cqQuestions ?? [])): ?>
        <div class="card" style="padding:var(--sp-5);margin-bottom:var(--sp-5);background:var(--c-surface-2)">
            <h4 style="margin-bottom:var(--sp-3)">Questions</h4>
            <?php foreach ($cqQuestions as $qi => $cqq): ?>
            <div style="display:flex;gap:12px;margin-bottom:8px;font-size:var(--text-sm)">
                <span style="background:var(--c-primary);color:#fff;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0"><?= $qi+1 ?></span>
                <div><?= nl2br(e($cqq['body'] ?? '')) ?> <em style="color:var(--c-ink-muted)">(<?= (float)$cqq['marks'] ?> marks)</em></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (empty($cqSubmissions)): ?>
        <div style="text-align:center;padding:var(--sp-12);background:var(--c-surface);border-radius:var(--r-lg);border:2px dashed var(--c-border)">
            <span class="material-icons-round" style="font-size:48px;color:var(--c-ink-faint)">assignment</span>
            <h4 style="color:var(--c-ink-light);margin-top:12px">No one has submitted yet</h4>
        </div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:var(--sp-4)">
            <?php foreach ($cqSubmissions as $si => $sub):
                // Try to extract Google Drive embed URL
                $driveUrl  = $sub['drive_url'] ?? '';
                $embedUrl  = '';
                if (preg_match('#drive\.google\.com/file/d/([A-Za-z0-9_-]+)#', $driveUrl, $m)) {
                    $embedUrl = 'https://drive.google.com/file/d/' . $m[1] . '/preview';
                }
            ?>
            <div class="card" style="padding:0;overflow:hidden">
                <!-- Student header -->
                <div style="padding:var(--sp-4) var(--sp-5);display:flex;align-items:center;gap:var(--sp-3);border-bottom:1px solid var(--c-border)">
                    <img src="<?= e(avatar_url($sub['avatar'],$sub['name'])) ?>"
                         style="width:40px;height:40px;border-radius:50%;object-fit:cover;flex-shrink:0">
                    <div style="flex:1;min-width:0">
                        <div style="font-weight:700"><?= e($sub['name']) ?></div>
                        <div style="font-size:var(--text-xs);color:var(--c-ink-muted)">
                            <?php if ($sub['roll_number']): ?>Roll: <strong><?= e($sub['roll_number']) ?></strong> · <?php endif; ?>
                            Submitted: <?= date('d M Y, h:i A', strtotime($sub['submitted_at'])) ?>
                        </div>
                    </div>
                    <div style="display:flex;gap:6px;flex-shrink:0">
                        <?php if ($sub['status'] === 'graded'): ?>
                        <span style="background:var(--c-success-light);color:var(--c-success);padding:4px 10px;border-radius:999px;font-size:var(--text-xs);font-weight:700">✓ Graded (<?= round((float)$sub['score']) ?>)</span>
                        <?php else: ?>
                        <span style="background:var(--c-warning-light,#fef3c7);color:#92400E;padding:4px 10px;border-radius:999px;font-size:var(--text-xs);font-weight:700">Not graded</span>
                        <?php endif; ?>
                        <a href="<?= e($driveUrl) ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" title="Open in Drive">
                            <span class="material-icons-round" style="font-size:16px">open_in_new</span>
                        </a>
                    </div>
                </div>

                <!-- Drive embed or link -->
                <?php if ($embedUrl): ?>
                <div style="background:var(--c-surface-2);padding:var(--sp-4)">
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-bottom:8px;display:flex;align-items:center;gap:4px">
                        <span class="material-icons-round" style="font-size:14px">insert_drive_file</span>
                        Google Drive file preview
                    </div>
                    <iframe src="<?= e($embedUrl) ?>"
                            style="width:100%;height:420px;border:1px solid var(--c-border);border-radius:var(--r-md)"
                            frameborder="0" allowfullscreen loading="lazy"
                            onload="this.style.background='transparent'">
                    </iframe>
                </div>
                <?php else: ?>
                <div style="padding:var(--sp-4) var(--sp-5);background:var(--c-surface-2)">
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-bottom:6px">Google Drive link:</div>
                    <a href="<?= e($driveUrl) ?>" target="_blank" rel="noopener"
                       style="color:var(--c-primary);font-size:var(--text-sm);word-break:break-all;display:flex;align-items:center;gap:6px">
                        <span class="material-icons-round" style="font-size:16px;flex-shrink:0">folder_open</span>
                        <?= e($driveUrl) ?>
                    </a>
                </div>
                <?php endif; ?>

                <!-- Grade input -->
                <div style="padding:var(--sp-3) var(--sp-5);border-top:1px solid var(--c-border)">
                    <form method="POST" style="display:flex;align-items:center;gap:var(--sp-3)">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="grade_cq">
                        <input type="hidden" name="attempt_id" value="<?= (int)$sub['attempt_id'] ?>">
                        <input type="hidden" name="exam_id" value="<?= (int)$editId ?>">
                        <label style="font-size:var(--text-sm);font-weight:700;flex-shrink:0">Enter score:</label>
                        <input type="number" name="score" class="form-control" style="width:90px;padding:6px 10px"
                               min="0" max="<?= (int)$editExam['total_marks'] ?>" step="0.5"
                               value="<?= round((float)$sub['score']) ?>" required>
                        <span style="font-size:var(--text-xs);color:var(--c-ink-muted)">/ <?= (int)$editExam['total_marks'] ?></span>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <span class="material-icons-round">save</span> Save
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php elseif ($action === 'mcq_results' && $editExam): ?>
        <!-- ── MCQ Results ── -->
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:var(--sp-5)">
            <a href="<?= APP_URL ?>/teacher/exams.php" class="btn btn-ghost btn-sm"><span class="material-icons-round">arrow_back</span></a>
            <div>
                <h1 style="font-size:var(--text-2xl)">MCQ Results</h1>
                <p style="color:var(--c-ink-muted);margin:2px 0 0"><?= e($editExam['title']) ?> · <?= count($mcqResults) ?> attempts</p>
            </div>
        </div>

        <?php if ($mcqViewAttempt): ?>
        <!-- Individual answer sheet -->
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:var(--sp-4)">
            <a href="<?= APP_URL ?>/teacher/exams.php?action=mcq_results&id=<?= (int)$editId ?>" class="btn btn-ghost btn-sm">
                <span class="material-icons-round">arrow_back</span> Back to list
            </a>
            <div>
                <div style="font-weight:700"><?= e($mcqViewAttempt['name']) ?></div>
                <div style="font-size:var(--text-xs);color:var(--c-ink-muted)">
                    Score: <strong><?= number_format((float)$mcqViewAttempt['score'],1) ?></strong> / <?= (int)$editExam['total_marks'] ?>
                    <?php if ($mcqViewAttempt['roll_number']): ?> · Roll: <?= e($mcqViewAttempt['roll_number']) ?><?php endif; ?>
                </div>
            </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:var(--sp-3)">
        <?php foreach ($mcqViewAnswers as $qi => $ans): ?>
        <div class="card" style="padding:var(--sp-4);border-left:4px solid <?= $ans['chosen']===$ans['correct_option']?'#10B981':'#EF4444' ?>">
            <div style="font-weight:700;font-size:var(--text-sm);margin-bottom:10px"><?= $qi+1 ?>. <?= e($ans['q_body']) ?> <em style="font-weight:400;color:var(--c-ink-muted)">(<?= (float)$ans['marks'] ?> marks)</em></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
            <?php foreach (['a'=>$ans['option_a'],'b'=>$ans['option_b'],'c'=>$ans['option_c'],'d'=>$ans['option_d']] as $k=>$v): if (!$v) continue; ?>
            <div style="padding:6px 10px;border-radius:8px;font-size:var(--text-xs);
                <?php if ($k === $ans['correct_option']): ?>background:#d1fae5;border:1px solid #10B981;font-weight:700;color:#065F46<?php
                elseif ($k === $ans['chosen']): ?>background:#fee2e2;border:1px solid #EF4444;color:#991B1B<?php
                else: ?>background:var(--c-surface);border:1px solid var(--c-border);color:var(--c-ink-muted)<?php endif; ?>">
                <strong><?= strtoupper($k) ?>.</strong> <?= e($v) ?>
                <?= $k === $ans['correct_option'] ? ' ✓' : ($k === $ans['chosen'] && $k !== $ans['correct_option'] ? ' ✗' : '') ?>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

        <?php else: ?>
        <!-- Summary table -->
        <?php if (empty($mcqResults)): ?>
        <div style="text-align:center;padding:var(--sp-12);background:var(--c-surface);border-radius:var(--r-lg);border:2px dashed var(--c-border)">
            <span class="material-icons-round" style="font-size:48px;color:var(--c-ink-faint)">bar_chart</span>
            <h4 style="color:var(--c-ink-light);margin-top:12px">No one has attempted yet</h4>
        </div>
        <?php else: ?>
        <div class="data-table-wrap">
        <table class="data-table">
        <thead><tr><th>#</th><th>Student</th><th>Score</th><th>Correct</th><th>Wrong</th><th>Skipped</th><th>Time</th><th>Date</th><th>Answer Sheet</th></tr></thead>
        <tbody>
        <?php foreach ($mcqResults as $ri => $mr): ?>
        <tr>
            <td style="font-family:var(--font-latin);color:var(--c-ink-muted)"><?= $ri+1 ?></td>
            <td>
                <div style="display:flex;align-items:center;gap:8px">
                    <img src="<?= e(avatar_url($mr['avatar'],$mr['name'])) ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover">
                    <div>
                        <div style="font-weight:700;font-size:var(--text-sm)"><?= e($mr['name']) ?></div>
                        <?php if ($mr['roll_number']): ?><div style="font-size:var(--text-xs);color:var(--c-primary)"><?= e($mr['roll_number']) ?></div><?php endif; ?>
                    </div>
                </div>
            </td>
            <td style="font-weight:700;font-family:var(--font-latin)"><?= number_format((float)$mr['score'],1) ?> / <?= (int)$mr['total_marks'] ?></td>
            <td style="font-family:var(--font-latin);color:var(--c-success);font-weight:700"><?= (int)$mr['total_correct'] ?></td>
            <td style="font-family:var(--font-latin);color:var(--c-error)"><?= (int)$mr['total_wrong'] ?></td>
            <td style="font-family:var(--font-latin);color:var(--c-ink-muted)"><?= (int)$mr['total_skipped'] ?></td>
            <td style="font-family:var(--font-latin);font-size:var(--text-sm)"><?= $mr['time_taken'] ? floor((int)$mr['time_taken']/60).'m '.((int)$mr['time_taken']%60).'s' : '—' ?></td>
            <td style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= $mr['submitted_at'] ? date('d M Y', strtotime($mr['submitted_at'])) : '—' ?></td>
            <td>
                <a href="<?= APP_URL ?>/teacher/exams.php?action=mcq_results&id=<?= (int)$editId ?>&view=<?= (int)$mr['attempt_id'] ?>"
                   class="btn btn-ghost btn-sm" title="View answer sheet">
                    <span class="material-icons-round" style="font-size:14px">visibility</span>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php else: ?>
        <!-- ── Exam List ── -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
            <h1 style="font-size:var(--text-2xl)">Exams</h1>
            <a href="<?= APP_URL ?>/teacher/exams.php?action=add" class="btn btn-primary btn-sm">
                <span class="material-icons-round">add</span> New Exam
            </a>
        </div>

        <?php if (empty($exams)): ?>
        <div style="text-align:center;padding:var(--sp-16);background:var(--c-surface);border-radius:var(--r-lg);border:2px dashed var(--c-border)">
            <span class="material-icons-round" style="font-size:56px;color:var(--c-ink-faint)">quiz</span>
            <h3 style="margin:12px 0 8px;color:var(--c-ink-light)">No exams yet</h3>
            <a href="<?= APP_URL ?>/teacher/exams.php?action=add" class="btn btn-primary" style="margin-top:12px">Create Your First Exam</a>
        </div>
        <?php else: ?>
        <div class="data-table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Exam</th><th>Course</th><th>Duration</th><th>Questions</th><th>Attempts</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($exams as $ex): ?>
                    <tr>
                        <td>
                            <div style="font-weight:700;font-size:var(--text-sm)"><?= e($ex['title']) ?></div>
                            <div style="font-size:var(--text-xs);color:var(--c-ink-muted)">Marks: <?= (int)$ex['total_marks'] ?> · Pass: <?= (int)$ex['pass_marks'] ?></div>
                        </td>
                        <td style="font-size:var(--text-sm);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($ex['course_title']) ?></td>
                        <td style="font-family:var(--font-latin);font-size:var(--text-sm)"><?= (int)$ex['duration'] ?> min</td>
                        <td style="font-family:var(--font-latin);font-weight:700;color:var(--c-primary)"><?= (int)$ex['q_count'] ?></td>
                        <td style="font-family:var(--font-latin);font-weight:700"><?= (int)$ex['attempt_count'] ?></td>
                        <td>
                            <span class="status-badge <?= ['published'=>'status-active','draft'=>'status-draft','archived'=>'status-archived'][$ex['status']] ?? '' ?>">
                                <?= ['published'=>'Published','draft'=>'Draft','archived'=>'Archived'][$ex['status']] ?>
                            </span>
                        </td>
                        <td>
                            <div style="display:flex;gap:4px;flex-wrap:wrap">
                                <a href="<?= APP_URL ?>/teacher/exams.php?action=questions&id=<?= (int)$ex['id'] ?>" class="btn btn-primary btn-sm" title="Questions">
                                    <span class="material-icons-round" style="font-size:14px">help_outline</span>
                                </a>
                                <a href="<?= APP_URL ?>/teacher/exams.php?action=edit&id=<?= (int)$ex['id'] ?>" class="btn btn-ghost btn-sm" title="Edit">
                                    <span class="material-icons-round" style="font-size:14px">edit</span>
                                </a>
                                <?php if (($ex['exam_type'] ?? 'mcq') === 'cq'): ?>
                                <a href="<?= APP_URL ?>/teacher/exams.php?action=cq_submissions&id=<?= (int)$ex['id'] ?>" class="btn btn-ghost btn-sm" title="View CQ Answers" style="color:var(--c-primary)">
                                    <span class="material-icons-round" style="font-size:14px">assignment</span>
                                </a>
                                <?php else: ?>
                                <a href="<?= APP_URL ?>/teacher/exams.php?action=mcq_results&id=<?= (int)$ex['id'] ?>" class="btn btn-ghost btn-sm" title="View MCQ Results" style="color:var(--c-success)">
                                    <span class="material-icons-round" style="font-size:14px">bar_chart</span>
                                </a>
                                <?php endif; ?>
                                <!-- Leaderboard toggle -->
                                <form method="POST" style="display:inline" title="<?= ($ex['show_leaderboard']??0) ? 'Hide leaderboard' : 'Publish leaderboard' ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_leaderboard">
                                    <input type="hidden" name="id" value="<?= (int)$ex['id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:<?= ($ex['show_leaderboard']??0)?'var(--c-success)':'var(--c-ink-muted)' ?>">
                                        <span class="material-icons-round" style="font-size:14px"><?= ($ex['show_leaderboard']??0)?'leaderboard':'bar_chart' ?></span>
                                    </button>
                                </form>
                                <?php if ((int)$ex['attempt_count'] === 0): ?>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this exam?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_exam">
                                    <input type="hidden" name="id" value="<?= (int)$ex['id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--c-error)">
                                        <span class="material-icons-round" style="font-size:14px">delete</span>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<style>
@media(max-width:900px){.exam-form-grid,.q-grid{grid-template-columns:1fr !important}}
</style>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
