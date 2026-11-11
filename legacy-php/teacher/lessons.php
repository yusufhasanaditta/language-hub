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

$courseId = sanitize_int($_GET['course'] ?? 0);
$action   = sanitize_string($_GET['action'] ?? 'list', 20);
$editId   = sanitize_int($_GET['id'] ?? 0);

// Verify teacher owns this course (directly, as a co-teacher, or as admin)
$course = $courseId ? db_row("
    SELECT c.* FROM courses c
    WHERE c.id=? AND (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?) OR ?)
", [$courseId, $tpId, $tpId, is_admin() ? 1 : 0]) : null;
if (!$course && $courseId) { flash('error','Course not found.'); redirect(APP_URL.'/teacher/courses.php'); }
if (!$courseId) { flash('error','Please specify a course.'); redirect(APP_URL.'/teacher/courses.php'); }

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $postAction = sanitize_string($_POST['action'] ?? '', 20);

    if ($postAction === 'save') {
        $id          = sanitize_int($_POST['id'] ?? 0);
        $title       = sanitize_string($_POST['title'] ?? '', 255);
        $type        = in_array($_POST['type'] ?? '', ['video','text','quiz','live']) ? $_POST['type'] : 'video';
        $video_url   = sanitize_string($_POST['video_url'] ?? '', 500);
        $duration    = sanitize_int($_POST['duration'] ?? 0);
        $sort_order  = sanitize_int($_POST['sort_order'] ?? 0);
        $is_free     = isset($_POST['is_free']) ? 1 : 0;
        $content     = $_POST['content'] ?? '';

        if (!$title) { flash('error','Lesson title is required.'); redirect(APP_URL.'/teacher/lessons.php?course='.$courseId.'&action='.$action.($id?'&id='.$id:'')); }

        if ($id) {
            $owns = db_val("SELECT 1 FROM lessons l JOIN modules m ON l.module_id=m.id WHERE l.id=? AND m.course_id=?", [$id, $courseId]);
            if (!$owns) { flash('error','Permission denied.'); redirect(APP_URL.'/teacher/lessons.php?course='.$courseId); }
            db_exec("UPDATE lessons SET title=?,kind=?,video_url=?,duration=?,sort_order=?,is_free=?,content=? WHERE id=?",
                [$title,$type,$video_url,$duration,$sort_order,$is_free,$content,$id]);
            flash('success','Lesson updated.');
        } else {
            $maxOrder = (int)db_val("SELECT COALESCE(MAX(l.sort_order),0) FROM lessons l JOIN modules m ON l.module_id=m.id WHERE m.course_id=?", [$courseId]);
            $moduleId = (int)(db_val("SELECT id FROM modules WHERE course_id=? ORDER BY sort_order ASC LIMIT 1", [$courseId])
                ?: db_insert("INSERT INTO modules (course_id,title,sort_order) VALUES (?,'Curriculum',1)", [$courseId]));
            db_insert("INSERT INTO lessons (module_id,title,kind,video_url,duration,sort_order,is_free,content) VALUES (?,?,?,?,?,?,?,?)",
                [$moduleId,$title,$type,$video_url,$duration,$sort_order ?: $maxOrder+1,$is_free,$content]);
            // Update total_lessons count
            db_exec("UPDATE courses SET total_lessons=(SELECT COUNT(*) FROM lessons l JOIN modules m ON l.module_id=m.id WHERE m.course_id=?) WHERE id=?", [$courseId,$courseId]);
            flash('success','Lesson created.');
        }
        redirect(APP_URL.'/teacher/lessons.php?course='.$courseId);

    } elseif ($postAction === 'delete') {
        $id = sanitize_int($_POST['id'] ?? 0);
        $owns = db_val("SELECT 1 FROM lessons l JOIN modules m ON l.module_id=m.id WHERE l.id=? AND m.course_id=?", [$id, $courseId]);
        if ($owns) {
            db_exec("DELETE FROM lessons WHERE id=?", [$id]);
            db_exec("UPDATE courses SET total_lessons=(SELECT COUNT(*) FROM lessons l JOIN modules m ON l.module_id=m.id WHERE m.course_id=?) WHERE id=?", [$courseId,$courseId]);
            flash('success','Lesson deleted.');
        } else { flash('error','Permission denied.'); }
        redirect(APP_URL.'/teacher/lessons.php?course='.$courseId);

    } elseif ($postAction === 'reorder') {
        $ids = array_map('intval', (array)($_POST['order'] ?? []));
        foreach ($ids as $i => $lid) {
            db_exec("UPDATE lessons SET sort_order=? WHERE id=? AND module_id IN (SELECT id FROM modules WHERE course_id=?)", [$i+1, $lid, $courseId]);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok'=>true]);
        exit;
    }
}

