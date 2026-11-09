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
    $sid = sanitize_int($_POST['story_id'] ?? 0);

    if ($act === 'approve' && $sid) {
        db_exec("UPDATE success_stories SET is_approved=1 WHERE id=?", [$sid]);
        flash('success', 'Story approved.');
    } elseif ($act === 'reject' && $sid) {
        db_exec("UPDATE success_stories SET is_approved=0 WHERE id=?", [$sid]);
        flash('success', 'Story rejected.');
    } elseif ($act === 'feature' && $sid) {
        db_exec("UPDATE success_stories SET featured=1-featured WHERE id=?", [$sid]);
        flash('success', 'Feature status changed.');
    } elseif ($act === 'delete' && $sid) {
        db_exec("DELETE FROM success_stories WHERE id=?", [$sid]);
        flash('success', 'Story deleted.');
    }
    redirect(APP_URL . '/admin/stories.php');
}

$filter  = sanitize_string($_GET['filter'] ?? 'pending', 20);
$page    = max(1, sanitize_int($_GET['page'] ?? 1));
$per     = 15;
$whereClause = match($filter) {
    'approved' => 'WHERE ss.is_approved=1',
    'rejected' => 'WHERE ss.is_approved=0',
    default    => 'WHERE ss.is_approved=0',
};

$total   = (int)db_val("SELECT COUNT(*) FROM success_stories ss $whereClause");
$offset  = ($page-1)*$per;
$stories = db_query("
    SELECT ss.*, u.name as user_name, u.avatar as user_avatar,
           c.title_bn as course_title
    FROM success_stories ss
    LEFT JOIN users u ON u.id=ss.user_id
    LEFT JOIN courses c ON c.id=ss.course_id
    $whereClause
    ORDER BY ss.created_at DESC
    LIMIT $per OFFSET $offset
");

$pendingCount  = (int)db_val("SELECT COUNT(*) FROM success_stories WHERE is_approved=0");
$approvedCount = (int)db_val("SELECT COUNT(*) FROM success_stories WHERE is_approved=1");
$featuredCount = (int)db_val("SELECT COUNT(*) FROM success_stories WHERE is_approved=1 AND featured=1");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Success Stories — <?= APP_NAME ?></title>
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
    <h1 style="font-size:var(--text-2xl)">Success Stories</h1>
</div>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:var(--sp-4);margin-bottom:var(--sp-6)">
    <?php foreach ([['pending','warning',$pendingCount,'Pending Approval'],['check_circle','success',$approvedCount,'Approved'],['star','accent',$featuredCount,'Featured']] as [$i,$c,$v,$l]): ?>
    <div class="stat-card"><div class="stat-card-icon <?= $c ?>"><span class="material-icons-round"><?= $i ?></span></div><div><div class="stat-card-value"><?= $v ?></div><div class="stat-card-label"><?= $l ?></div></div></div>
    <?php endforeach; ?>
</div>

<!-- Filter tabs -->
<div style="display:flex;gap:var(--sp-3);margin-bottom:var(--sp-5)">
    <?php foreach (['pending'=>'Pending Approval','approved'=>'Approved','rejected'=>'Rejected'] as $f=>$l): ?>
    <a href="<?= APP_URL ?>/admin/stories.php?filter=<?= $f ?>"
       class="btn <?= $filter===$f?'btn-primary':'btn-ghost' ?> btn-sm"><?= $l ?></a>
    <?php endforeach; ?>
</div>

<!-- Stories table -->
<div class="data-table-wrap">
<table class="data-table">
<thead><tr><th>Student</th><th>Course</th><th>Story Excerpt</th><th>Rating</th><th>Date</th><th>Action</th></tr></thead>
<tbody>
<?php if (empty($stories)): ?>
<tr><td colspan="6" style="text-align:center;padding:40px;color:var(--c-ink-muted)">No stories yet</td></tr>
<?php else: foreach ($stories as $s): ?>
<tr>
    <td>
        <div style="display:flex;align-items:center;gap:8px">
            <img src="<?= e(avatar_url($s['user_avatar'],$s['user_name'])) ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover">
            <div>
                <div style="font-weight:700;font-size:var(--text-sm)"><?= e($s['user_name']??'Unknown') ?></div>
                <?php if (!empty($s['role_after'])): ?>
                <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= e($s['role_after']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </td>
    <td style="font-size:var(--text-sm);color:var(--c-ink-muted)"><?= e(mb_substr($s['course_title']??'—',0,30,'UTF-8')) ?></td>
    <td style="font-size:var(--text-sm);max-width:260px">
        <?= e(mb_substr($s['quote'],0,100,'UTF-8')) ?><?= mb_strlen($s['quote'],'UTF-8')>100?'…':'' ?>
    </td>
    <td style="color:#F59E0B"><?= str_repeat('★',(int)$s['rating']) ?></td>
    <td style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
    <td>
        <div style="display:flex;gap:4px;flex-wrap:wrap">
            <?php if (!$s['is_approved']): ?>
            <form method="POST" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="story_id" value="<?= (int)$s['id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm" style="font-size:11px">
                    <span class="material-icons-round" style="font-size:13px">check</span> Approve
                </button>
            </form>
            <?php else: ?>
            <form method="POST" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="feature">
                <input type="hidden" name="story_id" value="<?= (int)$s['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" style="font-size:11px;color:<?= $s['featured']?'var(--c-warning)':'var(--c-ink-muted)' ?>">
                    <span class="material-icons-round" style="font-size:13px"><?= $s['featured']?'star':'star_border' ?></span> Feature
                </button>
            </form>
            <form method="POST" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="story_id" value="<?= (int)$s['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" style="font-size:11px">Reject</button>
            </form>
            <?php endif; ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this story?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="story_id" value="<?= (int)$s['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--c-error)">
                    <span class="material-icons-round" style="font-size:14px">delete</span>
                </button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
<?= paginate($total, $page, $per, APP_URL.'/admin/stories.php?filter='.urlencode($filter).'&page={page}') ?>
</div>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
