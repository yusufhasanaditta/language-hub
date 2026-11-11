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

$exam_id = sanitize_int($_GET['id'] ?? 0);
$course_id = sanitize_int($_GET['course'] ?? 0);
$classroom_id = sanitize_int($_GET['classroom'] ?? 0);

if ($exam_id) {
    $exam = db_row("SELECT * FROM cq_exams WHERE id=?", [$exam_id]);
    if (!$exam) { flash('error', 'Exam not found'); redirect(APP_URL.'/teacher/courses.php'); }
    $course_id = $exam['course_id'];
    $classroom_id = (int)($exam['classroom_id'] ?? 0);
}

// Verify teacher owns course
$course = db_row("SELECT * FROM courses WHERE id=? AND (teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=courses.id AND ct.teacher_id=?))", [$course_id, $tpId, $tpId]);
if (!$course) { flash('error', 'Permission denied.'); redirect(APP_URL.'/teacher/courses.php'); }

// Verify the classroom (if any) actually belongs to this course
if ($classroom_id && !db_val("SELECT 1 FROM virtual_classrooms WHERE id=? AND course_id=?", [$classroom_id, $course_id])) {
    $classroom_id = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_exam') {
        $title = sanitize_string($_POST['title'], 255);
        $duration = sanitize_int($_POST['duration']);
        $total_marks = sanitize_int($_POST['total_marks']);
        $status = in_array($_POST['status'], ['draft','published']) ? $_POST['status'] : 'draft';
        $desc = $_POST['description'] ?? '';
        
        if ($exam_id) {
            db_exec("UPDATE cq_exams SET title=?, instructions=?, duration=?, total_marks=?, status=? WHERE id=?",
                [$title, $desc, $duration, $total_marks, $status, $exam_id]);
            flash('success', 'CQ Exam settings updated.');
        } else {
            $exam_id = db_insert("INSERT INTO cq_exams (course_id, classroom_id, title, instructions, duration, total_marks, status) VALUES (?,?,?,?,?,?,?)",
                [$course_id, $classroom_id ?: null, $title, $desc, $duration, $total_marks, $status]);
            flash('success', 'CQ Exam created.');
        }
        redirect("?id=$exam_id");
    }
    
    if ($action === 'add_question' && $exam_id) {
        $stem = $_POST['stem_text'] ?? '';
        $q_knowledge = $_POST['q_knowledge'] ?? '';
        $q_comprehension = $_POST['q_comprehension'] ?? '';
        $q_application = $_POST['q_application'] ?? '';
        $q_higher_order = $_POST['q_higher_order'] ?? '';
        $sort_order = sanitize_int($_POST['sort_order'] ?? 1);
        
        $m_k = sanitize_int($_POST['marks_knowledge'] ?? 1);
        $m_c = sanitize_int($_POST['marks_comprehension'] ?? 2);
        $m_a = sanitize_int($_POST['marks_application'] ?? 3);
        $m_h = sanitize_int($_POST['marks_higher_order'] ?? 4);
        
        db_insert("INSERT INTO cq_questions (exam_id, stimulus, q_k, q_c, q_a, q_h, sort_order) 
                   VALUES (?,?,?,?,?,?,?)",
            [$exam_id, $stem, $q_knowledge, $q_comprehension, $q_application, $q_higher_order, $sort_order]);
        
        flash('success', 'Creative Question added successfully.');
        redirect("?id=$exam_id");
    }

    if ($action === 'delete_question' && $exam_id) {
        $qid = sanitize_int($_POST['question_id']);
        db_exec("DELETE FROM cq_questions WHERE id=? AND exam_id=?", [$qid, $exam_id]);
        flash('success', 'Question deleted.');
        redirect("?id=$exam_id");
    }
}

$questions = $exam_id ? db_query("SELECT * FROM cq_questions WHERE exam_id=? ORDER BY sort_order ASC", [$exam_id]) : [];

