<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $act = sanitize_string($_POST['action'] ?? '', 20);
    $eid = sanitize_int($_POST['enroll_id'] ?? 0);

    if ($act === 'manual_enroll') {
        $uid = sanitize_int($_POST['user_id'] ?? 0);
        $cid = sanitize_int($_POST['course_id'] ?? 0);
        if ($uid && $cid) {
            if (db_val("SELECT 1 FROM enrollments WHERE user_id=? AND course_id=?", [$uid, $cid])) {
                flash('error', 'This student is already enrolled in this course.');
            } else {
                db_insert("INSERT INTO enrollments (user_id,course_id,status,progress_pct,enrolled_at) VALUES (?,?,'active',0,NOW())", [$uid,$cid]);
                db_exec("UPDATE courses SET enrolled_count=COALESCE(enrolled_count,0)+1 WHERE id=?", [$cid]);
                $courseTitle = db_val("SELECT title_bn FROM courses WHERE id=?", [$cid]) ?? 'a course';
                create_notification(
                    $uid, 'enrollment', 'Enrolled in ' . $courseTitle,
                    'An administrator enrolled you in this course. You now have full access.',
                    APP_URL . '/learn.php?course=' . $cid
                );
                flash('success', 'Manual enrollment complete.');
            }
        }
    } elseif ($act === 'revoke' && $eid) {
        db_exec("UPDATE enrollments SET status='cancelled' WHERE id=?", [$eid]);
        flash('success', 'Enrollment cancelled.');
    } elseif ($act === 'reinstate' && $eid) {
        db_exec("UPDATE enrollments SET status='active' WHERE id=?", [$eid]);
        flash('success', 'Enrollment reactivated.');
    } elseif ($act === 'remove' && $eid) {
        $en = db_row("SELECT * FROM enrollments WHERE id=?", [$eid]);
        if ($en) {
            db_exec("DELETE FROM enrollments WHERE id=?", [$eid]);
            if ($en['status'] === 'active') {
                db_exec("UPDATE courses SET enrolled_count = GREATEST(0, enrolled_count - 1) WHERE id=?", [$en['course_id']]);
            }
            flash('success', 'Student permanently removed from course.');
        }
    }
    redirect(APP_URL . '/admin/enrollments.php');
}

$search  = sanitize_string($_GET['q'] ?? '', 100);
$cFilter = sanitize_int($_GET['course'] ?? 0);
$status  = sanitize_string($_GET['status'] ?? '', 20);
$page    = max(1, sanitize_int($_GET['page'] ?? 1));
$per     = 20;

$where  = ['1=1'];
$params = [];
if ($search) { $where[] = "(u.name LIKE ? OR u.phone LIKE ?)"; $params = array_merge($params, ['%'.$search.'%','%'.$search.'%']); }
if ($cFilter) { $where[] = 'e.course_id=?'; $params[] = $cFilter; }
if ($status && in_array($status, ['active','cancelled'])) { $where[] = 'e.status=?'; $params[] = $status; }
$wsql = 'WHERE '.implode(' AND ',$where);

