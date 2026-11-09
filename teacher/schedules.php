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

// Get courses for this teacher
$courses = db_query("SELECT id, title_bn, title_en FROM courses WHERE teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=courses.id AND ct.teacher_id=?)", [$tpId, $tpId]);
$courseIds = array_column($courses, 'id');
if (empty($courseIds)) {
    $courseIds = [0];
}

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

        $redirUrl = APP_URL.'/teacher/schedules.php' . ($courseId ? '?course_id='.$courseId : '');

        if (!$title || !$courseId || !$datetime) {
            flash('error', 'Title, Course, and Date/Time are required.');
            redirect($redirUrl);
        }

        if (!in_array($courseId, $courseIds)) {
            flash('error', 'You do not have permission for this course.');
            redirect($redirUrl);
        }

        $formatted_dt = date('Y-m-d H:i:s', strtotime($datetime));

        if ($id) {
            db_exec("UPDATE schedules SET course_id=?, title=?, description=?, type=?, scheduled_at=? WHERE id=? AND course_id IN (".implode(',',$courseIds).")",
                [$courseId, $title, $desc, $type, $formatted_dt, $id]);
            flash('success', 'Schedule updated.');
        } else {
            db_insert("INSERT INTO schedules (course_id, title, description, type, scheduled_at) VALUES (?,?,?,?,?)",
                [$courseId, $title, $desc, $type, $formatted_dt]);
            flash('success', 'Schedule added.');
        }
        redirect($redirUrl);

    } elseif ($postAction === 'delete') {
        $id = sanitize_int($_POST['id'] ?? 0);
        $courseId = db_val("SELECT course_id FROM schedules WHERE id=?", [$id]);
        $redirUrl = APP_URL.'/teacher/schedules.php' . ($courseId ? '?course_id='.$courseId : '');
        db_exec("DELETE FROM schedules WHERE id=? AND course_id IN (".implode(',',$courseIds).")", [$id]);
        flash('success', 'Schedule deleted.');
        redirect($redirUrl);
    }
}

// Handle AJAX drag and drop update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    if (($input['action'] ?? '') === 'move_event') {
        $id = (int)($input['id'] ?? 0);
        $newStart = $input['start'] ?? '';
        if ($id && $newStart) {
            $formatted_dt = date('Y-m-d H:i:s', strtotime($newStart));
            db_exec("UPDATE schedules SET scheduled_at=? WHERE id=? AND course_id IN (".implode(',',$courseIds).")", [$formatted_dt, $id]);
            echo json_encode(['success' => true]);
            exit;
        }
    }
    echo json_encode(['success' => false]);
    exit;
}

$filterCourseId = sanitize_int($_GET['course_id'] ?? 0);
$whereCourse = $filterCourseId && in_array($filterCourseId, $courseIds) 
    ? "s.course_id = " . $filterCourseId 
    : "s.course_id IN (".implode(',',$courseIds).")";

