<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_teacher();

ensure_classroom_subject_column();

$user = current_user();
$uid = (int)$user['id'];
$isAdmin = is_admin();
$tpId = 0;
if (!$isAdmin) {
    $tp = db_row("SELECT * FROM teacher_profiles WHERE user_id=?", [$uid]);
    if (!$tp) { die('Teacher profile not found.'); }
    $tpId = (int)$tp['id'];
}

$course_id = sanitize_int($_GET['id'] ?? 0);
if (!$course_id) { redirect(APP_URL."/".($isAdmin?'admin':'teacher')."/courses.php"); }

// Check if teacher owns the course or is a co-teacher (admins can manage any course)
$course = $isAdmin
    ? db_row("SELECT c.*, (SELECT COUNT(*) FROM enrollments WHERE course_id=c.id) as enroll_count FROM courses c WHERE c.id=?", [$course_id])
    : db_row("
        SELECT c.*,
               (SELECT COUNT(*) FROM enrollments WHERE course_id=c.id) as enroll_count
        FROM courses c
        WHERE c.id=? AND (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))
    ", [$course_id, $tpId, $tpId]);

if (!$course) { flash('error', 'You do not have access to this course.'); redirect(APP_URL."/".($isAdmin?'admin':'teacher')."/courses.php"); }

// Handle Create Classroom
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_classroom') {
    csrf_check();
    $subject = sanitize_string($_POST['subject'] ?? '');
    $title = sanitize_string($_POST['title']);
    $desc = sanitize_string($_POST['description']);
    $thumbPath = null;

    if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg','jpeg','png','webp','gif'];
        $ext = strtolower(pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $uploadDir = __DIR__ . '/../uploads/classrooms/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $filename = uniqid('cr_') . '.' . $ext;
            if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $uploadDir . $filename)) {
                $thumbPath = 'uploads/classrooms/' . $filename;
            }
        }
    }

    if ($title) {
        db_insert("INSERT INTO virtual_classrooms (course_id, subject, title, description, thumbnail, sort_order) VALUES (?, ?, ?, ?, ?, 0)", [$course_id, $subject ?: null, $title, $desc, $thumbPath]);
        flash('success', 'Virtual Classroom created successfully!');
    }
    redirect(APP_URL."/teacher/classroom.php?id=$course_id");
}

// Handle Delete Classroom
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_classroom') {
    csrf_check();
    $del_id = sanitize_int($_POST['classroom_id'] ?? 0);
    $del = db_row("SELECT id FROM virtual_classrooms WHERE id=? AND course_id=?", [$del_id, $course_id]);
    if ($del) {
        db_query("DELETE FROM virtual_classrooms WHERE id=?", [$del_id]);
        flash('success', 'Classroom deleted.');
    }
    redirect(APP_URL."/teacher/classroom.php?id=$course_id");
}

$classrooms = db_query("SELECT * FROM virtual_classrooms WHERE course_id=? ORDER BY sort_order ASC, created_at ASC", [$course_id]);
$classroomGroups = group_classrooms_by_subject($classrooms);
$existingSubjects = db_query("SELECT DISTINCT subject FROM virtual_classrooms WHERE course_id=? AND subject IS NOT NULL AND subject<>'' ORDER BY subject ASC", [$course_id]);

$_active = 'courses.php';

// Vibrant Gradients Array
$gradients = [
    'linear-gradient(135deg, #ff9a9e 0%, #fecfef 99%, #fecfef 100%)', // Pink
    'linear-gradient(120deg, #a1c4fd 0%, #c2e9fb 100%)', // Blue
    'linear-gradient(120deg, #d4fc79 0%, #96e6a1 100%)', // Green
    'linear-gradient(120deg, #fccb90 0%, #d57eeb 100%)', // Orange/Purple
    'linear-gradient(120deg, #e0c3fc 0%, #8ec5fc 100%)'  // Purple/Blue
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Virtual Classroom Dashboard: <?= e($course['title_en']?:$course['title_bn']) ?> — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<style>
body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8f9fe; }
.classroom-header {
    background: #ffffff;
    padding: var(--sp-8) var(--sp-6);
    border-radius: 24px;
    margin-bottom: var(--sp-8);
    display: flex;
    align-items: center;
    gap: var(--sp-8);
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.05);
}
.classroom-thumbnail {
    width: 200px;
    height: 120px;
    object-fit: cover;
    border-radius: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.grid-cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 24px;
}