$total = (int)db_val("SELECT COUNT(*) FROM enrollments e JOIN users u ON u.id=e.user_id $wsql", $params);
$offset = ($page-1)*$per;
$enrolls = db_query("
    SELECT e.*, u.name as user_name, u.phone, u.avatar,
           c.title_bn as course_title, c.type as course_type
    FROM enrollments e
    JOIN users u ON u.id=e.user_id
    JOIN courses c ON c.id=e.course_id
    $wsql
    ORDER BY e.enrolled_at DESC
    LIMIT $per OFFSET $offset
", $params);

$courses = db_query("SELECT id, title_bn FROM courses WHERE status='published' ORDER BY title_bn");
$users   = db_query("SELECT id, name, phone FROM users WHERE role_id=1 ORDER BY name LIMIT 500");

$totalActive = (int)db_val("SELECT COUNT(*) FROM enrollments WHERE status='active'");
$totalFree   = (int)db_val("SELECT COUNT(*) FROM enrollments e JOIN courses c ON c.id=e.course_id WHERE c.is_free=1 AND e.status='active'");
$completed   = (int)db_val("SELECT COUNT(*) FROM enrollments WHERE progress_pct=100 AND status='active'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Enrollments — <?= APP_NAME ?></title>
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

<!-- Manual enroll modal -->
<div id="meModal" style="display:none;position:fixed;inset:0;z-index:999;background:rgba(0,0,0,.6);align-items:center;justify-content:center;padding:20px">
    <div class="card" style="width:100%;max-width:420px;padding:var(--sp-7);position:relative">
        <button onclick="document.getElementById('meModal').style.display='none'" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer"><span class="material-icons-round">close</span></button>
        <h3 style="margin-bottom:var(--sp-5)">Manual Enrollment</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="manual_enroll">
            <div class="form-group">
                <label class="form-label">Student *</label>
                <select name="user_id" class="form-control" required>
                    <option value="">Choose</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?> (<?= e($u['phone']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Course *</label>
                <select name="course_id" class="form-control" required>
                    <option value="">Choose</option>
                    <?php foreach ($courses as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= e(mb_substr($c['title_bn'],0,50,'UTF-8')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Enroll</button>
        </form>
    </div>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6);flex-wrap:wrap;gap:12px">
    <h1 style="font-size:var(--text-2xl)">Enrollment Management</h1>
    <button onclick="document.getElementById('meModal').style.display='flex'" class="btn btn-primary btn-sm">
        <span class="material-icons-round">how_to_reg</span> Manual Enrollment
    </button>
</div>

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:var(--sp-4);margin-bottom:var(--sp-5)">
    <?php foreach ([['people','primary',$totalActive,'Total Active'],['school','success',$totalFree,'Free Courses'],['verified','accent',$completed,'Completed']] as [$i,$c,$v,$l]): ?>
    <div class="stat-card"><div class="stat-card-icon <?= $c ?>"><span class="material-icons-round"><?= $i ?></span></div><div><div class="stat-card-value"><?= number_format($v) ?></div><div class="stat-card-label"><?= $l ?></div></div></div>
    <?php endforeach; ?>
</div>

<form method="GET" style="display:flex;gap:var(--sp-3);margin-bottom:var(--sp-5);flex-wrap:wrap">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name or phone..." class="form-control" style="max-width:220px">
    <select name="course" class="form-control" style="width:auto" onchange="this.form.submit()">
        <option value="">All Courses</option>
        <?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $cFilter==(int)$c['id']?'selected':'' ?>><?= e(mb_substr($c['title_bn'],0,35,'UTF-8')) ?></option><?php endforeach; ?>
    </select>
    <select name="status" class="form-control" style="width:auto" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <option value="active" <?= $status==='active'?'selected':'' ?>>Active</option>
        <option value="cancelled" <?= $status==='cancelled'?'selected':'' ?>>Cancelled</option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><span class="material-icons-round">search</span></button>
    <?php if ($search||$cFilter||$status): ?><a href="<?= APP_URL ?>/admin/enrollments.php" class="btn btn-ghost btn-sm">Reset</a><?php endif; ?>
</form>

<div class="data-table-wrap">
<table class="data-table">
<thead><tr><th>#</th><th>Student</th><th>Course</th><th>Progress</th><th>Enrolled</th><th>Status</th><th>Action</th></tr></thead>
<tbody>
<?php if (empty($enrolls)): ?>
<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--c-ink-muted)">No enrollments yet</td></tr>
<?php else: foreach ($enrolls as $en): ?>
<tr>
    <td style="font-family:var(--font-latin);color:var(--c-ink-muted);font-size:var(--text-xs)"><?= (int)$en['id'] ?></td>
    <td>
        <div style="display:flex;align-items:center;gap:8px">
            <img src="<?= e(avatar_url($en['avatar']??'',$en['user_name'])) ?>" style="width:30px;height:30px;border-radius:50%">
            <div>
                <div style="font-weight:700;font-size:var(--text-sm)"><?= e($en['user_name']) ?></div>
                <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= e($en['phone']) ?></div>
            </div>
        </div>
    </td>
    <td style="font-size:var(--text-sm);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($en['course_title']) ?></td>
    <td>
        <div style="display:flex;align-items:center;gap:6px">
            <div style="width:60px;height:6px;background:var(--c-border);border-radius:3px;overflow:hidden">
                <div style="width:<?= (int)$en['progress_pct'] ?>%;height:100%;background:var(--c-primary);border-radius:3px"></div>
            </div>
            <span style="font-size:var(--text-xs);font-family:var(--font-latin)"><?= (int)$en['progress_pct'] ?>%</span>
        </div>
    </td>
    <td style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= date('d M Y', strtotime($en['enrolled_at'])) ?></td>
    <td><span class="status-badge <?= $en['status']==='active'?'status-active':'status-archived' ?>"><?= $en['status']==='active'?'Active':'Cancelled' ?></span></td>
    <td>
        <form method="POST" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $en['status']==='active'?'revoke':'reinstate' ?>">
            <input type="hidden" name="enroll_id" value="<?= (int)$en['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm" title="<?= $en['status']==='active'?'Cancel':'Reactivate' ?>">
                <span class="material-icons-round" style="font-size:15px"><?= $en['status']==='active'?'cancel':'check_circle' ?></span>
            </button>
        </form>
        <form method="POST" style="display:inline" onsubmit="return confirm('Are you sure you want to permanently remove this student from the course?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="enroll_id" value="<?= (int)$en['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm" title="Remove Completely" style="color:var(--c-error)">
                <span class="material-icons-round" style="font-size:15px">delete_forever</span>
            </button>
        </form>
    </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
<?= paginate($total, $page, $per, APP_URL.'/admin/enrollments.php?'.http_build_query(array_filter(['q'=>$search,'course'=>$cFilter,'status'=>$status,'page'=>'{page}']))) ?>
</div>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
