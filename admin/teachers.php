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
    $act = sanitize_string($_POST['action'] ?? '', 30);
    $tid = sanitize_int($_POST['teacher_id'] ?? 0);

    if ($act === 'toggle_active' && $tid) {
        $uid_t = (int)db_val("SELECT user_id FROM teacher_profiles WHERE id=?", [$tid]);
        if ($uid_t) db_exec("UPDATE users SET is_active=1-is_active WHERE id=?", [$uid_t]);
        flash('success', 'Teacher status changed.');

    } elseif ($act === 'update_featured' && $tid) {
        $fo = sanitize_int($_POST['featured_order'] ?? 0);
        db_exec("UPDATE teacher_profiles SET featured_order=? WHERE id=?", [$fo, $tid]);
        flash('success', 'Featured order updated.');

    } elseif ($act === 'create_teacher') {
        $name  = sanitize_string($_POST['name'] ?? '', 100);
        $phone = preg_replace('/[^0-9+]/', '', $_POST['phone'] ?? '');
        $email = sanitize_string($_POST['email'] ?? '', 180);
        $pass  = $_POST['password'] ?? '';
        $hl    = sanitize_string($_POST['headline'] ?? '', 255);
        if (!$name || !$phone || strlen($pass) < 8) {
            flash('error', 'Name, phone, and password are required.');
        } elseif (db_val("SELECT 1 FROM users WHERE phone=?", [$phone])) {
            flash('error', 'This phone number is already registered.');
        } elseif ($email && db_val("SELECT 1 FROM users WHERE email=?", [$email])) {
            flash('error', 'This email address is already registered.');
        } else {
            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost'=>BCRYPT_COST]);
            $uid = db_insert("INSERT INTO users (name,phone,email,password_hash,role_id,is_active,is_verified,created_at) VALUES (?,?,?,?,2,1,1,NOW())",
                [$name, $phone, $email?:null, $hash]);
            db_insert("INSERT INTO teacher_profiles (user_id,headline,rating_avg,total_students,total_courses,featured_order) VALUES (?,?,0,0,0,0)", [$uid, $hl]);
            flash('success', 'Teacher account created.');
        }

    } elseif ($act === 'edit_teacher' && $tid) {
        $uid_t  = (int)db_val("SELECT user_id FROM teacher_profiles WHERE id=?", [$tid]);
        if (!$uid_t) { flash('error', 'Teacher not found.'); redirect(APP_URL.'/admin/teachers.php'); }
        $name   = sanitize_string($_POST['name'] ?? '', 100);
        $phone  = preg_replace('/[^0-9+]/', '', $_POST['phone'] ?? '');
        $email  = sanitize_string($_POST['email'] ?? '', 180);
        $hl     = sanitize_string($_POST['headline'] ?? '', 255);
        $bio    = sanitize_string($_POST['bio'] ?? '', 2000);
        $desig  = sanitize_string($_POST['designation'] ?? '', 200);
        $expert = sanitize_string($_POST['expertise'] ?? '', 500);
        $fb     = sanitize_string($_POST['fb_url'] ?? '', 300);
        $yt     = sanitize_string($_POST['yt_url'] ?? '', 300);
        $web    = sanitize_string($_POST['website'] ?? '', 300);
        $newPass= $_POST['new_password'] ?? '';
        if (!$name || !$phone) { flash('error', 'Name and phone are required.'); redirect(APP_URL.'/admin/teachers.php'); }
        if (db_val("SELECT 1 FROM users WHERE phone=? AND id!=?", [$phone, $uid_t])) { flash('error', 'This phone number is already used.'); redirect(APP_URL.'/admin/teachers.php'); }
        if ($email && db_val("SELECT 1 FROM users WHERE email=? AND id!=?", [$email, $uid_t])) { flash('error', 'This email address is already used.'); redirect(APP_URL.'/admin/teachers.php'); }
        db_exec("UPDATE users SET name=?, phone=?, email=? WHERE id=?", [$name, $phone, $email?:null, $uid_t]);
        if (strlen($newPass) >= 8) {
            $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost'=>BCRYPT_COST]);
            db_exec("UPDATE users SET password_hash=? WHERE id=?", [$hash, $uid_t]);
        }
        db_exec("UPDATE teacher_profiles SET headline=?,bio=?,designation=?,expertise=?,fb_url=?,yt_url=?,website=?,updated_at=NOW() WHERE id=?",
            [$hl, $bio, $desig, $expert, $fb, $yt, $web, $tid]);
        flash('success', 'Teacher info updated.');

    } elseif ($act === 'impersonate' && $tid) {
        $uid_t = (int)db_val("SELECT user_id FROM teacher_profiles WHERE id=?", [$tid]);
        if (!$uid_t) { flash('error', 'Teacher not found.'); redirect(APP_URL.'/admin/teachers.php'); }
        $teacherUser = db_row("SELECT * FROM users WHERE id=? AND role_id=?", [$uid_t, ROLE_TEACHER]);
        if (!$teacherUser || !$teacherUser['is_active']) {
            flash('error', 'This teacher account is not active.');
            redirect(APP_URL.'/admin/teachers.php');
        }
        $_SESSION['original_admin'] = [
            'id'   => (int)$_SESSION['user_id'],
            'role' => (int)$_SESSION['user_role'],
            'name' => current_user()['name'] ?? '',
        ];
        $_SESSION['user_id']   = (int)$teacherUser['id'];
        $_SESSION['user_role'] = (int)$teacherUser['role_id'];
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        db_insert("INSERT INTO admin_impersonations (admin_id, target_id, ip_address, created_at) VALUES (?,?,?,NOW())",
            [(int)$_SESSION['original_admin']['id'], $uid_t, $ip]);
        redirect(APP_URL . '/teacher/dashboard.php');
    }

    redirect(APP_URL . '/admin/teachers.php');
}

