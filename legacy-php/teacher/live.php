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

    if ($postAction === 'save') {
        $id          = sanitize_int($_POST['id'] ?? 0);
        $title       = sanitize_string($_POST['title'] ?? '', 255);
        $courseId    = sanitize_int($_POST['course_id'] ?? 0);
        $scheduled   = sanitize_string($_POST['scheduled_at'] ?? '', 30);
        $duration    = sanitize_int($_POST['duration_minutes'] ?? 60);
        $meet_url    = sanitize_string($_POST['meeting_url'] ?? '', 500);
        $description = sanitize_string($_POST['description'] ?? '', 1000);
        $status      = in_array($_POST['status'] ?? '', ['scheduled','live','ended','cancelled']) ? $_POST['status'] : 'scheduled';

        if (!$title || !$courseId || !$scheduled) { flash('error','Title, course, and time are required.'); redirect(APP_URL.'/teacher/live.php?action='.($id?'edit&id='.$id:'schedule')); }

        $ownsCourse = teacher_owns_course($courseId, $tpId);
        if (!$ownsCourse) { flash('error','You do not have permission for this course.'); redirect(APP_URL.'/teacher/live.php'); }

        $rec_url = sanitize_string($_POST['recording_url'] ?? '', 500);
        if ($id) {
            $owns = teacher_owns_live_session($id, $tpId);
            if (!$owns) { flash('error','Permission denied.'); redirect(APP_URL.'/teacher/live.php'); }
            db_exec("UPDATE live_sessions SET title=?,course_id=?,scheduled_at=?,duration_minutes=?,meeting_url=?,description=?,status=?,recording_url=?,updated_at=NOW() WHERE id=?",
                [$title,$courseId,$scheduled,$duration,$meet_url,$description,$status,$rec_url,$id]);
            flash('success','Live class updated.');
        } else {
            db_insert("INSERT INTO live_sessions (title,course_id,scheduled_at,duration_minutes,meeting_url,description,status,recording_url,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())",
                [$title,$courseId,$scheduled,$duration,$meet_url,$description,$status,$rec_url]);
            $courseInfo = db_row("SELECT title_bn, slug FROM courses WHERE id=?", [$courseId]);
            notify_course_students(
                $courseId, 'live_class', 'New live class scheduled: ' . $title,
                ($courseInfo['title_bn'] ?? 'your course') . ' — ' . date('d M Y, h:i A', strtotime($scheduled)),
                APP_URL . '/course.php?slug=' . urlencode($courseInfo['slug'] ?? '')
            );
            flash('success','Live class scheduled.');
        }
        redirect(APP_URL.'/teacher/live.php');

    } elseif ($postAction === 'delete') {
        $id = sanitize_int($_POST['id'] ?? 0);
        $owns = teacher_owns_live_session($id, $tpId);
        if ($owns) { db_exec("DELETE FROM live_sessions WHERE id=?", [$id]); flash('success','Live class cancelled.'); }
        else { flash('error','Permission denied.'); }
        redirect(APP_URL.'/teacher/live.php');

    } elseif ($postAction === 'go_live') {
        $id = sanitize_int($_POST['id'] ?? 0);
        $owns = teacher_owns_live_session($id, $tpId);
        if ($owns) { db_exec("UPDATE live_sessions SET status='live',started_at=NOW() WHERE id=?", [$id]); flash('success','Live class started!'); }
        redirect(APP_URL.'/teacher/live.php');

    } elseif ($postAction === 'end_live') {
        $id = sanitize_int($_POST['id'] ?? 0);
        $owns = teacher_owns_live_session($id, $tpId);
        if ($owns) { db_exec("UPDATE live_sessions SET status='ended',ended_at=NOW() WHERE id=?", [$id]); flash('success','Live class ended.'); }
        redirect(APP_URL.'/teacher/live.php');
    }
}

$myCourses = db_query("SELECT id,title_bn FROM courses WHERE (teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=courses.id AND ct.teacher_id=?)) AND status='published' ORDER BY title_bn", [$tpId, $tpId]);
$editSession = null;
if (in_array($action, ['edit','schedule']) && $editId) {
    $editSession = db_row("SELECT ls.*,c.title_bn as course_title FROM live_sessions ls JOIN courses c ON c.id=ls.course_id WHERE ls.id=? AND (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))", [$editId,$tpId,$tpId]);
}

// Sessions grouped
$upcoming = db_query("SELECT ls.*,c.title_bn as course_title FROM live_sessions ls JOIN courses c ON c.id=ls.course_id WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)) AND ls.status IN ('scheduled','live') ORDER BY ls.scheduled_at ASC", [$tpId, $tpId]);
$past     = db_query("SELECT ls.*,c.title_bn as course_title FROM live_sessions ls JOIN courses c ON c.id=ls.course_id WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)) AND ls.status IN ('ended','cancelled') ORDER BY ls.scheduled_at DESC LIMIT 20", [$tpId, $tpId]);

