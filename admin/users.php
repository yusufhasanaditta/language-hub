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
    $action = sanitize_string($_POST['action'] ?? '', 20);
    $uid    = sanitize_int($_POST['user_id'] ?? 0);

    if ($action === 'toggle_active' && $uid) {
        db_exec("UPDATE users SET is_active = 1-is_active WHERE id=? AND role_id!=6", [$uid]);
        flash('success', 'User status changed.');

    } elseif ($action === 'change_role' && $uid) {
        $role = sanitize_int($_POST['role_id'] ?? 1);
        if (in_array($role, [1,2,3,4,5])) {
            db_exec("UPDATE users SET role_id=? WHERE id=? AND role_id!=6", [$role, $uid]);
            if ($role === ROLE_TEACHER && !db_val("SELECT 1 FROM teacher_profiles WHERE user_id=?", [$uid])) {
                db_insert("INSERT INTO teacher_profiles (user_id, rating_avg, total_students, total_courses) VALUES (?,0,0,0)", [$uid]);
            }
            flash('success', 'Role changed.');
        }

    } elseif ($action === 'edit_user' && $uid) {
        $name     = sanitize_string($_POST['name'] ?? '', 100);
        $email    = sanitize_string($_POST['email'] ?? '', 180);
        $school   = sanitize_string($_POST['school'] ?? '', 200);
        $college  = sanitize_string($_POST['college'] ?? '', 200);
        $cls      = sanitize_string($_POST['class_level'] ?? '', 50);
        $target   = sanitize_string($_POST['target'] ?? '', 200);
        $parent   = sanitize_string($_POST['parent_name'] ?? '', 100);
        $pphone   = preg_replace('/[^0-9+]/', '', $_POST['parent_phone'] ?? '');
        if (!$name) { flash('error', 'Name is required.'); redirect(APP_URL.'/admin/users.php'); }
        db_exec("UPDATE users SET name=?,email=?,school=?,college=?,class_level=?,target=?,parent_name=?,parent_phone=? WHERE id=? AND role_id!=6",
            [$name, $email?:null, $school, $college, $cls, $target, $parent, $pphone?:null, $uid]);
        flash('success', 'User info updated.');

    } elseif ($action === 'reset_password' && $uid) {
        $newPass = $_POST['new_password'] ?? '';
        if (!validate_password($newPass)) {
            flash('error', 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.');
        } else {
            $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
            db_exec("UPDATE users SET password_hash=? WHERE id=? AND role_id!=6", [$hash, $uid]);
            flash('success', 'Password reset. Give the student their new password so they can log in.');
        }

    } elseif ($action === 'enroll_student' && $uid) {
        $courseId = sanitize_int($_POST['course_id'] ?? 0);
        if (!$courseId) { flash('error', 'Choose a course.'); redirect(APP_URL.'/admin/users.php'); }
        $existing = db_val("SELECT 1 FROM enrollments WHERE user_id=? AND course_id=?", [$uid, $courseId]);
        if ($existing) {
            flash('info', 'Student is already enrolled in this course.');
        } else {
            db_insert("INSERT INTO enrollments (user_id, course_id, enrolled_at) VALUES (?,?,NOW())", [$uid, $courseId]);
            // Update enrolled_count on course
            db_exec("UPDATE courses SET enrolled_count=COALESCE(enrolled_count,0)+1 WHERE id=?", [$courseId]);
            $courseTitle = db_val("SELECT title_bn FROM courses WHERE id=?", [$courseId]) ?? 'a course';
            create_notification(
                $uid, 'enrollment', 'Enrolled in ' . $courseTitle,
                'An administrator enrolled you in this course. You now have full access.',
                APP_URL . '/learn.php?course=' . $courseId
            );
            flash('success', 'Enrolled in course.');
        }

    } elseif ($action === 'create_teacher') {
        $name    = sanitize_string($_POST['name'] ?? '', 100);
        $phone   = preg_replace('/[^0-9+]/', '', $_POST['phone'] ?? '');
        $email   = sanitize_string($_POST['email'] ?? '', 180);
        $pass    = $_POST['password'] ?? '';
        if (!$name || !$phone || strlen($pass) < 8) {
            flash('error', 'Name, phone, and a password of at least 8 characters are required.');
        } elseif (db_val("SELECT 1 FROM users WHERE phone=?", [$phone])) {
            flash('error', 'An account already exists with this phone number.');
        } else {
            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
            $newUid = db_insert("INSERT INTO users (name, phone, email, password_hash, role_id, is_active, is_verified, created_at) VALUES (?,?,?,?,?,1,1,NOW())",
                [$name, $phone, $email ?: null, $hash, ROLE_TEACHER]);
            db_insert("INSERT INTO teacher_profiles (user_id, rating_avg, total_students, total_courses) VALUES (?,0,0,0)", [$newUid]);
            flash('success', 'Teacher account created. (#' . $newUid . ')');
        }
    }
    redirect(APP_URL . '/admin/users.php' . (isset($_POST['view_uid']) && (int)$_POST['view_uid'] ? '?view='.(int)$_POST['view_uid'] : ''));
}