$search = sanitize_string($_GET['q'] ?? '', 100);
$page   = max(1, sanitize_int($_GET['page'] ?? 1));
$per    = 15;
$wparams = [];
$wsql    = '';
if ($search) {
    $wsql    = "WHERE (u.name LIKE ? OR u.phone LIKE ?)";
    $s       = '%' . $search . '%';
    $wparams = [$s, $s];
}
$total  = (int)db_val("SELECT COUNT(*) FROM teacher_profiles tp JOIN users u ON u.id=tp.user_id $wsql", $wparams);
$offset = ($page-1)*$per;
$teachers = db_query("
    SELECT tp.*, u.name, u.phone, u.email, u.avatar, u.is_active, u.created_at, u.id as uid,
           (SELECT COUNT(*) FROM courses c WHERE c.teacher_id=tp.id AND c.status='published') as course_count,
           (SELECT COUNT(DISTINCT e.user_id) FROM enrollments e JOIN courses c ON c.id=e.course_id WHERE c.teacher_id=tp.id) as student_count,
           (SELECT COALESCE(SUM(oi.price),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN courses c ON c.id=oi.item_id WHERE c.teacher_id=tp.id AND o.status='paid' AND oi.item_type='course') as total_earnings
    FROM teacher_profiles tp
    JOIN users u ON u.id=tp.user_id
    $wsql
    ORDER BY tp.featured_order DESC, student_count DESC
    LIMIT $per OFFSET $offset
", $wparams);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Teachers — <?= APP_NAME ?></title>
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

<!-- Create teacher modal -->
<div id="ctModal" style="display:none;position:fixed;inset:0;z-index:999;background:rgba(0,0,0,.6);align-items:center;justify-content:center;padding:20px">
    <div class="card" style="width:100%;max-width:440px;padding:var(--sp-7);position:relative">
        <button onclick="document.getElementById('ctModal').style.display='none'" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer"><span class="material-icons-round">close</span></button>
        <h3 style="margin-bottom:var(--sp-5)">New Teacher Account</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_teacher">
            <div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Phone *</label><input type="tel" name="phone" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
            <div class="form-group"><label class="form-label">Professional Title</label><input type="text" name="headline" class="form-control" placeholder="HSC Physics Teacher"></div>
            <div class="form-group"><label class="form-label">Password * (at least 8 characters)</label><input type="text" name="password" class="form-control" required minlength="8"></div>
            <button type="submit" class="btn btn-primary btn-block">Create</button>
        </form>
    </div>
</div>

<!-- Edit teacher modal -->
<div id="etModal" style="display:none;position:fixed;inset:0;z-index:999;background:rgba(0,0,0,.6);align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto">
    <div class="card" style="width:100%;max-width:560px;padding:var(--sp-7);position:relative;margin:auto">
        <button onclick="document.getElementById('etModal').style.display='none'" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer"><span class="material-icons-round">close</span></button>
        <h3 style="margin-bottom:var(--sp-5)">Edit Teacher Info</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_teacher">
            <input type="hidden" name="teacher_id" id="et_tid">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-3)">
                <div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" id="et_name" class="form-control" required></div>
                <div class="form-group"><label class="form-label">Phone *</label><input type="tel" name="phone" id="et_phone" class="form-control" required></div>
            </div>
            <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" id="et_email" class="form-control"></div>
            <div class="form-group"><label class="form-label">Professional Title</label><input type="text" name="headline" id="et_headline" class="form-control"></div>
            <div class="form-group"><label class="form-label">Designation</label><input type="text" name="designation" id="et_designation" class="form-control"></div>
            <div class="form-group"><label class="form-label">Expertise (comma separated)</label><input type="text" name="expertise" id="et_expertise" class="form-control"></div>
            <div class="form-group"><label class="form-label">Short Bio</label><textarea name="bio" id="et_bio" class="form-control" rows="3"></textarea></div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:var(--sp-3)">
                <div class="form-group"><label class="form-label">Facebook</label><input type="url" name="fb_url" id="et_fb" class="form-control"></div>
                <div class="form-group"><label class="form-label">YouTube</label><input type="url" name="yt_url" id="et_yt" class="form-control"></div>
                <div class="form-group"><label class="form-label">Website</label><input type="url" name="website" id="et_web" class="form-control"></div>
            </div>
            <div class="form-group"><label class="form-label">New Password (optional — 8+ characters)</label><input type="text" name="new_password" class="form-control" minlength="8" placeholder="Leave blank to keep unchanged"></div>
            <button type="submit" class="btn btn-primary btn-block">Save</button>
        </form>
    </div>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6);flex-wrap:wrap;gap:12px">
    <h1 style="font-size:var(--text-2xl)">Teachers <span style="color:var(--c-ink-muted);font-size:var(--text-lg)">(<?= $total ?>)</span></h1>
    <button onclick="document.getElementById('ctModal').style.display='flex'" class="btn btn-primary btn-sm">
        <span class="material-icons-round">person_add</span> New Teacher
    </button>