$_active = 'courses.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>CQ Builder — <?= APP_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@700;800&family=Hind+Siliguri:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<script>const APP_URL='<?= APP_URL ?>';</script>
<style>
.cq-card {
    background: var(--c-surface);
    border: 1px solid var(--c-border);
    border-radius: var(--r-lg);
    padding: var(--sp-5);
    margin-bottom: var(--sp-4);
}
.cq-part { margin-bottom: 12px; }
.cq-part-label { font-weight: 700; color: var(--c-primary); display: inline-block; width: 30px; }
.marks-badge { float: right; font-size: 12px; font-weight: 700; background: rgba(91, 33, 182,0.1); color: var(--c-primary); padding: 2px 8px; border-radius: 12px; }
</style>
<?php if (isset($_GET['layout']) && $_GET['layout'] === 'modal'): ?>
<style>
.admin-sidebar { display: none !important; }
.admin-content { margin-left: 0 !important; width: 100% !important; padding: 24px !important; }
</style>
<?php endif; ?>
</head>
<body <?= (isset($_GET['layout']) && $_GET['layout'] === 'modal') ? 'style="background:#fff;"' : '' ?>>
<div class="admin-layout">
    <?php include __DIR__.'/includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php foreach(get_flash() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:16px">
            <span><?= e($f['message']) ?></span>
        </div>
        <?php endforeach; ?>

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
            <div>
                <a href="<?= APP_URL ?>/teacher/course_exams.php?course=<?= $course_id ?>" style="text-decoration:none;font-weight:600;color:var(--c-primary);display:flex;align-items:center;gap:4px;margin-bottom:8px">
                    <span class="material-icons-round" style="font-size:16px">arrow_back</span> Back to Exam Center
                </a>
                <h1 style="margin:0;font-size:var(--text-2xl)"><?= $exam_id ? 'Edit CQ Exam' : 'Create New CQ Exam' ?></h1>
            </div>
        </div>

        <div style="display:flex;gap:var(--sp-6)">
            <!-- Main Content Area -->
            <div style="flex:2">
                <?php if ($exam_id): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-4)">
                    <h2 style="margin:0">Questions (<?= count($questions) ?>)</h2>
                    <button class="btn btn-primary" onclick="document.getElementById('addQuestionForm').style.display='block'">
                        <span class="material-icons-round">add</span> Add Question
                    </button>
                </div>

                <div id="addQuestionForm" class="card" style="display:none;padding:var(--sp-5);margin-bottom:var(--sp-6);border:2px dashed var(--c-primary)">
                    <h3 style="margin-top:0">New Creative Question</h3>
                    <p style="font-size:13px;color:var(--c-ink-muted)">NCTB Standard: Stem + 4 parts (Knowledge, Comprehension, Application, Higher Order Thinking)</p>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_question">
                        
                        <div class="form-group">
                            <label class="form-label">Stem Text (উদ্দীপক)</label>
                            <textarea name="stem_text" class="form-control" rows="4" required placeholder="Enter the scenario or paragraph here..."></textarea>
                        </div>
                        
                        <div style="display:grid;grid-template-columns:1fr 80px;gap:12px;margin-bottom:12px">
                            <div class="form-group" style="margin:0">
                                <label class="form-label">(a) Knowledge (জ্ঞানমূলক)</label>
                                <input type="text" name="q_knowledge" class="form-control" required>
                            </div>
                            <div class="form-group" style="margin:0">
                                <label class="form-label">Marks</label>
                                <input type="number" name="marks_knowledge" class="form-control" value="1" required>
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 80px;gap:12px;margin-bottom:12px">
                            <div class="form-group" style="margin:0">
                                <label class="form-label">(b) Comprehension (অনুধাবনমূলক)</label>
                                <input type="text" name="q_comprehension" class="form-control" required>
                            </div>
                            <div class="form-group" style="margin:0">
                                <label class="form-label">Marks</label>
                                <input type="number" name="marks_comprehension" class="form-control" value="2" required>
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 80px;gap:12px;margin-bottom:12px">
                            <div class="form-group" style="margin:0">
                                <label class="form-label">(c) Application (প্রয়োগমূলক)</label>
                                <input type="text" name="q_application" class="form-control" required>
                            </div>
                            <div class="form-group" style="margin:0">
                                <label class="form-label">Marks</label>
                                <input type="number" name="marks_application" class="form-control" value="3" required>
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 80px;gap:12px;margin-bottom:12px">
                            <div class="form-group" style="margin:0">
                                <label class="form-label">(d) Higher Order (উচ্চতর দক্ষতা)</label>
                                <input type="text" name="q_higher_order" class="form-control" required>
                            </div>
                            <div class="form-group" style="margin:0">
                                <label class="form-label">Marks</label>
                                <input type="number" name="marks_higher_order" class="form-control" value="4" required>
                            </div>
                        </div>

                        <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:20px">
                            <button type="button" class="btn btn-ghost" onclick="document.getElementById('addQuestionForm').style.display='none'">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Question</button>
                        </div>
                    </form>
                </div>

                <?php foreach ($questions as $i => $q): ?>
                <div class="cq-card">
                    <div style="display:flex;justify-content:space-between;margin-bottom:16px">
                        <span style="font-weight:800;font-size:18px">Question <?= $i+1 ?></span>
                        <form method="POST" onsubmit="return confirm('Delete this question?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_question">
                            <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                            <button type="submit" class="btn btn-ghost btn-sm text-danger"><span class="material-icons-round">delete</span></button>
                        </form>
                    </div>
                    <div style="background:var(--c-surface-hover);padding:16px;border-radius:var(--r-md);margin-bottom:16px">
                        <strong>Stem:</strong><br>
                        <?= nl2br(e($q['stimulus'])) ?>
                    </div>
                    <div class="cq-part">
                        <span class="marks-badge">1</span>
                        <span class="cq-part-label">(a)</span> <?= e($q['q_k']) ?>
                    </div>
                    <div class="cq-part">
                        <span class="marks-badge">2</span>
                        <span class="cq-part-label">(b)</span> <?= e($q['q_c']) ?>
                    </div>
                    <div class="cq-part">
                        <span class="marks-badge">3</span>
                        <span class="cq-part-label">(c)</span> <?= e($q['q_a']) ?>
                    </div>
                    <div class="cq-part">
                        <span class="marks-badge">4</span>
                        <span class="cq-part-label">(d)</span> <?= e($q['q_h']) ?>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if (empty($questions)): ?>
                <div style="text-align:center;padding:var(--sp-12);background:var(--c-surface);border-radius:var(--r-xl);border:2px dashed var(--c-border)">
                    <p style="color:var(--c-ink-muted)">No questions added yet. Click "Add Question" to build this exam.</p>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div style="text-align:center;padding:var(--sp-12);background:var(--c-surface);border-radius:var(--r-xl)">
                    <span class="material-icons-round" style="font-size:48px;color:var(--c-ink-faint)">save</span>
                    <h3 style="margin:12px 0 8px">Save Settings First</h3>
                    <p style="color:var(--c-ink-muted)">You must save the exam settings on the right before adding questions.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Settings Sidebar -->
            <div style="flex:1">
                <form method="POST" class="card" style="padding:var(--sp-5);position:sticky;top:var(--sp-6)">
                    <h3 style="margin-top:0;margin-bottom:var(--sp-4)">Exam Settings</h3>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_exam">
                    
                    <div class="form-group">
                        <label class="form-label">Exam Title *</label>
                        <input type="text" name="title" class="form-control" required value="<?= e($exam['title'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description / Instructions</label>
                        <textarea name="description" class="form-control" rows="3"><?= e($exam['instructions'] ?? '') ?></textarea>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <div class="form-group">
                            <label class="form-label">Duration (mins) *</label>
                            <input type="number" name="duration" class="form-control" required value="<?= (int)($exam['duration'] ?? 150) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Total Marks *</label>
                            <input type="number" name="total_marks" class="form-control" required value="<?= (int)($exam['total_marks'] ?? 100) ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="draft" <?= ($exam['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="published" <?= ($exam['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width:100%;margin-top:12px">
                        <span class="material-icons-round">save</span> Save Settings
                    </button>
                </form>
            </div>
        </div>

    </div>
</div>
</body>
</html>
