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

$action  = sanitize_string($_GET['action'] ?? 'list', 20);
$edit_id = sanitize_int($_GET['id'] ?? 0);

// ── Edit Access Removed ──────────────────────────────────────────────────────────────
// Teachers can no longer edit course details. This is restricted to administrators only.
if (isset($_GET['action']) && $_GET['action'] === 'edit') {
    flash('error', 'Teachers cannot edit courses. Please contact an Administrator.');
    redirect(APP_URL.'/teacher/courses.php');
}

// List
$search = sanitize_string($_GET['q'] ?? '', 100);
$page   = max(1, sanitize_int($_GET['page'] ?? 1));
$perPage = 12;
$where = ['(c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))']; $params = [$tpId, $tpId];
if ($search) { $where[] = 'c.title_bn LIKE ?'; $params[] = '%'.$search.'%'; }
$whereSQL = 'WHERE '.implode(' AND ',$where);
$total   = (int)db_val("SELECT COUNT(*) FROM courses c $whereSQL", $params);
$offset  = ($page-1)*$perPage;
$courses = db_query("SELECT c.*, (SELECT COUNT(*) FROM enrollments e WHERE e.course_id=c.id) as enroll_count FROM courses c $whereSQL ORDER BY c.created_at DESC LIMIT $perPage OFFSET $offset", $params);
$categories = db_query("SELECT id,name_bn FROM categories ORDER BY sort_order");

function time_ago(string $dt): string { $d=time()-strtotime($dt); if($d<60)return 'just now'; if($d<3600)return intdiv($d,60).'m ago'; if($d<86400)return intdiv($d,3600).'h ago'; return date('d M Y',strtotime($dt)); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>My Courses — <?= APP_NAME ?></title>
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


        <!-- ── List ── -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
            <h1 style="font-size:var(--text-2xl)">My Courses</h1>
        </div>
        <form method="GET" style="display:flex;gap:var(--sp-3);margin-bottom:var(--sp-6)">
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search courses..." class="form-control" style="max-width:300px">
            <button type="submit" class="btn btn-primary btn-sm"><span class="material-icons-round">search</span></button>
            <?php if ($search): ?><a href="<?= APP_URL ?>/teacher/courses.php" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
            <span style="margin-left:auto;color:var(--c-ink-muted);font-size:var(--text-sm);align-self:center"><?= $total ?> courses</span>
        </form>

        <?php if (empty($courses)): ?>
        <div style="text-align:center;padding:var(--sp-16);background:var(--c-surface);border-radius:var(--r-lg);border:2px dashed var(--c-border)">
            <span class="material-icons-round" style="font-size:56px;color:var(--c-ink-faint)">school</span>
            <h3 style="margin:12px 0 8px;color:var(--c-ink-light)">No courses assigned to you yet</h3>
        </div>
        <?php else: ?>
        <div class="data-table-wrap">
            <table class="data-table">
                <thead><tr><th>Course</th><th>Type</th><th>Price</th><th>Students</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($courses as $c): ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:12px">
                            <?php $thumbUrl = course_thumbnail_url($c['thumbnail'] ?? '', $c['title_bn'] ?? '', '80x45'); ?>
                            <img src="<?= e($thumbUrl) ?>"
                                 style="width:80px;height:45px;object-fit:cover;border-radius:6px;flex-shrink:0">
                            <div>
                                <div style="font-weight:700;font-size:var(--text-sm)"><?= e($c['title_bn']) ?></div>
                                <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= time_ago($c['created_at']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td><span style="font-size:var(--text-xs)"><?= ['recorded'=>'Recorded','live'=>'Live','hybrid'=>'Hybrid'][$c['type']] ?? $c['type'] ?></span></td>
                    <td style="font-family:var(--font-latin);font-weight:700">
                        <?php if ($c['is_free']): ?>
                        <span style="color:var(--c-success)">Free</span>
                        <?php else: ?>
                        <?= number_format($c['sale_price']>0 ? $c['sale_price'] : $c['price'],0) ?>
                        <?php endif; ?>
                    </td>
                    <td style="font-family:var(--font-latin);font-weight:700"><?= number_format((int)$c['enroll_count']) ?></td>
                    <td>
                        <span class="status-badge <?= ['published'=>'status-active','draft'=>'status-draft','archived'=>'status-archived'][$c['status']] ?? '' ?>">
                            <?= ['published'=>'Published','draft'=>'Draft','archived'=>'Archived'][$c['status']] ?>
                        </span>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px">
                            <a href="<?= APP_URL ?>/teacher/classroom.php?id=<?= (int)$c['id'] ?>" class="btn btn-primary btn-sm" title="Virtual Classroom Hub" style="margin-right:8px;display:flex;align-items:center;gap:4px">
                                <span class="material-icons-round" style="font-size:15px">cast_for_education</span> Hub
                            </a>
                            <a href="<?= APP_URL ?>/teacher/lessons.php?course=<?= (int)$c['id'] ?>" class="btn btn-ghost btn-sm" title="Lessons">
                                <span class="material-icons-round" style="font-size:15px">list</span>
                            </a>
                            <a href="<?= APP_URL ?>/course.php?slug=<?= e($c['slug']) ?>" target="_blank" class="btn btn-ghost btn-sm" title="View">
                                <span class="material-icons-round" style="font-size:15px">open_in_new</span>
                            </a>

                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= paginate($total,$page,$perPage, APP_URL.'/teacher/courses.php?'.http_build_query(array_filter(['q'=>$search,'page'=>'{page}']))) ?>
        <?php endif; ?>
    </div>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
