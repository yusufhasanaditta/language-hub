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
if (!$tp) { flash('error','Profile not found.'); redirect(APP_URL.'/teacher/dashboard.php'); }
$tpId = (int)$tp['id'];

$myCourses = db_query("SELECT id,title_bn FROM courses WHERE (teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=courses.id AND ct.teacher_id=?)) AND status='published' ORDER BY title_bn", [$tpId, $tpId]);

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $act      = sanitize_string($_POST['action'] ?? '', 20);
    $courseId = sanitize_int($_POST['course_id'] ?? 0);
    $subject  = sanitize_string($_POST['subject'] ?? '', 255);
    $body     = sanitize_string($_POST['body'] ?? '', 5000);
    $toAll    = isset($_POST['to_all']);
    $students = $_POST['students'] ?? [];

    if ($act === 'send' && $courseId && $subject && $body) {
        // Verify teacher owns course
        $owns = teacher_owns_course($courseId, $tpId);
        if (!$owns) { flash('error','Permission denied.'); redirect(APP_URL.'/teacher/feedback.php'); }

        if ($toAll) {
            db_insert("INSERT INTO teacher_feedback (teacher_profile_id,course_id,student_id,subject,body,created_at) VALUES (?,?,NULL,?,?,NOW())",
                [$tpId, $courseId, $subject, $body]);
            flash('success','Feedback sent to all students.');
        } elseif (!empty($students)) {
            foreach ($students as $sid) {
                $sid = (int)$sid;
                if ($sid) db_insert("INSERT INTO teacher_feedback (teacher_profile_id,course_id,student_id,subject,body,created_at) VALUES (?,?,?,?,?,NOW())",
                    [$tpId, $courseId, $sid, $subject, $body]);
            }
            flash('success', 'Feedback sent to ' . count($students) . ' students.');
        } else {
            flash('error','Select at least one student.');
        }
        redirect(APP_URL.'/teacher/feedback.php');
    }
}