$schedules = db_query("
    SELECT s.*, c.title_bn as course_title
    FROM schedules s
    JOIN courses c ON c.id = s.course_id
    WHERE $whereCourse
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
                $editData = db_row("SELECT * FROM schedules WHERE id=? AND course_id IN (".implode(',',$courseIds).")", [$editId]);
            }
        ?>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:var(--sp-6)">
            <a href="<?= APP_URL ?>/teacher/schedules.php" class="btn btn-ghost btn-sm"><span class="material-icons-round">arrow_back</span></a>
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
                    <?php 
                        $selected = '';
                        if ($editData && $editData['course_id'] == $c['id']) $selected = 'selected';
                        elseif (!$editData && $filterCourseId == $c['id']) $selected = 'selected';
                    ?>
                    <option value="<?= $c['id'] ?>" <?= $selected ?>><?= e($c['title_en']?:$c['title_bn']) ?></option>
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
                <?php
                    $defaultDate = '';
                    if ($editData) {
                        $defaultDate = date('Y-m-d\TH:i', strtotime($editData['scheduled_at']));
                    } elseif (isset($_GET['date'])) {
                        $defaultDate = sanitize_string($_GET['date']) . 'T10:00';
                    }
                ?>
                <input type="datetime-local" name="scheduled_at" class="form-control" required value="<?= $defaultDate ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Description (Optional)</label>
                <textarea name="description" class="form-control" rows="3"><?= e($editData['description'] ?? '') ?></textarea>
            </div>
            <div style="display:flex;gap:12px;margin-top:var(--sp-6)">
                <button type="submit" class="btn btn-primary">Save Schedule</button>
                <a href="<?= APP_URL ?>/teacher/schedules.php<?= $filterCourseId ? '?course_id='.$filterCourseId : '' ?>" class="btn btn-ghost">Cancel</a>
            </div>
        </form>

        <?php else: ?>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
            <h1 style="font-size:var(--text-2xl)">Schedules & Calendar</h1>
            <a href="<?= APP_URL ?>/teacher/schedules.php?action=add<?= $filterCourseId ? '&course_id='.$filterCourseId : '' ?>" class="btn btn-primary btn-sm">
                <span class="material-icons-round">add</span> Add Schedule
            </a>
        </div>

        <?php if (empty($schedules)): ?>
        <div style="text-align:center;padding:var(--sp-16);background:var(--c-surface);border-radius:var(--r-lg);border:2px dashed var(--c-border);margin-bottom:var(--sp-6)">
            <span class="material-icons-round" style="font-size:56px;color:var(--c-ink-faint)">event</span>
            <h3 style="margin:12px 0 8px;color:var(--c-ink-light)">No schedules found</h3>
        </div>
        <?php endif; ?>
        
        <div class="card" style="padding:var(--sp-5);background:var(--c-surface);">
            <div id="teacherCalendar"></div>
        </div>
        
        <style>
        /* Customizing FullCalendar */
        .fc-theme-standard td, .fc-theme-standard th, .fc-theme-standard .fc-scrollgrid { border-color: var(--c-border); }
        .fc-col-header-cell { background: var(--c-surface-alt); padding: 8px 0 !important; }
        .fc-col-header-cell-cushion { color: var(--c-ink-muted); font-weight: 600; text-decoration: none !important; }
        .fc-daygrid-day-number { color: var(--c-ink); text-decoration: none !important; font-weight: 600; margin: 4px; }
        .fc-event { border-radius: 4px; border: none; padding: 2px 4px; font-size: 11px; font-weight: 600; cursor: pointer; transition: transform 0.15s ease, box-shadow 0.15s ease; }
        .fc-event:hover { transform: translateY(-1px); box-shadow: var(--shadow-sm); filter: brightness(1.1); }
        .fc-button-primary { background: var(--c-primary) !important; border-color: var(--c-primary) !important; font-weight: 600 !important; text-transform: capitalize; border-radius: 6px !important; }
        .fc-button-primary:not(:disabled):active, .fc-button-primary:not(:disabled).fc-button-active { background: #4a2ec6 !important; }
        .fc-toolbar-title { font-size: 1.25rem !important; font-weight: 800 !important; color: var(--c-ink); }
        .fc-day-today { background-color: rgba(91, 33, 182, 0.05) !important; }
        </style>
        
        <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>
        <script>
        document.addEventListener('DOMContentLoaded', () => {
            const calendarEl = document.getElementById('teacherCalendar');
            if (calendarEl) {
                const eventsData = [
                    <?php foreach ($schedules as $s): 
                        $ts = strtotime($s['scheduled_at']);
                        $endTime = isset($s['end_time']) ? strtotime($s['end_time']) : $ts + 3600;
                        $color = match($s['type']) {
                            'live_class' => '#5B21B6',
                            'exam' => '#F59E0B',
                            default => '#10B981'
                        };
                    ?>
                    {
                        id: '<?= (int)$s['id'] ?>',
                        title: <?= json_encode($s['course_title'] . ': ' . $s['title']) ?>,
                        start: '<?= date('Y-m-d\TH:i:s', $ts) ?>',
                        end: '<?= date('Y-m-d\TH:i:s', $endTime) ?>',
                        backgroundColor: '<?= $color ?>',
                        borderColor: '<?= $color ?>',
                        extendedProps: {
                            description: <?= json_encode($s['description']) ?>,
                            type: <?= json_encode($s['type']) ?>,
                            courseTitle: <?= json_encode($s['course_title']) ?>
                        }
                    },
                    <?php endforeach; ?>
                ];

                const calendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek,listWeek'
                    },
                    events: eventsData,
                    height: 'auto',
                    editable: true, // Allow drag and drop
                    droppable: true,
                    selectable: true,
                    dateClick: function(info) {
                        // Redirect to add page with the selected date
                        const courseParam = <?= $filterCourseId ? "'&course_id=".$filterCourseId."'" : "''" ?>;
                        window.location.href = APP_URL + '/teacher/schedules.php?action=add&date=' + info.dateStr + courseParam;
                    },
                    eventClick: function(info) {
                        // Redirect to edit page
                        const courseParam = <?= $filterCourseId ? "'&course_id=".$filterCourseId."'" : "''" ?>;
                        window.location.href = APP_URL + '/teacher/schedules.php?action=edit&id=' + info.event.id + courseParam;
                    },
                    eventDrop: function(info) {
                        // AJAX call to update event time
                        fetch(APP_URL + '/teacher/schedules.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify({
                                action: 'move_event',
                                id: info.event.id,
                                start: info.event.startStr
                            })
                        }).then(r => r.json()).then(data => {
                            if (!data.success) {
                                info.revert();
                                alert('Failed to move event.');
                            }
                        }).catch(() => {
                            info.revert();
                            alert('An error occurred.');
                        });
                    }
                });
                
                calendar.render();
            }
        });
        </script>
        <?php endif; ?>
    </div>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>