</div>

<form method="GET" style="display:flex;gap:var(--sp-3);margin-bottom:var(--sp-5)">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name or phone..." class="form-control" style="max-width:280px">
    <button type="submit" class="btn btn-primary btn-sm"><span class="material-icons-round">search</span></button>
    <?php if ($search): ?><a href="<?= APP_URL ?>/admin/teachers.php" class="btn btn-ghost btn-sm">Reset</a><?php endif; ?>
</form>

<div class="data-table-wrap">
<table class="data-table">
<thead><tr><th>Teacher</th><th>Courses</th><th>Students</th><th>Earnings</th><th>Rating</th><th>Status</th><th>Featured</th><th>Action</th></tr></thead>
<tbody>
<?php if (empty($teachers)): ?>
<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--c-ink-muted)">No teachers yet</td></tr>
<?php else: foreach ($teachers as $t): ?>
<tr>
    <td>
        <div style="display:flex;align-items:center;gap:10px">
            <img src="<?= e(avatar_url($t['avatar']??'',$t['name'])) ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover">
            <div>
                <div style="font-weight:700"><?= e($t['name']) ?></div>
                <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= e($t['headline']??'') ?></div>
                <div style="font-size:var(--text-xs);color:var(--c-ink-faint)"><?= e($t['phone']) ?></div>
            </div>
        </div>
    </td>
    <td style="font-weight:700;font-family:var(--font-latin)"><?= (int)$t['course_count'] ?></td>
    <td style="font-weight:700;font-family:var(--font-latin)"><?= number_format((int)$t['student_count']) ?></td>
    <td style="font-family:var(--font-latin);color:var(--c-success)"><?= number_format((float)$t['total_earnings'],0) ?></td>
    <td><span style="color:#F59E0B">★</span> <?= number_format((float)$t['rating_avg'],1) ?></td>
    <td>
        <span class="status-badge <?= $t['is_active']?'status-active':'status-archived' ?>">
            <?= $t['is_active']?'Active':'Inactive' ?>
        </span>
    </td>
    <td>
        <form method="POST" style="display:flex;gap:4px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_featured">
            <input type="hidden" name="teacher_id" value="<?= (int)$t['id'] ?>">
            <input type="number" name="featured_order" value="<?= (int)$t['featured_order'] ?>" style="width:60px;padding:4px;border:1px solid var(--c-border);border-radius:var(--r-sm);font-size:var(--text-xs)" min="0">
            <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px"><span class="material-icons-round" style="font-size:14px">save</span></button>
        </form>
    </td>
    <td>
        <div style="display:flex;gap:4px;flex-wrap:wrap">
            <a href="<?= APP_URL ?>/teacher-profile.php?id=<?= (int)$t['id'] ?>" class="btn btn-ghost btn-sm" target="_blank" title="View profile">
                <span class="material-icons-round" style="font-size:15px">open_in_new</span>
            </a>
            <button class="btn btn-ghost btn-sm" title="Edit"
                    onclick="openEditTeacher(<?= htmlspecialchars(json_encode([
                        'id'          => (int)$t['id'],
                        'name'        => $t['name'],
                        'phone'       => $t['phone'],
                        'email'       => $t['email'] ?? '',
                        'headline'    => $t['headline'] ?? '',
                        'bio'         => $t['bio'] ?? '',
                        'designation' => $t['designation'] ?? '',
                        'expertise'   => $t['expertise'] ?? '',
                        'fb_url'      => $t['fb_url'] ?? '',
                        'yt_url'      => $t['yt_url'] ?? '',
                        'website'     => $t['website'] ?? '',
                    ]), ENT_QUOTES) ?>)">
                <span class="material-icons-round" style="font-size:15px">edit</span>
            </button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Log in to this teacher account? Your admin session will be kept.')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="impersonate">
                <input type="hidden" name="teacher_id" value="<?= (int)$t['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" title="Log in as this account" style="color:var(--c-warning)">
                    <span class="material-icons-round" style="font-size:15px">manage_accounts</span>
                </button>
            </form>
            <form method="POST" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="teacher_id" value="<?= (int)$t['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" title="<?= $t['is_active']?'Deactivate':'Activate' ?>">
                    <span class="material-icons-round" style="font-size:15px"><?= $t['is_active']?'block':'check_circle' ?></span>
                </button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
<?= paginate($total, $page, $per, APP_URL.'/admin/teachers.php?'.http_build_query(array_filter(['q'=>$search,'page'=>'{page}']))) ?>
</div>
</div>
<script>
function openEditTeacher(t) {
    document.getElementById('et_tid').value         = t.id;
    document.getElementById('et_name').value        = t.name;
    document.getElementById('et_phone').value       = t.phone;
    document.getElementById('et_email').value       = t.email;
    document.getElementById('et_headline').value    = t.headline;
    document.getElementById('et_bio').value         = t.bio;
    document.getElementById('et_designation').value = t.designation;
    document.getElementById('et_expertise').value   = t.expertise;
    document.getElementById('et_fb').value          = t.fb_url;
    document.getElementById('et_yt').value          = t.yt_url;
    document.getElementById('et_web').value         = t.website;
    document.getElementById('etModal').style.display = 'flex';
}
</script>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