// Sent feedback list
$feedbacks = db_query("
    SELECT tf.*, c.title_bn AS course_title,
           u.name AS student_name
    FROM teacher_feedback tf
    JOIN courses c ON c.id=tf.course_id
    LEFT JOIN users u ON u.id=tf.student_id
    WHERE tf.teacher_profile_id=?
    ORDER BY tf.created_at DESC
    LIMIT 50
", [$tpId]);

// Selected course students (for AJAX)
$selCourseId = sanitize_int($_GET['course'] ?? (count($myCourses) ? $myCourses[0]['id'] : 0));
$selStudents = [];
if ($selCourseId) {
    $selStudents = db_query("
        SELECT u.id, u.name, u.avatar
        FROM enrollments e
        JOIN users u ON u.id=e.user_id
        WHERE e.course_id=? AND e.status='active'
        ORDER BY u.name
    ", [$selCourseId]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Feedback — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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
        <?php foreach(get_flash() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:16px">
            <span class="material-icons-round"><?= $f['type']==='success'?'check_circle':'error' ?></span>
            <span><?= e($f['message']) ?></span>
        </div>
        <?php endforeach; ?>

        <h1 style="font-size:var(--text-2xl);margin-bottom:var(--sp-6)">Send Feedback to Students</h1>

        <div style="display:grid;grid-template-columns:1fr 380px;gap:var(--sp-6);align-items:start" class="fb-grid">

            <!-- Form -->
            <div class="card" style="padding:var(--sp-6)">
                <h3 style="margin-bottom:var(--sp-5)">New Feedback</h3>
                <form method="POST" id="fbForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send">

                    <div class="form-group">
                        <label class="form-label">Select Course *</label>
                        <select name="course_id" id="courseSelect" class="form-control" required onchange="loadStudents(this.value)">
                            <?php foreach ($myCourses as $mc): ?>
                            <option value="<?= (int)$mc['id'] ?>" <?= $selCourseId==$mc['id']?'selected':'' ?>><?= e($mc['title_bn']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Subject *</label>
                        <input type="text" name="subject" class="form-control" required placeholder="Feedback subject">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Message *</label>
                        <textarea name="body" class="form-control" rows="6" required placeholder="Write your feedback for the students..."></textarea>
                    </div>

                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:700">
                            <input type="checkbox" name="to_all" id="toAll" onchange="toggleStudentList(this.checked)">
                            Send to all students
                        </label>
                    </div>

                    <div id="studentListWrap" class="form-group">
                        <label class="form-label">Select Specific Students</label>
                        <div style="max-height:240px;overflow-y:auto;border:1px solid var(--c-border);border-radius:var(--r-md);padding:var(--sp-3)" id="studentList">
                            <?php foreach ($selStudents as $st): ?>
                            <label style="display:flex;align-items:center;gap:8px;padding:6px 0;cursor:pointer;border-bottom:1px solid var(--c-border)">
                                <input type="checkbox" name="students[]" value="<?= (int)$st['id'] ?>">
                                <img src="<?= e(avatar_url($st['avatar'], $st['name'])) ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover" alt="">
                                <?= e($st['name']) ?>
                            </label>
                            <?php endforeach; ?>
                            <?php if (empty($selStudents)): ?>
                            <div style="color:var(--c-ink-muted);text-align:center;padding:var(--sp-4)">No students in this course</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <span class="material-icons-round">send</span> Send Feedback
                    </button>
                </form>
            </div>

            <!-- Sent history -->
            <div class="card" style="padding:var(--sp-6)">
                <h3 style="margin-bottom:var(--sp-5)">Sent Feedback</h3>
                <?php if (empty($feedbacks)): ?>
                <div style="text-align:center;color:var(--c-ink-muted);padding:var(--sp-6)">
                    <span class="material-icons-round" style="font-size:40px;display:block;margin-bottom:8px">feedback</span>
                    No feedback sent yet.
                </div>
                <?php else: foreach ($feedbacks as $fb): ?>
                <div style="border:1px solid var(--c-border);border-radius:var(--r-md);padding:var(--sp-4);margin-bottom:var(--sp-3)">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:var(--sp-2)">
                        <span style="font-weight:700;font-size:var(--text-sm)"><?= e($fb['subject']) ?></span>
                        <span style="font-size:10px;color:var(--c-ink-muted)"><?= date('d M Y', strtotime($fb['created_at'])) ?></span>
                    </div>
                    <div style="font-size:var(--text-xs);color:var(--c-primary);margin-bottom:var(--sp-2)"><?= e(mb_substr($fb['course_title']??'',0,40,'UTF-8')) ?></div>
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted)">
                        Recipient: <?= $fb['student_id'] ? e($fb['student_name'] ?? 'Unknown') : '<strong>Everyone</strong>' ?>
                    </div>
                    <div style="font-size:var(--text-sm);color:var(--c-ink-light);margin-top:var(--sp-2);line-height:1.6">
                        <?= e(mb_substr($fb['body'],0,120,'UTF-8')) ?><?= mb_strlen($fb['body'],'UTF-8')>120?'…':'' ?>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>
<style>
@media(max-width:1024px){.fb-grid{grid-template-columns:1fr !important}}
</style>
<script>
function toggleStudentList(toAll) {
    document.getElementById('studentListWrap').style.display = toAll ? 'none' : 'block';
}

function loadStudents(courseId) {
    const wrap = document.getElementById('studentList');
    wrap.innerHTML = '<div style="text-align:center;padding:20px;color:var(--c-ink-muted)">Loading...</div>';
    fetch(APP_URL + '/api/messages.php?action=students&course=' + courseId, {
        headers: { 'X-CSRF-TOKEN': CSRF_TOKEN, 'X-Requested-With': 'XMLHttpRequest' }
    }).then(r => r.json()).then(data => {
        if (!data.students || !data.students.length) {
            wrap.innerHTML = '<div style="color:var(--c-ink-muted);text-align:center;padding:16px">No students in this course</div>';
            return;
        }
        wrap.innerHTML = data.students.map(s => `
            <label style="display:flex;align-items:center;gap:8px;padding:6px 0;cursor:pointer;border-bottom:1px solid var(--c-border)">
                <input type="checkbox" name="students[]" value="${s.id}">
                <img src="${s.avatar}" style="width:28px;height:28px;border-radius:50%;object-fit:cover" alt="">
                ${s.name}
            </label>
        `).join('');
    }).catch(() => {
        wrap.innerHTML = '<div style="color:var(--c-error);text-align:center;padding:16px">Failed to load</div>';
    });
}
</script>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
