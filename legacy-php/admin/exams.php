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
    $eid = sanitize_int($_POST['exam_id'] ?? 0);

    if ($act === 'toggle_status' && $eid) {
        $cur = db_val("SELECT status FROM exams WHERE id=?", [$eid]);
        $new = $cur === 'published' ? 'draft' : 'published';
        db_exec("UPDATE exams SET status=? WHERE id=?", [$new, $eid]);
        flash('success', 'Exam status changed.');
    } elseif ($act === 'delete' && $eid) {
        db_exec("DELETE FROM exam_attempt_answers WHERE attempt_id IN (SELECT id FROM exam_attempts WHERE exam_id=?)", [$eid]);
        db_exec("DELETE FROM exam_leaderboard WHERE exam_id=?", [$eid]);
        db_exec("DELETE FROM exam_attempts WHERE exam_id=?", [$eid]);
        db_exec("DELETE FROM exam_questions WHERE exam_id=?", [$eid]);
        db_exec("DELETE FROM exams WHERE id=?", [$eid]);
        flash('success', 'Exam deleted.');
    }
    redirect(APP_URL . '/admin/exams.php');
}

$search  = sanitize_string($_GET['q'] ?? '', 100);
$page    = max(1, sanitize_int($_GET['page'] ?? 1));
$per     = 20;
$wparams = [];
$wsql    = '';
if ($search) {
    $wsql    = "WHERE (ex.title LIKE ? OR c.title_bn LIKE ?)";
    $s       = '%' . $search . '%';
    $wparams = [$s, $s];
}
$total   = (int)db_val("SELECT COUNT(*) FROM exams ex LEFT JOIN courses c ON c.id=ex.course_id $wsql", $wparams);
$offset  = ($page-1)*$per;
$exams   = db_query("
    SELECT ex.*, c.title_bn as course_title,
           u.name as teacher_name,
           (SELECT COUNT(*) FROM exam_questions WHERE exam_id=ex.id) as q_count,
           (SELECT COUNT(*) FROM exam_attempts WHERE exam_id=ex.id AND status='submitted') as attempt_count
    FROM exams ex
    LEFT JOIN courses c ON c.id=ex.course_id
    LEFT JOIN teacher_profiles tp ON tp.id=c.teacher_id
    LEFT JOIN users u ON u.id=tp.user_id
    $wsql
    ORDER BY ex.created_at DESC
    LIMIT $per OFFSET $offset
", $wparams);

$totalPublished = (int)db_val("SELECT COUNT(*) FROM exams WHERE status='published'");
$totalAttempts  = (int)db_val("SELECT COUNT(*) FROM exam_attempts WHERE status='submitted'");
$freeExams      = (int)db_val("SELECT COUNT(*) FROM exams WHERE is_free=1 AND status='published'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Exams — <?= APP_NAME ?></title>
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

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
    <h1 style="font-size:var(--text-2xl)">Exam Management</h1>
</div>

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:var(--sp-4);margin-bottom:var(--sp-6)">
    <?php foreach ([['quiz','primary',$totalPublished,'Published'],['school','success',$freeExams,'Free Exams'],['people','accent',$totalAttempts,'Total Attempts']] as [$i,$c,$v,$l]): ?>
    <div class="stat-card"><div class="stat-card-icon <?= $c ?>"><span class="material-icons-round"><?= $i ?></span></div><div><div class="stat-card-value"><?= number_format($v) ?></div><div class="stat-card-label"><?= $l ?></div></div></div>
    <?php endforeach; ?>
</div>

<form method="GET" style="display:flex;gap:var(--sp-3);margin-bottom:var(--sp-5)">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Exam name..." class="form-control" style="max-width:280px">
    <button type="submit" class="btn btn-primary btn-sm"><span class="material-icons-round">search</span></button>
    <?php if ($search): ?><a href="<?= APP_URL ?>/admin/exams.php" class="btn btn-ghost btn-sm">Reset</a><?php endif; ?>
</form>

<div class="data-table-wrap">
<table class="data-table">
<thead><tr><th>#</th><th>Exam</th><th>Course/Teacher</th><th>Questions</th><th>Attempts</th><th>Type</th><th>Status</th><th>Action</th></tr></thead>
<tbody>
<?php if (empty($exams)): ?>
<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--c-ink-muted)">No exams yet</td></tr>
<?php else: foreach ($exams as $ex): ?>
<tr>
    <td style="font-family:var(--font-latin);color:var(--c-ink-muted)"><?= (int)$ex['id'] ?></td>
    <td>
        <div style="font-weight:700"><?= e($ex['title']) ?></div>
        <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= $ex['start_at'] ? date('d M Y', strtotime($ex['start_at'])) : '—' ?></div>
    </td>
    <td>
        <div style="font-size:var(--text-sm)"><?= e(mb_substr($ex['course_title']??'—',0,30,'UTF-8')) ?></div>
        <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= e($ex['teacher_name']??'') ?></div>
    </td>
    <td style="font-family:var(--font-latin);font-weight:700"><?= (int)$ex['q_count'] ?></td>
    <td style="font-family:var(--font-latin)"><?= number_format((int)$ex['attempt_count']) ?></td>
    <td>
        <?php if ($ex['is_free']): ?>
        <span class="status-badge status-active">Free</span>
        <?php endif; ?>
        <?php if (($ex['exam_type'] ?? 'mcq') === 'cq'): ?>
        <span class="status-badge status-pending">CQ</span>
        <?php else: ?>
        <span class="status-badge status-draft">MCQ</span>
        <?php endif; ?>
    </td>
    <td>
        <span class="status-badge <?= $ex['status']==='published'?'status-active':'status-draft' ?>">
            <?= $ex['status']==='published'?'Published':'Draft' ?>
        </span>
    </td>
    <td>
        <div style="display:flex;gap:6px">
            <form method="POST" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="exam_id" value="<?= (int)$ex['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" title="Change status">
                    <span class="material-icons-round" style="font-size:15px"><?= $ex['status']==='published'?'unpublished':'publish' ?></span>
                </button>
            </form>
            <a href="<?= APP_URL ?>/exams.php?exam=<?= (int)$ex['id'] ?>" class="btn btn-ghost btn-sm" target="_blank">
                <span class="material-icons-round" style="font-size:15px">open_in_new</span>
            </a>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this exam?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="exam_id" value="<?= (int)$ex['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--c-error)">
                    <span class="material-icons-round" style="font-size:15px">delete</span>
                </button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
<?= paginate($total, $page, $per, APP_URL.'/admin/exams.php?'.http_build_query(array_filter(['q'=>$search,'page'=>'{page}']))) ?>
</div>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
