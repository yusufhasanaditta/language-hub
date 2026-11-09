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

$selCourse = sanitize_int($_GET['course'] ?? 0);

// Teacher's courses
$myCourses = db_query("SELECT id, title_bn FROM courses WHERE teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=courses.id AND ct.teacher_id=?) ORDER BY created_at DESC", [$tpId, $tpId]);
if (!$selCourse && !empty($myCourses)) $selCourse = (int)$myCourses[0]['id'];

// Verify ownership
if ($selCourse && !teacher_owns_course($selCourse, $tpId)) {
    flash('error', 'This course does not belong to you.');
    redirect(APP_URL . '/teacher/materials.php');
}

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $act = sanitize_string($_POST['action'] ?? '', 20);
    $cid = sanitize_int($_POST['course_id'] ?? 0);

    if (!$cid || !teacher_owns_course($cid, $tpId)) {
        flash('error', 'Course not found.');
        redirect(APP_URL . '/teacher/materials.php?course=' . $cid);
    }

    if ($act === 'add') {
        $title     = sanitize_string($_POST['title'] ?? '', 255);
        $fileUrl   = sanitize_string($_POST['file_url'] ?? '', 500);
        $fileType  = sanitize_string($_POST['file_type'] ?? 'other', 30);
        $lessonId  = sanitize_int($_POST['lesson_id'] ?? 0);
        $isFree    = isset($_POST['is_free']) ? 1 : 0;

        if (!$title || !$fileUrl) {
            flash('error', 'Title and link are required.');
        } elseif (!filter_var($fileUrl, FILTER_VALIDATE_URL)) {
            flash('error', 'Please enter a valid URL.');
        } else {
            $maxOrder = (int)db_val("SELECT COALESCE(MAX(sort_order),0)+1 FROM course_materials WHERE course_id=?", [$cid]);
            db_insert("INSERT INTO course_materials (course_id, lesson_id, title, file_url, file_type, sort_order, is_free)
                       VALUES (?,?,?,?,?,?,?)",
                [$cid, $lessonId ?: null, $title, $fileUrl, $fileType, $maxOrder, $isFree]);
            flash('success', 'Material added.');
        }
    }

    if ($act === 'delete') {
        $mid = sanitize_int($_POST['material_id'] ?? 0);
        $owns = db_val("SELECT 1 FROM course_materials WHERE id=? AND course_id=?", [$mid, $cid]);
        if ($owns) {
            db_exec("DELETE FROM course_materials WHERE id=?", [$mid]);
            flash('success', 'Material deleted.');
        }
    }

    if ($act === 'toggle_free') {
        $mid = sanitize_int($_POST['material_id'] ?? 0);
        $owns = db_val("SELECT 1 FROM course_materials WHERE id=? AND course_id=?", [$mid, $cid]);
        if ($owns) {
            db_exec("UPDATE course_materials SET is_free=1-is_free WHERE id=?", [$mid]);
        }
    }

    redirect(APP_URL . '/teacher/materials.php?course=' . $cid);
}

// Load materials for selected course
$materials = $selCourse ? db_query("
    SELECT cm.*, l.title as lesson_title
    FROM course_materials cm
    LEFT JOIN lessons l ON l.id = cm.lesson_id
    WHERE cm.course_id=?
    ORDER BY cm.sort_order ASC, cm.id ASC
", [$selCourse]) : [];

// Load lessons for the selected course (for associating material to lesson)
$lessons = $selCourse ? db_query("SELECT l.id, l.title FROM lessons l JOIN modules m ON l.module_id=m.id WHERE m.course_id=? AND l.is_published=1 ORDER BY l.sort_order ASC", [$selCourse]) : [];

$selCourseName = $selCourse ? db_val("SELECT title_bn FROM courses WHERE id=?", [$selCourse]) : '';

$_active = 'materials.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Course Materials — <?= APP_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@700;800&family=Hind+Siliguri:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<script>const APP_URL='<?= APP_URL ?>';const CSRF_TOKEN='<?= csrf_token() ?>';</script>
</head>
<body>
<div class="admin-layout">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="admin-content">

<?php foreach (get_flash() as $f): ?>
<div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:16px">
    <span class="material-icons-round"><?= $f['type']==='success'?'check_circle':'error' ?></span>
    <span><?= e($f['message']) ?></span>
</div>
<?php endforeach; ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-5)">
    <div>
        <h1 style="font-size:var(--text-2xl);margin-bottom:4px">Course Materials</h1>
        <p style="color:var(--c-ink-muted);font-size:var(--text-sm);margin:0">Add PDFs, notes, and links for students</p>
    </div>
