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

$courseFilter = sanitize_int($_GET['course'] ?? 0);
$search       = sanitize_string($_GET['q'] ?? '', 100);
$page         = max(1, sanitize_int($_GET['page'] ?? 1));
$perPage      = 20;

// Courses owned by teacher (for filter dropdown)
$myCourses = db_query("SELECT id, title_bn FROM courses WHERE teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=courses.id AND ct.teacher_id=?) ORDER BY title_bn", [$tpId, $tpId]);

// Build query
$where  = ['(c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))', 'e.status=\'active\''];
$params = [$tpId, $tpId];
if ($courseFilter) { $where[] = 'c.id=?'; $params[] = $courseFilter; }
if ($search) { $where[] = '(u.name LIKE ? OR u.phone LIKE ?)'; $params[] = '%'.$search.'%'; $params[] = '%'.$search.'%'; }
$whereSQL = 'WHERE '.implode(' AND ', $where);

$total  = (int)db_val("SELECT COUNT(*) FROM enrollments e JOIN courses c ON c.id=e.course_id JOIN users u ON u.id=e.user_id $whereSQL", $params);
$offset = ($page - 1) * $perPage;
$students = db_query("
    SELECT u.id, u.name, u.phone, u.avatar, u.created_at as joined,
           c.title_bn as course_title, c.id as course_id,
           e.enrolled_at, e.progress_pct, e.status as enroll_status
    FROM enrollments e
    JOIN courses c ON c.id = e.course_id
    JOIN users u ON u.id = e.user_id
    $whereSQL
    ORDER BY e.enrolled_at DESC
    LIMIT $perPage OFFSET $offset
", $params);

function time_ago(string $dt): string { $d=time()-strtotime($dt); if($d<60)return 'just now'; if($d<3600)return intdiv($d,60).'m ago'; if($d<86400)return intdiv($d,3600).'h ago'; if($d<604800)return intdiv($d,86400).'d ago'; return date('d M Y',strtotime($dt)); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Students — <?= APP_NAME ?></title>
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

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
            <div>
                <h1 style="font-size:var(--text-2xl)">Students</h1>
                <p style="color:var(--c-ink-muted);margin:4px 0 0">Total <strong style="color:var(--c-ink)"><?= number_format($total) ?></strong> enrolled</p>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" style="display:flex;gap:var(--sp-3);margin-bottom:var(--sp-6);flex-wrap:wrap">
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search by name or phone..." class="form-control" style="max-width:260px">
            <select name="course" class="form-control" style="max-width:280px">
                <option value="">All Courses</option>
                <?php foreach ($myCourses as $mc): ?>
                <option value="<?= (int)$mc['id'] ?>" <?= $courseFilter===$mc['id']?'selected':'' ?>><?= e($mc['title_bn']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm"><span class="material-icons-round">search</span> Filter</button>
            <?php if ($search || $courseFilter): ?>
            <a href="<?= APP_URL ?>/teacher/students.php" class="btn btn-ghost btn-sm">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (empty($students)): ?>
        <div style="text-align:center;padding:var(--sp-16);background:var(--c-surface);border-radius:var(--r-lg);border:2px dashed var(--c-border)">
            <span class="material-icons-round" style="font-size:56px;color:var(--c-ink-faint)">people</span>
            <h3 style="margin:12px 0 8px;color:var(--c-ink-light)">No students found</h3>
            <p style="color:var(--c-ink-muted)">Students can enroll once the course is published.</p>
        </div>
        <?php else: ?>
        <div class="data-table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Course</th>
                        <th>Progress</th>
                        <th>Enrolled</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $s): ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:12px">
                                <img src="<?= e(avatar_url($s['avatar'], $s['name'])) ?>"
                                     alt="" style="width:40px;height:40px;border-radius:50%;flex-shrink:0">
                                <div>
                                    <div style="font-weight:700;font-size:var(--text-sm)"><?= e($s['name']) ?></div>
                                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= e($s['phone']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="font-size:var(--text-sm);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                <?= e($s['course_title']) ?>
                            </div>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;min-width:120px">
                                <div class="progress-bar" style="flex:1">
                                    <div class="progress-fill" style="width:<?= (int)$s['progress_pct'] ?>%"></div>
                                </div>
                                <span style="font-family:var(--font-latin);font-size:var(--text-xs);font-weight:700;color:var(--c-primary);white-space:nowrap">
                                    <?= (int)$s['progress_pct'] ?>%
                                </span>
                            </div>
                        </td>
                        <td style="font-size:var(--text-sm);color:var(--c-ink-muted)"><?= time_ago($s['enrolled_at']) ?></td>
                        <td>
                            <span class="status-badge <?= $s['enroll_status']==='active'?'status-active':'status-archived' ?>">
                                <?= $s['enroll_status']==='active' ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= paginate($total, $page, $perPage, APP_URL.'/teacher/students.php?'.http_build_query(array_filter(['q'=>$search,'course'=>$courseFilter ?: '','page'=>'{page}']))) ?>
        <?php endif; ?>
    </div>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