$editLesson = null;
if (in_array($action, ['edit','add']) && $editId) {
    $editLesson = db_row("SELECT l.* FROM lessons l JOIN modules m ON l.module_id=m.id WHERE l.id=? AND m.course_id=?", [$editId, $courseId]);
}

$lessons = db_query("SELECT l.* FROM lessons l JOIN modules m ON l.module_id=m.id WHERE m.course_id=? ORDER BY l.sort_order ASC, l.id ASC", [$courseId]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Lessons — <?= APP_NAME ?></title>
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

        <!-- Breadcrumb -->
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:var(--sp-6);flex-wrap:wrap">
            <a href="<?= APP_URL ?>/teacher/courses.php" class="btn btn-ghost btn-sm">
                <span class="material-icons-round">arrow_back</span>
            </a>
            <span style="color:var(--c-ink-muted);font-size:var(--text-sm)">
                <a href="<?= APP_URL ?>/teacher/courses.php" style="color:var(--c-primary);text-decoration:none">My Courses</a>
                › <?= e($course['title_bn']) ?>
            </span>
            <?php if ($action === 'add' || $action === 'edit'): ?>
            <span style="color:var(--c-ink-muted);font-size:var(--text-sm)">
                › <?= $action==='edit' ? 'Edit Lesson' : 'New Lesson' ?>
            </span>
            <?php endif; ?>
        </div>

        <?php if ($action === 'add' || $action === 'edit'): ?>
        <!-- ── Add/Edit Form ── -->
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:var(--sp-6)">
            <h1 style="font-size:var(--text-2xl)"><?= $action==='edit' ? 'Edit Lesson' : 'New Lesson' ?></h1>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($editLesson['id'] ?? 0) ?>">
            <div style="display:grid;grid-template-columns:1fr 280px;gap:var(--sp-6)" class="lesson-form-grid">
                <div style="display:flex;flex-direction:column;gap:var(--sp-5)">
                    <div class="card" style="padding:var(--sp-6)">
                        <h3 style="margin-bottom:var(--sp-4)">Main Information</h3>
                        <div class="form-group">
                            <label class="form-label">Lesson Title *</label>
                            <input type="text" name="title" class="form-control" required value="<?= e($editLesson['title'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Video Link (YouTube/Vimeo)</label>
                            <input type="url" name="video_url" class="form-control" value="<?= e($editLesson['video_url'] ?? '') ?>" placeholder="https://youtube.com/watch?v=...">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Lesson Content (Text)</label>
                            <textarea name="content" class="form-control" rows="8"><?= e($editLesson['content'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;gap:var(--sp-5)">
                    <div class="card" style="padding:var(--sp-6)">
                        <h3 style="margin-bottom:var(--sp-4)">Settings</h3>
                        <div class="form-group">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-control">
                                <?php foreach (['video'=>'Video','text'=>'Text','quiz'=>'Quiz','live'=>'Live'] as $v=>$l): ?>
                                <option value="<?= $v ?>" <?= ($editLesson['kind']??'video')===$v?'selected':'' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Video Duration (seconds)</label>
                            <input type="number" name="duration" class="form-control" min="0" value="<?= (int)($editLesson['duration'] ?? 0) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Order Number</label>
                            <input type="number" name="sort_order" class="form-control" min="1" value="<?= (int)($editLesson['sort_order'] ?? count($lessons)+1) ?>">
                        </div>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                            <input type="checkbox" name="is_free" value="1" <?= !empty($editLesson['is_free'])?'checked':'' ?>>
                            <span style="font-weight:600">Free Preview</span>
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%">
                        <span class="material-icons-round">save</span>
                        <?= $action==='edit' ? 'Save Changes' : 'Create Lesson' ?>
                    </button>
                    <a href="<?= APP_URL ?>/teacher/lessons.php?course=<?= $courseId ?>" class="btn btn-ghost" style="width:100%;text-align:center">Cancel</a>
                </div>
            </div>
        </form>

        <?php else: ?>
        <!-- ── Lesson List ── -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
            <div>
                <h1 style="font-size:var(--text-2xl)">Lessons</h1>
                <p style="color:var(--c-ink-muted);margin:4px 0 0"><?= e($course['title_bn']) ?> · <?= count($lessons) ?> lessons</p>
            </div>
            <a href="<?= APP_URL ?>/teacher/lessons.php?course=<?= $courseId ?>&action=add" class="btn btn-primary btn-sm">
                <span class="material-icons-round">add</span> New Lesson
            </a>
        </div>

        <?php if (empty($lessons)): ?>
        <div style="text-align:center;padding:var(--sp-16);background:var(--c-surface);border-radius:var(--r-lg);border:2px dashed var(--c-border)">
            <span class="material-icons-round" style="font-size:56px;color:var(--c-ink-faint)">playlist_add</span>
            <h3 style="margin:12px 0 8px;color:var(--c-ink-light)">No lessons yet</h3>
            <a href="<?= APP_URL ?>/teacher/lessons.php?course=<?= $courseId ?>&action=add" class="btn btn-primary" style="margin-top:12px">Create First Lesson</a>
        </div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:var(--sp-3)" id="lessonList">
            <?php foreach ($lessons as $i => $l): ?>
            <div class="lesson-row" data-id="<?= (int)$l['id'] ?>"
                 style="display:flex;align-items:center;gap:var(--sp-4);background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--r-lg);padding:var(--sp-4)">
                <span class="material-icons-round" style="color:var(--c-border);cursor:grab;flex-shrink:0" title="Drag to reorder">drag_indicator</span>
                <div style="width:28px;height:28px;border-radius:50%;background:var(--c-primary-light);color:var(--c-primary);display:flex;align-items:center;justify-content:center;font-family:var(--font-latin);font-weight:800;font-size:var(--text-sm);flex-shrink:0">
                    <?= $i+1 ?>
                </div>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:700;font-size:var(--text-sm);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($l['title']) ?></div>
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:2px">
                        <?= ['video'=>'Video','text'=>'Text','quiz'=>'Quiz','live'=>'Live'][$l['kind']] ?? $l['kind'] ?>
                        <?php if ($l['duration']): ?>
                        · <?= gmdate('i:s', (int)$l['duration']) ?>
                        <?php endif; ?>
                        <?php if ($l['is_free']): ?>
                        · <span style="color:var(--c-success);font-weight:700">Free Preview</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="display:flex;gap:4px;flex-shrink:0">
                    <a href="<?= APP_URL ?>/teacher/lessons.php?course=<?= $courseId ?>&action=edit&id=<?= (int)$l['id'] ?>"
                       class="btn btn-ghost btn-sm" title="Edit">
                        <span class="material-icons-round" style="font-size:15px">edit</span>
                    </a>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this lesson?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--c-error)" title="Delete">
                            <span class="material-icons-round" style="font-size:15px">delete</span>
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p style="color:var(--c-ink-muted);font-size:var(--text-sm);margin-top:var(--sp-4)">
            <span class="material-icons-round" style="font-size:14px;vertical-align:middle">info</span>
            Drag lessons to reorder them.
        </p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<style>
@media(max-width:900px){.lesson-form-grid{grid-template-columns:1fr !important}}
.lesson-row.dragging{opacity:.5;border:2px dashed var(--c-primary) !important}
.lesson-row.drag-over{border-top:3px solid var(--c-primary) !important}
</style>
<script>
// Simple drag-to-reorder
const list = document.getElementById('lessonList');
if (list) {
    let dragged = null;
    list.querySelectorAll('.lesson-row').forEach(row => {
        row.draggable = true;
        row.addEventListener('dragstart', () => { dragged = row; row.classList.add('dragging'); });
        row.addEventListener('dragend', () => { row.classList.remove('dragging'); saveOrder(); });
        row.addEventListener('dragover', e => { e.preventDefault(); if (dragged !== row) row.classList.add('drag-over'); });
        row.addEventListener('dragleave', () => row.classList.remove('drag-over'));
        row.addEventListener('drop', e => {
            e.preventDefault();
            row.classList.remove('drag-over');
            if (dragged && dragged !== row) list.insertBefore(dragged, row);
        });
    });
    function saveOrder() {
        const ids = [...list.querySelectorAll('.lesson-row')].map(r => r.dataset.id);
        const fd = new FormData();
        fd.append('action','reorder');
        fd.append('csrf_token', CSRF_TOKEN);
        ids.forEach(id => fd.append('order[]', id));
        fetch('', {method:'POST', body:fd});
    }
}
</script>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