function time_ago(string $dt): string { $d=time()-strtotime($dt); if($d<60)return 'just now'; if($d<3600)return intdiv($d,60).'m ago'; if($d<86400)return intdiv($d,3600).'h ago'; return date('d M Y',strtotime($dt)); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Live Classes — <?= APP_NAME ?></title>
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

        <?php if ($action === 'schedule' || $action === 'edit'): ?>
        <!-- ── Schedule Form ── -->
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:var(--sp-6)">
            <a href="<?= APP_URL ?>/teacher/live.php" class="btn btn-ghost btn-sm"><span class="material-icons-round">arrow_back</span></a>
            <h1 style="font-size:var(--text-2xl)"><?= $action==='edit' ? 'Edit Live Class' : 'Schedule Live Class' ?></h1>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($editSession['id'] ?? 0) ?>">
            <div style="display:grid;grid-template-columns:1fr 280px;gap:var(--sp-6)" class="live-form-grid">
                <div class="card" style="padding:var(--sp-6)">
                    <h3 style="margin-bottom:var(--sp-4)">Main Information</h3>
                    <div class="form-group">
                        <label class="form-label">Class Title *</label>
                        <input type="text" name="title" class="form-control" required value="<?= e($editSession['title'] ?? '') ?>" placeholder="e.g. Physics Live Class — Chapter 3">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Course *</label>
                        <select name="course_id" class="form-control" required>
                            <option value="">Select</option>
                            <?php foreach ($myCourses as $mc): ?>
                            <option value="<?= (int)$mc['id'] ?>" <?= ($editSession['course_id']??0)==$mc['id']?'selected':'' ?>><?= e($mc['title_bn']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)">
                        <div class="form-group">
                            <label class="form-label">Date & Time *</label>
                            <input type="datetime-local" name="scheduled_at" class="form-control" required
                                   value="<?= $editSession['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($editSession['scheduled_at'])) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Duration (minutes)</label>
                            <input type="number" name="duration_minutes" class="form-control" min="15" value="<?= (int)($editSession['duration_minutes'] ?? 60) ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Meeting Link (Zoom/Google Meet)</label>
                        <input type="url" name="meeting_url" class="form-control" value="<?= e($editSession['meeting_url'] ?? '') ?>" placeholder="https://meet.google.com/...">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Recording Link (YouTube)</label>
                        <input type="url" name="recording_url" class="form-control" value="<?= e($editSession['recording_url'] ?? '') ?>" placeholder="https://youtu.be/... or YouTube private link">
                        <small style="color:var(--c-ink-muted)">Add the YouTube link after class ends — only enrolled students will be able to watch</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="4"><?= e($editSession['description'] ?? '') ?></textarea>
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;gap:var(--sp-5)">
                    <div class="card" style="padding:var(--sp-6)">
                        <h3 style="margin-bottom:var(--sp-4)">Status</h3>
                        <select name="status" class="form-control">
                            <?php foreach (['scheduled'=>'Scheduled','live'=>'Live','ended'=>'Ended','cancelled'=>'Cancelled'] as $v=>$l): ?>
                            <option value="<?= $v ?>" <?= ($editSession['status']??'scheduled')===$v?'selected':'' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%">
                        <span class="material-icons-round">save</span>
                        <?= $action==='edit' ? 'Save' : 'Schedule' ?>
                    </button>
                    <a href="<?= APP_URL ?>/teacher/live.php" class="btn btn-ghost" style="width:100%;text-align:center">Cancel</a>
                </div>
            </div>
        </form>

        <?php else: ?>
        <!-- ── List ── -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
            <h1 style="font-size:var(--text-2xl)">Live Classes</h1>
            <a href="<?= APP_URL ?>/teacher/live.php?action=schedule" class="btn btn-primary btn-sm">
                <span class="material-icons-round">add</span> Schedule Live Class
            </a>
        </div>

        <!-- Upcoming/Live -->
        <h3 style="font-size:var(--text-lg);margin-bottom:var(--sp-4)">Upcoming & Ongoing</h3>
        <?php if (empty($upcoming)): ?>
        <div style="text-align:center;padding:var(--sp-12);background:var(--c-surface);border-radius:var(--r-lg);border:2px dashed var(--c-border);margin-bottom:var(--sp-8)">
            <span class="material-icons-round" style="font-size:48px;color:var(--c-ink-faint)">live_tv</span>
            <h4 style="margin:12px 0 8px;color:var(--c-ink-light)">No upcoming live classes</h4>
            <a href="<?= APP_URL ?>/teacher/live.php?action=schedule" class="btn btn-primary" style="margin-top:8px">Schedule</a>
        </div>
        <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:var(--sp-4);margin-bottom:var(--sp-8)">
            <?php foreach ($upcoming as $ls): ?>
            <div style="border-radius:var(--r-lg);overflow:hidden;border:1px solid var(--c-border);">
                <div style="background:<?= $ls['status']==='live' ? 'linear-gradient(135deg,#e53935,#ef6c00)' : 'linear-gradient(135deg,#5B21B6,#7C3AED)' ?>;padding:var(--sp-5);color:#fff">
                    <?php if ($ls['status']==='live'): ?>
                    <div style="display:inline-flex;align-items:center;gap:4px;background:rgba(255,255,255,.2);border-radius:999px;padding:2px 10px;font-size:11px;font-weight:800;margin-bottom:8px">
                        <span style="width:6px;height:6px;border-radius:50%;background:#fff;animation:pulse 1s infinite"></span> LIVE
                    </div>
                    <?php endif; ?>
                    <div style="font-weight:800;font-size:var(--text-base);margin-bottom:4px"><?= e($ls['title']) ?></div>
                    <div style="font-size:var(--text-xs);opacity:.8"><?= e($ls['course_title']) ?></div>
                    <div style="margin-top:10px;font-size:var(--text-sm);display:flex;align-items:center;gap:6px">
                        <span class="material-icons-round" style="font-size:16px">schedule</span>
                        <?= date('d M Y, g:i A', strtotime($ls['scheduled_at'])) ?>
                        · <?= (int)$ls['duration_minutes'] ?> min
                    </div>
                </div>
                <div style="background:var(--c-surface-2);padding:var(--sp-4);display:flex;gap:var(--sp-2);flex-wrap:wrap">
                    <?php if ($ls['meeting_url']): ?>
                    <a href="<?= e($ls['meeting_url']) ?>" target="_blank" class="btn btn-primary btn-sm">
                        <span class="material-icons-round" style="font-size:14px">videocam</span>
                        <?= $ls['status']==='live' ? 'Join' : 'Meeting Link' ?>
                    </a>
                    <?php endif; ?>
                    <?php if ($ls['status']==='scheduled'): ?>
                    <form method="POST" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="go_live">
                        <input type="hidden" name="id" value="<?= (int)$ls['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--c-success)">
                            <span class="material-icons-round" style="font-size:14px">live_tv</span> Start
                        </button>
                    </form>
                    <?php elseif ($ls['status']==='live'): ?>
                    <form method="POST" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="end_live">
                        <input type="hidden" name="id" value="<?= (int)$ls['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--c-error)">
                            <span class="material-icons-round" style="font-size:14px">stop_circle</span> End
                        </button>
                    </form>
                    <?php endif; ?>
                    <a href="<?= APP_URL ?>/teacher/live.php?action=edit&id=<?= (int)$ls['id'] ?>" class="btn btn-ghost btn-sm">
                        <span class="material-icons-round" style="font-size:14px">edit</span>
                    </a>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this live class?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$ls['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--c-error)">
                            <span class="material-icons-round" style="font-size:14px">delete</span>
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Past sessions -->
        <?php if (!empty($past)): ?>
        <h3 style="font-size:var(--text-lg);margin-bottom:var(--sp-4)">Past Classes</h3>
        <div class="data-table-wrap">
            <table class="data-table">
                <thead><tr><th>Title</th><th>Course</th><th>Date</th><th>Status</th><th>Recording</th></tr></thead>
                <tbody>
                    <?php foreach ($past as $ls): ?>
                    <tr>
                        <td style="font-weight:600;font-size:var(--text-sm)"><?= e($ls['title']) ?></td>
                        <td style="font-size:var(--text-sm);color:var(--c-ink-muted)"><?= e($ls['course_title']) ?></td>
                        <td style="font-size:var(--text-sm);color:var(--c-ink-muted)"><?= date('d M Y, g:i A', strtotime($ls['scheduled_at'])) ?></td>
                        <td>
                            <span class="status-badge <?= $ls['status']==='ended'?'status-archived':'status-draft' ?>">
                                <?= $ls['status']==='ended' ? 'Ended' : 'Cancelled' ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($ls['recording_url'])): ?>
                            <a href="<?= e($ls['recording_url']) ?>" target="_blank" class="btn btn-ghost btn-sm" style="color:var(--c-error)">
                                <span class="material-icons-round" style="font-size:15px">smart_display</span> Recording
                            </a>
                            <?php else: ?>
                            <a href="<?= APP_URL ?>/teacher/live.php?action=edit&id=<?= (int)$ls['id'] ?>" class="btn btn-ghost btn-sm" style="font-size:11px">+ Add Recording</a>
                            <?php endif; ?>
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
@media(max-width:900px){.live-form-grid{grid-template-columns:1fr !important}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
</style>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