.classroom-card { 
    border-radius: 16px; 
    padding: 16px; 
    transition: all .3s ease; 
    display: flex; 
    flex-direction: column; 
    text-decoration: none; 
    color: #333; 
    background: #ffffff;
    border: 1px solid rgba(0,0,0,0.05);
    box-shadow: 0 4px 12px rgba(0,0,0,0.04);
}
.classroom-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(0,0,0,0.08); }
.classroom-card .thumb {
    width: 100%;
    aspect-ratio: 16/9;
    border-radius: 12px;
    object-fit: cover;
    margin-bottom: 16px;
    background: #f1f5f9;
}
.classroom-card h3 { margin: 0 0 8px; font-size: 18px; font-weight: 800; color: #1a1a2e; line-height: 1.3; }
.classroom-card p { margin: 0 0 16px; font-size: 14px; color: #64748b; font-weight: 600; line-height: 1.5; flex: 1; display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden; }
.classroom-card .card-footer { display: flex; align-items: center; justify-content: space-between; border-top: 1px solid rgba(0,0,0,0.05); padding-top: 12px; }

.btn-create {
    background: #ffffff;
    color: var(--c-primary);
    border: 3px dashed rgba(91, 33, 182, 0.3);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 16px;
    cursor: pointer;
    box-shadow: none;
    min-height: 250px;
}
.btn-create:hover {
    border-color: var(--c-primary);
    background: rgba(91, 33, 182, 0.02);
    box-shadow: 0 12px 24px rgba(91, 33, 182, 0.1);
    transform: translateY(-4px);
}

.modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(5px); }
.modal.show { display: flex; }
.modal-content { background: #fff; border-radius: 24px; width: 100%; max-width: 500px; padding: 32px; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
</style>
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . ($isAdmin ? '/../admin/includes/sidebar.php' : '/includes/sidebar.php'); ?>
    <div class="admin-content" style="background:#f8f9fe;padding:32px">
        <?php foreach(get_flash() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:24px;border-radius:12px">
            <span class="material-icons-round"><?= $f['type']==='success'?'check_circle':'error' ?></span>
            <span><?= e($f['message']) ?></span>
        </div>
        <?php endforeach; ?>

        <!-- Header -->
        <div class="classroom-header">
            <?php $courseThumbUrl = course_thumbnail_url($course['thumbnail'] ?? '', $course['title_bn'] ?? '', '160x90'); ?>
            <img src="<?= e($courseThumbUrl) ?>" class="classroom-thumbnail" alt="Thumbnail">
            <div>
                <div style="font-size:14px;text-transform:uppercase;letter-spacing:2px;color:var(--c-primary);margin-bottom:8px;font-weight:800">Course Dashboard</div>
                <h1 style="margin:0;font-size:36px;font-weight:800;color:#1a1a2e;margin-bottom:12px"><?= e($course['title_en'] ?: $course['title_bn']) ?></h1>
                <div style="display:flex;gap:16px;color:#666;font-size:14px;font-weight:600">
                    <span style="display:flex;align-items:center;gap:6px"><span class="material-icons-round" style="font-size:18px;color:var(--c-primary)">people</span> <?= number_format($course['enroll_count']) ?> Students Enrolled</span>
                </div>
            </div>
            <div style="margin-left:auto;display:flex;gap:12px">
                <a href="<?= APP_URL ?>/teacher/course_exams.php?course=<?= $course_id ?>" class="btn btn-outline" style="border-radius:12px;font-weight:700">
                    <span class="material-icons-round">quiz</span> Exam Center
                </a>
                <a href="<?= APP_URL ?>/teacher/course_grading.php?course=<?= $course_id ?>" class="btn btn-outline" style="border-radius:12px;font-weight:700">
                    <span class="material-icons-round">grading</span> Grading Desk
                </a>
                <a href="<?= APP_URL ?>/teacher/schedules.php?course_id=<?= $course_id ?>" class="btn btn-primary" style="border-radius:12px;font-weight:700;box-shadow:0 4px 12px rgba(91, 33, 182,0.3)">
                    <span class="material-icons-round">calendar_month</span> Course Routine
                </a>
                <a href="<?= APP_URL ?>/<?= $isAdmin?'admin':'teacher' ?>/courses.php" class="btn btn-outline" style="border-radius:12px;font-weight:700">Back to Courses</a>
            </div>
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:32px">
            <h2 style="margin:0;font-weight:800;font-size:32px;color:#1a1a2e">Virtual Classrooms</h2>
            <button class="btn btn-primary" style="border-radius:12px;padding:12px 24px;font-weight:700;box-shadow:0 8px 16px rgba(91, 33, 182,0.3)" onclick="document.getElementById('createModal').classList.add('show')">
                <span class="material-icons-round">add</span> Create Classroom
            </button>
        </div>

        <?php if (empty($classrooms)): ?>
        <div class="grid-cards">
            <button class="classroom-card btn-create" onclick="document.getElementById('createModal').classList.add('show')">
                <span class="material-icons-round" style="font-size:64px;color:var(--c-primary)">add_circle_outline</span>
                <span style="font-weight:800;font-size:24px">New Classroom</span>
            </button>
        </div>
        <?php else: ?>
        <?php foreach ($classroomGroups as $subjectName => $group): ?>
        <div style="margin-bottom:32px">
            <h3 style="margin:0 0 16px;font-size:18px;font-weight:800;color:var(--c-primary);display:flex;align-items:center;gap:8px">
                <span class="material-icons-round" style="font-size:20px"><?= $subjectName==='General'?'folder':'menu_book' ?></span>
                <?= e($subjectName) ?>
                <span style="font-size:13px;font-weight:700;color:#94a3b8">(<?= count($group) ?>)</span>
            </h3>
            <div class="grid-cards">
                <?php foreach($group as $cr): ?>
                <div style="position:relative">
                    <form method="POST" action="" onsubmit="return confirm('Delete this classroom? All its materials and comments will be permanently removed.');" style="position:absolute;top:12px;right:12px;z-index:5;margin:0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_classroom">
                        <input type="hidden" name="classroom_id" value="<?= $cr['id'] ?>">
                        <button type="submit" title="Delete Classroom" style="background:rgba(255,255,255,0.95);border:none;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#ef4444;box-shadow:0 2px 8px rgba(0,0,0,0.15)">
                            <span class="material-icons-round" style="font-size:18px">delete</span>
                        </button>
                    </form>
                    <a href="<?= APP_URL ?>/teacher/manage_classroom.php?id=<?= $cr['id'] ?>" class="classroom-card">
                        <?php
                            $thumbUrl = course_thumbnail_url($cr['thumbnail'] ?? '', 'Classroom', '400x225', 'e2e8f0');
                        ?>
                        <img src="<?= e($thumbUrl) ?>" class="thumb" alt="Thumbnail">
                        <h3><?= e($cr['title']) ?></h3>
                        <p><?= e($cr['description']) ?: '<span style="opacity:0.5">No description provided</span>' ?></p>
                        <div class="card-footer">
                            <span style="font-size:13px;font-weight:700;color:rgba(0,0,0,0.5)">
                                <?= date('M j, Y', strtotime($cr['created_at'])) ?>
                            </span>
                            <span style="background:rgba(91, 33, 182, 0.1);color:var(--c-primary);padding:6px 16px;border-radius:20px;font-size:13px;font-weight:700">Manage</span>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Create Classroom Modal -->
<div class="modal" id="createModal">
    <div class="modal-content">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
            <h3 style="margin:0;font-size:24px;font-weight:800;color:#1a1a2e">Create Virtual Classroom</h3>
            <button class="btn btn-ghost" style="padding:8px;border-radius:50%;background:#f0f0f0" onclick="document.getElementById('createModal').classList.remove('show')"><span class="material-icons-round">close</span></button>
        </div>
        <form method="POST" action="" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_classroom">
            <div class="form-group" style="margin-bottom:20px">
                <label class="form-label" style="font-weight:700;color:#333">Subject / Section</label>
                <input type="text" name="subject" list="subjectList" class="form-control" style="border-radius:12px;padding:12px" placeholder="e.g. Bangla, English, GK">
                <datalist id="subjectList">
                    <?php foreach ($existingSubjects as $s): ?>
                    <option value="<?= e($s['subject']) ?>">
                    <?php endforeach; ?>
                </datalist>
                <small style="color:#666;margin-top:4px;display:block">Groups this classroom under a subject for students. Leave empty to keep it in "General".</small>
            </div>
            <div class="form-group" style="margin-bottom:20px">
                <label class="form-label" style="font-weight:700;color:#333">Classroom Title <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control" style="border-radius:12px;padding:12px" required placeholder="e.g. Physics Chapter 1: Introduction">
            </div>
            <div class="form-group" style="margin-bottom:20px">
                <label class="form-label" style="font-weight:700;color:#333">Thumbnail Image</label>
                <input type="file" name="thumbnail" class="form-control" accept="image/*" style="border-radius:12px;padding:10px">
                <small style="color:#666;margin-top:4px;display:block">Recommended ratio is 16:9 (e.g. 800x450px)</small>
            </div>
            <div class="form-group" style="margin-bottom:32px">
                <label class="form-label" style="font-weight:700;color:#333">Description (Optional)</label>
                <textarea name="description" class="form-control" style="border-radius:12px;padding:12px" rows="3" placeholder="What will be covered in this class?"></textarea>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:16px">
                <button type="button" class="btn btn-outline" style="border-radius:12px;font-weight:700" onclick="document.getElementById('createModal').classList.remove('show')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="border-radius:12px;font-weight:700;padding:10px 24px;box-shadow:0 8px 16px rgba(91, 33, 182,0.3)">Create Classroom</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>