<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_admin();

$courses = db_query("SELECT id, title_bn, title_en FROM courses ORDER BY title_en, title_bn");

$action = sanitize_string($_GET['action'] ?? 'list', 20);
$editId = sanitize_int($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $postAction = sanitize_string($_POST['action'] ?? '', 20);

    if ($postAction === 'save') {
        $id = sanitize_int($_POST['id'] ?? 0);
        $courseId = sanitize_int($_POST['course_id'] ?? 0);
        $title = sanitize_string($_POST['title'] ?? '', 200);
        $desc = $_POST['description'] ?? '';
        $type = in_array($_POST['type'] ?? '', ['live_class','exam','general']) ? $_POST['type'] : 'general';
        $datetime = $_POST['scheduled_at'] ?? ''; // Format: YYYY-MM-DDTHH:MM

        if (!$title || !$courseId || !$datetime) {
            flash('error', 'Title, Course, and Date/Time are required.');
            redirect(APP_URL.'/admin/schedules.php');
        }

        $formatted_dt = date('Y-m-d H:i:s', strtotime($datetime));

        if ($id) {
            db_exec("UPDATE schedules SET course_id=?, title=?, description=?, type=?, scheduled_at=? WHERE id=?",
                [$courseId, $title, $desc, $type, $formatted_dt, $id]);
            flash('success', 'Schedule updated.');
        } else {
            db_insert("INSERT INTO schedules (course_id, title, description, type, scheduled_at) VALUES (?,?,?,?,?)",
                [$courseId, $title, $desc, $type, $formatted_dt]);
            flash('success', 'Schedule added.');
        }
        redirect(APP_URL.'/admin/schedules.php');

    } elseif ($postAction === 'delete') {
        $id = sanitize_int($_POST['id'] ?? 0);
        db_exec("DELETE FROM schedules WHERE id=?", [$id]);
        flash('success', 'Schedule deleted.');
        redirect(APP_URL.'/admin/schedules.php');
    }
}

$schedules = db_query("
    SELECT s.*, c.title_bn as course_title, tp.user_id as teacher_uid, u.name as teacher_name
    FROM schedules s
    JOIN courses c ON c.id = s.course_id
    JOIN teacher_profiles tp ON c.teacher_id = tp.id
    JOIN users u ON tp.user_id = u.id
    ORDER BY s.scheduled_at DESC
");

$_active = 'schedules.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Schedules — <?= APP_NAME ?></title>
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

        <?php if ($action === 'add' || $action === 'edit'):
            $editData = null;
            if ($action === 'edit' && $editId) {
                $editData = db_row("SELECT * FROM schedules WHERE id=?", [$editId]);
            }
        ?>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:var(--sp-6)">
            <a href="<?= APP_URL ?>/admin/schedules.php" class="btn btn-ghost btn-sm"><span class="material-icons-round">arrow_back</span></a>
            <h1 style="font-size:var(--text-2xl)"><?= $action==='edit' ? 'Edit Schedule' : 'New Schedule' ?></h1>
        </div>
        
        <form method="POST" class="card" style="max-width:600px;padding:var(--sp-6)">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($editData['id'] ?? 0) ?>">
            
            <div class="form-group">
                <label class="form-label">Course *</label>
                <select name="course_id" class="form-control" required>
                    <?php foreach ($courses as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= ($editData['course_id']??'')==$c['id']?'selected':'' ?>><?= e($c['title_en']?:$c['title_bn']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Title *</label>
                <input type="text" name="title" class="form-control" required value="<?= e($editData['title'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Type</label>
                <select name="type" class="form-control">
                    <option value="general" <?= ($editData['type']??'')=='general'?'selected':'' ?>>General Event</option>
                    <option value="live_class" <?= ($editData['type']??'')=='live_class'?'selected':'' ?>>Live Class</option>
                    <option value="exam" <?= ($editData['type']??'')=='exam'?'selected':'' ?>>Exam</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Date & Time *</label>
                <input type="datetime-local" name="scheduled_at" class="form-control" required value="<?= $editData ? date('Y-m-d\TH:i', strtotime($editData['scheduled_at'])) : '' ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Description (Optional)</label>
                <textarea name="description" class="form-control" rows="3"><?= e($editData['description'] ?? '') ?></textarea>
            </div>
            <div style="display:flex;gap:12px;margin-top:var(--sp-6)">
                <button type="submit" class="btn btn-primary">Save Schedule</button>
                <a href="<?= APP_URL ?>/admin/schedules.php" class="btn btn-ghost">Cancel</a>
            </div>
        </form>

        <?php else: ?>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
            <h1 style="font-size:var(--text-2xl)">Schedules & Calendar</h1>
            <a href="<?= APP_URL ?>/admin/schedules.php?action=add" class="btn btn-primary btn-sm">
                <span class="material-icons-round">add</span> Add Schedule
            </a>
        </div>

        <?php if (empty($schedules)): ?>
        <div style="text-align:center;padding:var(--sp-16);background:var(--c-surface);border-radius:var(--r-lg);border:2px dashed var(--c-border)">
            <span class="material-icons-round" style="font-size:56px;color:var(--c-ink-faint)">event</span>
            <h3 style="margin:12px 0 8px;color:var(--c-ink-light)">No schedules found</h3>
        </div>
        <?php else: ?>
        <div class="data-table-wrap">
            <table class="data-table">
                <thead><tr><th>Date & Time</th><th>Type</th><th>Course</th><th>Teacher</th><th>Title</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($schedules as $s): ?>
                <tr>
                    <td style="font-family:var(--font-latin);font-weight:600"><?= date('d M Y, h:i A', strtotime($s['scheduled_at'])) ?></td>
                    <td>
                        <span class="status-badge" style="background:var(--c-surface-hover);color:var(--c-ink)">
                            <?= ['live_class'=>'Live Class','exam'=>'Exam','general'=>'General'][$s['type']] ?>
                        </span>
                    </td>
                    <td><?= e($s['course_title']) ?></td>
                    <td><?= e($s['teacher_name']) ?></td>
                    <td style="font-weight:600"><?= e($s['title']) ?></td>
                    <td>
                        <div style="display:flex;gap:4px">
                            <a href="<?= APP_URL ?>/admin/schedules.php?action=edit&id=<?= $s['id'] ?>" class="btn btn-ghost btn-sm" title="Edit">
                                <span class="material-icons-round" style="font-size:15px">edit</span>
                            </a>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this schedule?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--c-error)">
                                    <span class="material-icons-round" style="font-size:15px">delete</span>
                                </button>
                            </form>
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
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>