</div>

<!-- Course selector -->
<?php if (empty($myCourses)): ?>
<div style="text-align:center;padding:var(--sp-16);background:var(--c-surface);border-radius:var(--r-xl);border:2px dashed var(--c-border)">
    <span class="material-icons-round" style="font-size:48px;color:var(--c-ink-faint);display:block;margin-bottom:12px">folder_open</span>
    <h3>No Courses Yet</h3>
    <p style="color:var(--c-ink-muted)">Create a course first.</p>
    <a href="<?= APP_URL ?>/teacher/courses.php" class="btn btn-primary" style="margin-top:12px">Create Course</a>
</div>
<?php else: ?>

<div style="display:grid;grid-template-columns:260px 1fr;gap:var(--sp-6);align-items:start">

    <!-- Course list -->
    <div class="card" style="padding:var(--sp-4);position:sticky;top:calc(var(--nav-h,60px)+16px)">
        <div style="font-size:var(--text-xs);font-weight:700;color:var(--c-ink-muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:var(--sp-3)">My Courses</div>
        <?php foreach ($myCourses as $c): ?>
        <a href="?course=<?= (int)$c['id'] ?>"
           class="admin-nav-item <?= $selCourse===(int)$c['id']?'active':'' ?>"
           style="border-radius:var(--r-md);margin-bottom:4px;font-size:var(--text-sm);padding:10px 12px">
            <span class="material-icons-round" style="font-size:18px">folder</span>
            <?= e(mb_substr($c['title_bn'], 0, 30, 'UTF-8')) ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Materials panel -->
    <div>
        <?php if ($selCourse): ?>

        <div class="card" style="padding:var(--sp-6);margin-bottom:var(--sp-5)">
            <h3 style="margin-bottom:var(--sp-5)">
                <span class="material-icons-round" style="vertical-align:middle;color:var(--c-primary)">add_circle</span>
                Add New Material
            </h3>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="course_id" value="<?= $selCourse ?>">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)">
                    <div class="form-group" style="grid-column:1/-1">
                        <label class="form-label">Title *</label>
                        <input type="text" name="title" class="form-control" required placeholder="e.g. Chapter 3 — Note Sheet">
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label class="form-label">File / Link URL *</label>
                        <input type="url" name="file_url" class="form-control" required
                               placeholder="https://drive.google.com/file/... or https://..."
                               style="font-family:var(--font-latin)">
                        <div class="form-hint">Enter a Google Drive share link, YouTube link, or direct PDF URL.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">File Type</label>
                        <select name="file_type" class="form-control">
                            <option value="pdf">PDF</option>
                            <option value="video">Video</option>
                            <option value="doc">Word/Doc</option>
                            <option value="image">Image</option>
                            <option value="link">Link</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Attach to Lesson (optional)</label>
                        <select name="lesson_id" class="form-control">
                            <option value="">— No lesson (general) —</option>
                            <?php foreach ($lessons as $ls): ?>
                            <option value="<?= (int)$ls['id'] ?>"><?= e(mb_substr($ls['title'], 0, 40, 'UTF-8')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="display:flex;align-items:center;gap:var(--sp-5);margin-top:var(--sp-3)">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" name="is_free" value="1" class="form-check-input">
                        <span style="font-size:var(--text-sm);font-weight:600;color:var(--c-ink)">
                            Free access (visible without enrollment)
                        </span>
                    </label>
                    <button type="submit" class="btn btn-primary">
                        <span class="material-icons-round">add</span> Add
                    </button>
                </div>
            </form>
        </div>

        <!-- Material list -->
        <div class="card" style="padding:var(--sp-5)">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-4)">
                <h3 style="margin:0">Material List <span style="font-family:var(--font-latin);font-weight:600;color:var(--c-ink-muted);font-size:var(--text-base)">(<?= count($materials) ?>)</span></h3>
            </div>

            <?php if (empty($materials)): ?>
            <div style="text-align:center;padding:var(--sp-10);color:var(--c-ink-muted)">
                <span class="material-icons-round" style="font-size:40px;display:block;margin-bottom:8px">folder_open</span>
                No materials in this course yet.
            </div>
            <?php else: ?>
            <div class="data-table-wrap">
            <table class="data-table">
            <thead><tr><th>#</th><th>Title</th><th>Type</th><th>Lesson</th><th>Access</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($materials as $i => $mat):
                $typeIcon = match($mat['file_type']) {
                    'pdf'   => 'picture_as_pdf',
                    'video' => 'play_circle',
                    'doc'   => 'description',
                    'image' => 'image',
                    'link'  => 'link',
                    default => 'attach_file',
                };
                $typeColor = match($mat['file_type']) {
                    'pdf'   => '#EF4444',
                    'video' => '#8B5CF6',
                    'doc'   => '#3B82F6',
                    default => 'var(--c-ink-muted)',
                };
            ?>
            <tr>
                <td style="font-family:var(--font-latin);color:var(--c-ink-muted);font-size:var(--text-xs)"><?= $i+1 ?></td>
                <td>
                    <a href="<?= e($mat['file_url']) ?>" target="_blank" rel="noopener"
                       style="display:flex;align-items:center;gap:8px;font-weight:600;font-size:var(--text-sm);color:var(--c-ink);text-decoration:none">
                        <span class="material-icons-round" style="font-size:18px;color:<?= $typeColor ?>;flex-shrink:0"><?= $typeIcon ?></span>
                        <?= e($mat['title']) ?>
                        <span class="material-icons-round" style="font-size:13px;color:var(--c-ink-faint)">open_in_new</span>
                    </a>
                </td>
                <td><span style="font-size:var(--text-xs);font-weight:700;color:<?= $typeColor ?>;text-transform:uppercase"><?= e($mat['file_type']) ?></span></td>
                <td style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= $mat['lesson_title'] ? e(mb_substr($mat['lesson_title'], 0, 25, 'UTF-8')) : '—' ?></td>
                <td>
                    <form method="POST" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_free">
                        <input type="hidden" name="course_id" value="<?= $selCourse ?>">
                        <input type="hidden" name="material_id" value="<?= (int)$mat['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm" style="font-size:11px;color:<?= $mat['is_free']?'var(--c-success)':'var(--c-ink-muted)' ?>;padding:3px 8px">
                            <span class="material-icons-round" style="font-size:14px"><?= $mat['is_free']?'lock_open':'lock' ?></span>
                            <?= $mat['is_free'] ? 'Free' : 'Paid' ?>
                        </button>
                    </form>
                </td>
                <td>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this material?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="course_id" value="<?= $selCourse ?>">
                        <input type="hidden" name="material_id" value="<?= (int)$mat['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--c-error)">
                            <span class="material-icons-round" style="font-size:16px">delete</span>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

</div>
</div>

<style>
@media(max-width:900px){
    .admin-layout > .admin-content > div > div[style*="grid-template-columns:260px"]{
        grid-template-columns:1fr !important;
    }
}
</style>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