// Student detail view
$viewUid = sanitize_int($_GET['view'] ?? 0);
$viewUser = null;
$viewEnrollments = [];
$viewExamAttempts = [];
if ($viewUid) {
    $viewUser = db_row("SELECT * FROM users WHERE id=?", [$viewUid]);
    if ($viewUser) {
        $viewEnrollments = db_query("
            SELECT e.*, c.title_bn, c.thumbnail, c.slug
            FROM enrollments e
            JOIN courses c ON c.id=e.course_id
            WHERE e.user_id=?
            ORDER BY e.enrolled_at DESC
        ", [$viewUid]);
        $viewExamAttempts = db_query("
            SELECT ea.*, ex.title as exam_title, ex.total_marks, ex.exam_type, c.title_bn as course_title
            FROM exam_attempts ea
            JOIN exams ex ON ex.id=ea.exam_id
            JOIN courses c ON c.id=ex.course_id
            WHERE ea.user_id=? AND ea.status IN ('submitted','graded')
            ORDER BY ea.submitted_at DESC
            LIMIT 30
        ", [$viewUid]);
    }
}

// Published courses for enroll modal
$allCourses = db_query("SELECT c.id, c.title_bn, u.name as teacher_name FROM courses c JOIN teacher_profiles tp ON tp.id=c.teacher_id JOIN users u ON u.id=tp.user_id WHERE c.status='published' ORDER BY c.title_bn");

// List query
$search  = sanitize_string($_GET['q'] ?? '', 100);
// No ?role= at all -> default to students only (this page is titled "Students" in the sidebar).
// ?role=0 (explicit "All Roles" pick) -> show every role, including teachers/admins.
$role    = isset($_GET['role']) ? sanitize_int($_GET['role']) : ROLE_STUDENT;
$page    = max(1, sanitize_int($_GET['page'] ?? 1));
$perPage = USERS_PER_PAGE;

$where  = ['1=1'];
$params = [];
if ($search) {
    $where[]  = '(u.name LIKE ? OR u.phone LIKE ? OR u.email LIKE ?)';
    $s = '%' . $search . '%';
    $params = [$s,$s,$s];
}
if ($role) { $where[] = 'u.role_id=?'; $params[] = $role; }

$whereSQL = 'WHERE ' . implode(' AND ', $where);
$total    = (int)db_val("SELECT COUNT(*) FROM users u $whereSQL", $params);
$offset   = ($page - 1) * $perPage;
$users    = db_query("
    SELECT u.*, r.name as role_name,
           (SELECT COUNT(*) FROM enrollments e WHERE e.user_id=u.id) as enrollment_count
    FROM users u JOIN roles r ON r.id=u.role_id
    $whereSQL
    ORDER BY u.created_at DESC
    LIMIT $perPage OFFSET $offset
", $params);

$roles = db_query("SELECT * FROM roles");
$urlPattern = APP_URL . '/admin/users.php?' . http_build_query(['q'=>$search,'role'=>$role,'page'=>'{page}']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@700;800&family=Hind+Siliguri:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
    <script>const APP_URL='<?= APP_URL ?>';const CSRF_TOKEN='<?= csrf_token() ?>';</script>
</head>
<body>
<div class="admin-layout">
    <?php include dirname(__DIR__) . '/admin/includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php foreach (get_flash() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:16px">
            <span class="material-icons-round"><?= $f['type']==='success'?'check_circle':'error' ?></span>
            <span><?= e($f['message']) ?></span>
        </div>
        <?php endforeach; ?>

        <!-- Edit user modal -->
        <div id="editUserModal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.6);align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto">
            <div class="card" style="width:100%;max-width:520px;padding:var(--sp-7);position:relative;margin:auto">
                <button onclick="document.getElementById('editUserModal').style.display='none'" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer"><span class="material-icons-round">close</span></button>
                <h3 style="margin-bottom:var(--sp-5)">Edit Student Info</h3>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" id="eu_uid">
                    <div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" id="eu_name" class="form-control" required></div>
                    <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" id="eu_email" class="form-control"></div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-3)">
                        <div class="form-group"><label class="form-label">School</label><input type="text" name="school" id="eu_school" class="form-control"></div>
                        <div class="form-group"><label class="form-label">College</label><input type="text" name="college" id="eu_college" class="form-control"></div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-3)">
                        <div class="form-group"><label class="form-label">Class</label><input type="text" name="class_level" id="eu_class" class="form-control"></div>
                        <div class="form-group"><label class="form-label">Target</label><input type="text" name="target" id="eu_target" class="form-control"></div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-3)">
                        <div class="form-group"><label class="form-label">Parent's Name</label><input type="text" name="parent_name" id="eu_parent" class="form-control"></div>
                        <div class="form-group"><label class="form-label">Parent's Phone</label><input type="tel" name="parent_phone" id="eu_pphone" class="form-control"></div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Save</button>
                </form>
            </div>
        </div>

        <!-- Enroll student modal -->
        <div id="enrollModal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.6);align-items:center;justify-content:center;padding:20px">
            <div class="card" style="width:100%;max-width:440px;padding:var(--sp-7);position:relative">
                <button onclick="document.getElementById('enrollModal').style.display='none'" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer"><span class="material-icons-round">close</span></button>
                <h3 style="margin-bottom:8px">Enroll in Course</h3>
                <p id="enrollUserName" style="font-size:var(--text-sm);color:var(--c-ink-muted);margin-bottom:var(--sp-5)"></p>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="enroll_student">
                    <input type="hidden" name="user_id" id="enroll_uid">
                    <div class="form-group">
                        <label class="form-label">Course *</label>
                        <select name="course_id" class="form-control" required>
                            <option value="">Choose course</option>
                            <?php foreach ($allCourses as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= e($c['title_bn']) ?> — <?= e($c['teacher_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <span class="material-icons-round">school</span> Enroll
                    </button>
                </form>
            </div>
        </div>

        <!-- Reset Password modal -->
        <div id="resetPasswordModal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.6);align-items:center;justify-content:center;padding:20px">
            <div class="card" style="width:100%;max-width:440px;padding:var(--sp-7);position:relative">
                <button onclick="document.getElementById('resetPasswordModal').style.display='none'" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer"><span class="material-icons-round">close</span></button>
                <h3 style="margin-bottom:8px">Reset Password</h3>
                <p id="resetPasswordUserName" style="font-size:var(--text-sm);color:var(--c-ink-muted);margin-bottom:var(--sp-5)"></p>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" id="reset_uid">
                    <div class="form-group">
                        <label class="form-label">New Password *</label>
                        <input type="text" name="new_password" class="form-control" required minlength="<?= PASSWORD_MIN_LENGTH ?>" placeholder="At least <?= PASSWORD_MIN_LENGTH ?> characters">
                        <div class="form-hint">Shown as plain text so you can read it back to the student. Tell them this password after saving — it isn't stored anywhere you can look up later.</div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <span class="material-icons-round">lock_reset</span> Set New Password
                    </button>
                </form>
            </div>
        </div>

        <!-- Create Teacher Modal -->
        <div id="createTeacherModal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:var(--sp-4)">
            <div class="card" style="width:100%;max-width:480px;padding:var(--sp-7);position:relative;animation:scaleIn .2s ease">
                <button onclick="document.getElementById('createTeacherModal').style.display='none'"
                        style="position:absolute;top:16px;right:16px;background:none;border:none;cursor:pointer;color:var(--c-ink-muted)">
                    <span class="material-icons-round">close</span>
                </button>
                <h3 style="margin-bottom:var(--sp-5)">New Teacher Account</h3>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_teacher">
                    <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="name" class="form-control" required placeholder="Teacher's name"></div>
                    <div class="form-group"><label class="form-label">Phone Number *</label><input type="tel" name="phone" class="form-control" required placeholder="01XXXXXXXXX"></div>
                    <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" placeholder="teacher@example.com"></div>
                    <div class="form-group"><label class="form-label">Initial Password *</label><input type="text" name="password" class="form-control" required minlength="8" placeholder="At least 8 characters"></div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <span class="material-icons-round">person_add</span> Create Account
                    </button>
                </form>
            </div>
        </div>

        <?php if ($viewUid && $viewUser): ?>
        <!-- ── Student detail panel ── -->
        <div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:var(--sp-5)">
            <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-ghost btn-sm"><span class="material-icons-round">arrow_back</span></a>
            <h1 style="font-size:var(--text-2xl)">Student Details</h1>
        </div>
        <div style="display:grid;grid-template-columns:280px 1fr;gap:var(--sp-5);align-items:start">
            <!-- Profile card -->
            <div class="card" style="padding:var(--sp-6);text-align:center">
                <img src="<?= e(avatar_url($viewUser['avatar'],$viewUser['name'])) ?>" style="width:80px;height:80px;border-radius:50%;object-fit:cover;margin:0 auto 12px">
                <div style="font-size:var(--text-xl);font-weight:800"><?= e($viewUser['name']) ?></div>
                <?php if ($viewUser['roll_number']): ?><div style="font-size:var(--text-sm);color:var(--c-primary);font-weight:700;margin:4px 0"><?= e($viewUser['roll_number']) ?></div><?php endif; ?>
                <div style="font-size:var(--text-sm);color:var(--c-ink-muted);margin-bottom:16px"><?= e($viewUser['phone']) ?></div>
                <?php
                $rows = [
                    'Email'          => $viewUser['email'],
                    'School'         => $viewUser['school'],
                    'College'        => $viewUser['college'],
                    'Class'          => $viewUser['class_level'],
                    'Target'         => $viewUser['target'],
                    'Parent'         => $viewUser['parent_name'],
                    'Parent Phone'   => $viewUser['parent_phone'],
                ];
                foreach ($rows as $lbl=>$val): if ($val): ?>
                <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--c-border);font-size:var(--text-sm)">
                    <span style="color:var(--c-ink-muted)"><?= $lbl ?></span>
                    <span style="font-weight:600;text-align:right;max-width:160px"><?= e($val) ?></span>
                </div>
                <?php endif; endforeach; ?>
                <div style="margin-top:16px;display:flex;flex-direction:column;gap:8px">
                    <button onclick="openEditUser(<?= htmlspecialchars(json_encode([
                        'uid'    => (int)$viewUser['id'],
                        'name'   => $viewUser['name'],
                        'email'  => $viewUser['email'] ?? '',
                        'school' => $viewUser['school'] ?? '',
                        'college'=> $viewUser['college'] ?? '',
                        'class'  => $viewUser['class_level'] ?? '',
                        'target' => $viewUser['target'] ?? '',
                        'parent' => $viewUser['parent_name'] ?? '',
                        'pphone' => $viewUser['parent_phone'] ?? '',
                    ]), ENT_QUOTES) ?>)" class="btn btn-ghost btn-sm">
                        <span class="material-icons-round">edit</span> Edit Info
                    </button>
                    <button onclick="openEnroll(<?= (int)$viewUser['id'] ?>, '<?= addslashes(e($viewUser['name'])) ?>')" class="btn btn-primary btn-sm">
                        <span class="material-icons-round">add</span> Enroll in Course
                    </button>
                    <button onclick="openResetPassword(<?= (int)$viewUser['id'] ?>, '<?= addslashes(e($viewUser['name'])) ?>')" class="btn btn-ghost btn-sm">
                        <span class="material-icons-round">lock_reset</span> Reset Password
                    </button>
                </div>
            </div>
            <!-- Right panel -->
            <div style="display:flex;flex-direction:column;gap:var(--sp-4)">
                <!-- Enrollments -->
                <div class="card" style="padding:var(--sp-5)">
                    <h3 style="margin-bottom:var(--sp-4)">Enrolled Courses (<?= count($viewEnrollments) ?>)</h3>
                    <?php if (empty($viewEnrollments)): ?>
                    <p style="color:var(--c-ink-muted);font-size:var(--text-sm)">Not enrolled in any course.</p>
                    <?php else: ?>
                    <div style="display:flex;flex-direction:column;gap:10px">
                        <?php foreach ($viewEnrollments as $en): ?>
                        <div style="display:flex;align-items:center;gap:12px;padding:10px;border:1px solid var(--c-border);border-radius:10px">
                            <?php if ($en['thumbnail']): ?>
                            <img src="<?= e($en['thumbnail']) ?>" style="width:56px;height:36px;object-fit:cover;border-radius:6px;flex-shrink:0">
                            <?php endif; ?>
                            <div style="flex:1;min-width:0">
                                <div style="font-weight:700;font-size:var(--text-sm);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($en['title_bn']) ?></div>
                                <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= date('d M Y', strtotime($en['enrolled_at'])) ?></div>
                            </div>
                            <?php if (isset($en['progress_pct'])): ?>
                            <div style="font-size:var(--text-sm);font-weight:700;color:var(--c-primary)"><?= (int)$en['progress_pct'] ?>%</div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <!-- Exam results -->
                <div class="card" style="padding:var(--sp-5)">
                    <h3 style="margin-bottom:var(--sp-4)">Exam Results (<?= count($viewExamAttempts) ?>)</h3>
                    <?php if (empty($viewExamAttempts)): ?>
                    <p style="color:var(--c-ink-muted);font-size:var(--text-sm)">No exam results yet.</p>
                    <?php else: ?>
                    <div class="data-table-wrap">
                    <table class="data-table">
                    <thead><tr><th>Exam</th><th>Type</th><th>Score</th><th>Time</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($viewExamAttempts as $ea): ?>
                    <tr>
                        <td>
                            <div style="font-weight:700;font-size:var(--text-sm)"><?= e($ea['exam_title']) ?></div>
                            <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= e(mb_substr($ea['course_title'],0,30,'UTF-8')) ?></div>
                        </td>
                        <td><span class="status-badge status-draft"><?= strtoupper($ea['exam_type'] ?? 'mcq') ?></span></td>
                        <td style="font-weight:700;font-family:var(--font-latin)">
                            <?= number_format((float)$ea['score'],1) ?><?= $ea['total_marks'] ? ' / '.(int)$ea['total_marks'] : '' ?>
                        </td>
                        <td style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= $ea['submitted_at'] ? date('d M Y', strtotime($ea['submitted_at'])) : '—' ?></td>
                        <td><span class="status-badge <?= $ea['status']==='graded'?'status-active':'status-pending' ?>"><?= $ea['status']==='graded'?'Graded':'Submitted' ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- ── List view ── -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6);flex-wrap:wrap;gap:var(--sp-3)">
            <h1 style="font-size:var(--text-2xl)">Users <span style="font-size:var(--text-lg);color:var(--c-ink-muted)"><?= number_format($total) ?></span></h1>
            <button onclick="document.getElementById('createTeacherModal').style.display='flex'" class="btn btn-primary btn-sm">
                <span class="material-icons-round">person_add</span> Create Teacher
            </button>
        </div>

        <form method="GET" style="display:flex;gap:var(--sp-3);margin-bottom:var(--sp-6);flex-wrap:wrap">
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, phone, or email..." class="form-control" style="max-width:300px">
            <select name="role" class="form-control" style="width:auto" onchange="this.form.submit()">
                <option value="">All Roles</option>
                <?php foreach ($roles as $r): ?>
                <option value="<?= (int)$r['id'] ?>" <?= $role==$r['id']?'selected':'' ?>><?= e($r['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm"><span class="material-icons-round">search</span> Search</button>
            <?php if ($search || isset($_GET['role'])): ?><a href="<?= APP_URL ?>/admin/users.php" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
        </form>

        <div class="data-table-wrap">
            <table class="data-table">
                <thead><tr><th>#</th><th>Name</th><th>Phone / Email</th><th>Role</th><th>Courses</th><th>Joined</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                    <?php if (empty($users)): ?>
                    <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--c-ink-muted)">No users found</td></tr>
                    <?php else: foreach ($users as $u): ?>
                    <tr>
                        <td style="font-family:var(--font-latin);color:var(--c-ink-muted)"><?= (int)$u['id'] ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px">
                                <img src="<?= e(avatar_url($u['avatar'], $u['name'])) ?>" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover">
                                <div>
                                    <div style="font-weight:700"><?= e($u['name']) ?></div>
                                    <?php if ($u['roll_number']): ?><div style="font-size:var(--text-xs);color:var(--c-primary)"><?= e($u['roll_number']) ?></div><?php endif; ?>
                                    <?php if ($u['target']): ?><div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= e($u['target']) ?></div><?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="font-family:var(--font-latin);font-size:var(--text-sm)"><?= e($u['phone']) ?></div>
                            <?php if ($u['email']): ?><div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= e($u['email']) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="change_role">
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <select name="role_id" class="form-control" style="padding:4px 8px;font-size:var(--text-xs);width:auto"
                                        onchange="this.form.submit()" <?= (int)$u['role_id']===6?'disabled':'' ?>>
                                    <?php foreach ($roles as $r): ?>
                                    <?php if ($r['name']==='admin') continue; ?>
                                    <option value="<?= (int)$r['id'] ?>" <?= $u['role_id']==$r['id']?'selected':'' ?>><?= e($r['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td style="font-family:var(--font-latin);font-weight:700"><?= (int)$u['enrollment_count'] ?></td>
                        <td style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                        <td>
                            <span class="status-badge <?= $u['is_active'] ? 'status-active' : 'status-archived' ?>">
                                <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <div style="display:flex;gap:4px;flex-wrap:wrap">
                                <a href="<?= APP_URL ?>/admin/users.php?view=<?= (int)$u['id'] ?>" class="btn btn-ghost btn-sm" title="View details">
                                    <span class="material-icons-round" style="font-size:15px">person</span>
                                </a>
                                <button class="btn btn-ghost btn-sm" title="Edit info"
                                        onclick="openEditUser(<?= htmlspecialchars(json_encode([
                                            'uid'    => (int)$u['id'],
                                            'name'   => $u['name'],
                                            'email'  => $u['email'] ?? '',
                                            'school' => $u['school'] ?? '',
                                            'college'=> $u['college'] ?? '',
                                            'class'  => $u['class_level'] ?? '',
                                            'target' => $u['target'] ?? '',
                                            'parent' => $u['parent_name'] ?? '',
                                            'pphone' => $u['parent_phone'] ?? '',
                                        ]), ENT_QUOTES) ?>)">
                                    <span class="material-icons-round" style="font-size:15px">edit</span>
                                </button>
                                <?php if ((int)$u['role_id'] === ROLE_STUDENT): ?>
                                <button class="btn btn-ghost btn-sm" title="Enroll in course"
                                        onclick="openEnroll(<?= (int)$u['id'] ?>, '<?= addslashes(e($u['name'])) ?>')">
                                    <span class="material-icons-round" style="font-size:15px">school</span>
                                </button>
                                <?php endif; ?>
                                <?php if ((int)$u['role_id'] !== 6): ?>
                                <button class="btn btn-ghost btn-sm" title="Reset password"
                                        onclick="openResetPassword(<?= (int)$u['id'] ?>, '<?= addslashes(e($u['name'])) ?>')">
                                    <span class="material-icons-round" style="font-size:15px">lock_reset</span>
                                </button>
                                <?php endif; ?>
                                <?php if ((int)$u['role_id'] !== 6): ?>
                                <form method="POST" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm" title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                        <span class="material-icons-round" style="font-size:16px"><?= $u['is_active'] ? 'block' : 'check_circle' ?></span>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?= paginate($total, $page, $perPage, $urlPattern) ?>
        <?php endif; ?>
    </div>
</div>
<script>
function openEditUser(u) {
    document.getElementById('eu_uid').value    = u.uid;
    document.getElementById('eu_name').value   = u.name;
    document.getElementById('eu_email').value  = u.email;
    document.getElementById('eu_school').value = u.school;
    document.getElementById('eu_college').value= u.college;
    document.getElementById('eu_class').value  = u.class;
    document.getElementById('eu_target').value = u.target;
    document.getElementById('eu_parent').value = u.parent;
    document.getElementById('eu_pphone').value = u.pphone;
    document.getElementById('editUserModal').style.display = 'flex';
}
function openEnroll(uid, name) {
    document.getElementById('enroll_uid').value          = uid;
    document.getElementById('enrollUserName').textContent = 'Choose a course for ' + name;
    document.getElementById('enrollModal').style.display  = 'flex';
}
function openResetPassword(uid, name) {
    document.getElementById('reset_uid').value = uid;
    document.getElementById('resetPasswordUserName').textContent = 'Set a new password for ' + name;
    document.getElementById('resetPasswordModal').style.display = 'flex';
}
</script>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>
