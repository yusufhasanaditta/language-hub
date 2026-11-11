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
$tp = db_row("SELECT id FROM teacher_profiles WHERE user_id=?", [$uid]);
if (!$tp) { die('Profile not found.'); }
$tpId = (int)$tp['id'];

$sub_id = sanitize_int($_GET['id'] ?? 0);
if (!$sub_id) { redirect(APP_URL.'/teacher/courses.php'); }

$submission = db_row("
    SELECT s.*, e.title as exam_title, e.course_id, e.total_marks as exam_total, u.name as student_name, u.avatar
    FROM cq_submissions s
    JOIN cq_exams e ON e.id = s.exam_id
    JOIN users u ON u.id = s.user_id
    WHERE s.id = ?
", [$sub_id]);

if (!$submission) { flash('error', 'Submission not found.'); redirect(APP_URL.'/teacher/courses.php'); }

$course_id = $submission['course_id'];
$course = db_row("SELECT * FROM courses WHERE id=? AND (teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=courses.id AND ct.teacher_id=?))", [$course_id, $tpId, $tpId]);
if (!$course) { flash('error', 'Permission denied.'); redirect(APP_URL.'/teacher/courses.php'); }

$questions = db_query("SELECT * FROM cq_questions WHERE exam_id=? ORDER BY sort_order ASC, id ASC", [$submission['exam_id']]);

// Marks per NCTB part: (ক) Knowledge=1, (খ) Comprehension=2, (গ) Application=3, (ঘ) Higher Order=4 — 10 per CQ
$PARTS = [
    'k' => ['label' => 'ক (Knowledge)',       'max' => 1],
    'c' => ['label' => 'খ (Comprehension)',    'max' => 2],
    'a' => ['label' => 'গ (Application)',      'max' => 3],
    'h' => ['label' => 'ঘ (Higher Order)',     'max' => 4],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $overallNote = sanitize_string($_POST['overall_note'] ?? '', 1000);
    $total = 0.0;

    foreach ($questions as $q) {
        $qid = (int)$q['id'];
        $scores = [];
        $notes  = [];
        foreach ($PARTS as $key => $part) {
            $raw = (float)($_POST['score_' . $key][$qid] ?? 0);
            $raw = max(0, min($part['max'], $raw));
            $scores[$key] = $raw;
            $notes[$key]  = sanitize_string($_POST['note_' . $key][$qid] ?? '', 500);
            $total += $raw;
        }

        $existing = db_val("SELECT id FROM cq_evaluations WHERE submission_id=? AND question_id=?", [$sub_id, $qid]);
        if ($existing) {
            db_exec("UPDATE cq_evaluations SET score_k=?,score_c=?,score_a=?,score_h=?,note_k=?,note_c=?,note_a=?,note_h=? WHERE id=?",
                [$scores['k'], $scores['c'], $scores['a'], $scores['h'], $notes['k'], $notes['c'], $notes['a'], $notes['h'], $existing]);
        } else {
            db_insert("INSERT INTO cq_evaluations (submission_id,question_id,score_k,score_c,score_a,score_h,note_k,note_c,note_a,note_h) VALUES (?,?,?,?,?,?,?,?,?,?)",
                [$sub_id, $qid, $scores['k'], $scores['c'], $scores['a'], $scores['h'], $notes['k'], $notes['c'], $notes['a'], $notes['h']]);
        }
    }

    if ($total > (float)$submission['exam_total']) {
        $total = (float)$submission['exam_total'];
    }

    db_exec("UPDATE cq_submissions SET score_total=?, teacher_note=?, status='graded' WHERE id=?",
        [$total, $overallNote, $sub_id]);

    create_notification(
        (int) $submission['user_id'], 'exam', 'Your CQ answer has been graded',
        $submission['exam_title'] . ' — score: ' . rtrim(rtrim(number_format($total, 1), '0'), '.') . ' / ' . (float) $submission['exam_total'],
        APP_URL . '/cq_take.php?id=' . $submission['exam_id']
    );

    flash('success', 'CQ submission graded successfully.');
    redirect(APP_URL."/teacher/course_grading.php?course=$course_id");
}

// Existing per-question evaluations (for re-grading / editing)
$evals = [];
foreach (db_query("SELECT * FROM cq_evaluations WHERE submission_id=?", [$sub_id]) as $ev) {
    $evals[(int)$ev['question_id']] = $ev;
}

$_active = 'courses.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Grade CQ — <?= APP_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@700;800&family=Hind+Siliguri:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<script>const APP_URL='<?= APP_URL ?>';const CSRF_TOKEN='<?= csrf_token() ?>';</script>
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__.'/includes/sidebar.php'; ?>
    <div class="admin-content">

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
            <div>
                <a href="<?= APP_URL ?>/teacher/course_grading.php?course=<?= $course_id ?>" style="text-decoration:none;font-weight:600;color:var(--c-primary);display:flex;align-items:center;gap:4px;margin-bottom:8px">
                    <span class="material-icons-round" style="font-size:16px">arrow_back</span> Back to Grading Desk
                </a>
                <h1 style="margin:0;font-size:var(--text-2xl)">Grade Submission</h1>
            </div>
        </div>

        <form method="POST">
        <?= csrf_field() ?>
        <div style="display:flex;gap:var(--sp-6);align-items:flex-start" class="cq-grade-grid">

            <!-- Viewer (Left) -->
            <div style="flex:1;position:sticky;top:var(--sp-4)">
                <div class="card" style="padding:var(--sp-5);height:calc(100vh - 200px);display:flex;flex-direction:column">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;gap:12px;flex-wrap:wrap">
                        <div style="display:flex;align-items:center;gap:12px">
                            <img src="<?= e(avatar_url($submission['avatar'], $submission['student_name'])) ?>" style="width:40px;height:40px;border-radius:50%">
                            <div>
                                <div style="font-weight:700;font-size:16px"><?= e($submission['student_name']) ?></div>
                                <div style="font-size:12px;color:var(--c-ink-muted)">Submitted: <?= date('d M, h:i A', strtotime($submission['submitted_at'])) ?><?php if ($submission['is_late']): ?> · <span style="color:var(--c-error);font-weight:700">Late</span><?php endif; ?></div>
                            </div>
                        </div>
                        <a href="<?= e($submission['drive_link']) ?>" target="_blank" class="btn btn-outline btn-sm">
                            <span class="material-icons-round" style="font-size:16px">open_in_new</span> Open in New Tab
                        </a>
                    </div>

                    <div style="flex:1;background:var(--c-surface-hover);border-radius:var(--r-md);overflow:hidden;border:1px solid var(--c-border)">
                        <iframe src="<?= str_replace('/view?usp=sharing', '/preview', e($submission['drive_link'])) ?>" style="width:100%;height:100%;border:none"></iframe>
                    </div>
                </div>
            </div>

            <!-- Grading Panel (Right) -->
            <div style="flex:1;display:flex;flex-direction:column;gap:var(--sp-5)">
                <div class="card" style="padding:var(--sp-6)">
                    <h3 style="margin-top:0;margin-bottom:4px">Evaluate — NCTB Rubric</h3>
                    <div style="font-size:14px;color:var(--c-ink-muted);margin-bottom:8px">Exam: <?= e($submission['exam_title']) ?></div>
                    <div style="font-size:13px;color:var(--c-ink-muted)">Score each sub-question, then add an overall note. Total is calculated automatically (out of <?= (float)$submission['exam_total'] ?>).</div>
                </div>

                <?php if (empty($questions)): ?>
                <div class="card" style="padding:var(--sp-6);text-align:center;color:var(--c-ink-muted)">
                    This exam has no questions yet — nothing to grade per-question. You can still add an overall note below.
                </div>
                <?php endif; ?>

                <?php foreach ($questions as $qi => $q): $qid = (int)$q['id']; $ev = $evals[$qid] ?? null; ?>
                <div class="card" style="padding:var(--sp-6)">
                    <div style="font-weight:700;margin-bottom:8px">Question <?= $qi + 1 ?></div>
                    <div style="font-size:13px;color:var(--c-ink-muted);margin-bottom:16px;white-space:pre-wrap"><?= e(mb_strimwidth($q['stimulus'], 0, 220, '...')) ?></div>

                    <?php foreach ($PARTS as $key => $part):
                        $qtext = $q['q_' . $key];
                        if (!$qtext) continue;
                        $curScore = $ev ? (float)$ev['score_' . $key] : 0;
                        $curNote  = $ev ? ($ev['note_' . $key] ?? '') : '';
                    ?>
                    <div style="border-top:1px solid var(--c-border);padding-top:12px;margin-top:12px">
                        <div style="font-weight:600;font-size:13px;margin-bottom:6px"><?= e($part['label']) ?> — <?= e(mb_strimwidth($qtext, 0, 140, '...')) ?></div>
                        <div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">
                            <div style="display:flex;align-items:center;gap:6px;flex-shrink:0">
                                <input type="number" name="score_<?= $key ?>[<?= $qid ?>]" class="form-control" step="0.5" min="0" max="<?= $part['max'] ?>"
                                       value="<?= $curScore ?>" style="width:70px;text-align:center;font-family:var(--font-latin);font-weight:700">
                                <span style="font-size:13px;color:var(--c-ink-muted);font-family:var(--font-latin)">/ <?= $part['max'] ?></span>
                            </div>
                            <input type="text" name="note_<?= $key ?>[<?= $qid ?>]" class="form-control" style="flex:1;min-width:200px" placeholder="Feedback for this part..." value="<?= e($curNote) ?>">
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

                <div class="card" style="padding:var(--sp-6)">
                    <label class="form-label">Overall Teacher's Note</label>
                    <textarea name="overall_note" class="form-control" rows="4" placeholder="General suggestions for improvement..."><?= e($submission['teacher_note'] ?? '') ?></textarea>
                    <button type="submit" class="btn btn-primary" style="width:100%;margin-top:16px">
                        <span class="material-icons-round">done_all</span> Submit Grade
                    </button>
                </div>
            </div>

        </div>
        </form>
    </div>
</div>
<style>
@media(max-width:900px){.cq-grade-grid{flex-direction:column !important}.cq-grade-grid > div:first-child{position:static !important}}
</style>
</body>
</html>
