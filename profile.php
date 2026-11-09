<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_login();

$user = current_user();
$uid  = (int)$user['id'];

// POST: update profile info
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $postAction = sanitize_string($_POST['action'] ?? '', 20);

    if ($postAction === 'kill_session') {
        $sessionId    = sanitize_int($_POST['session_id'] ?? 0);
        $currentToken = $_SESSION['_session_token'] ?? '';
        if ($sessionId) {
            $target = db_row("SELECT session_token FROM user_sessions WHERE id=? AND user_id=?", [$sessionId, $uid]);
            if ($target && $target['session_token'] !== $currentToken) {
                db_exec("DELETE FROM user_sessions WHERE id=? AND user_id=?", [$sessionId, $uid]);
                flash('success', 'You have been logged out of that device.');
            }
        }
        redirect(APP_URL.'/profile.php#edit-profile');
    }

    if ($postAction === 'update') {
        $name        = sanitize_string($_POST['name'] ?? '', 100);
        $email       = sanitize_string($_POST['email'] ?? '', 180);
        $bio         = sanitize_string($_POST['bio'] ?? '', 1000);
        $school      = sanitize_string($_POST['school'] ?? '', 200);
        $college     = sanitize_string($_POST['college'] ?? '', 200);
        $university  = sanitize_string($_POST['university'] ?? '', 200);
        $academic_bg = sanitize_string($_POST['academic_bg'] ?? '', 1000);
        $class       = sanitize_string($_POST['class'] ?? '', 50);
        $skills      = sanitize_string($_POST['skills'] ?? '', 500);
        $parent_name = sanitize_string($_POST['parent_name'] ?? '', 100);
        $parent_phone = sanitize_string($_POST['parent_phone'] ?? '', 20);
        $address     = sanitize_string($_POST['address'] ?? '', 500);

        if (!$name) { flash('error','Name is required.'); redirect(APP_URL.'/profile.php'); }

        $avatar = $user['avatar'];
        if (!empty($_FILES['avatar']['tmp_name'])) {
            $up = handle_image_upload('avatar','avatars');
            if ($up) $avatar = $up;
        }

        db_exec("UPDATE users SET name=?,email=?,bio=?,school=?,college=?,university=?,academic_bg=?,class_level=?,skills=?,parent_name=?,parent_phone=?,address=?,avatar=? WHERE id=?",
            [$name, $email ?: null, $bio, $school, $college, $university, $academic_bg, $class, $skills, $parent_name, $parent_phone, $address, $avatar, $uid]);
        flash('success','Profile updated.');
        redirect(APP_URL.'/profile.php#profile');
    }
}

$user = current_user(); // refresh after possible update

// Ensure roll number is generated
if (empty($user['roll_number'])) {
    $user['roll_number'] = ensure_roll_number($uid, $user['name']);
}

// Enrolled courses with progress
$enrolledCourses = db_query("
    SELECT c.id, c.title_bn, c.slug, c.thumbnail, c.total_lessons, c.type, c.category_id,
           cat.name_bn as cat_name, cat.color as cat_color,
           u2.name as teacher_name,
           e.progress_pct, e.enrolled_at, e.status as enroll_status
    FROM enrollments e
    JOIN courses c ON c.id=e.course_id
    JOIN categories cat ON cat.id=c.category_id
    JOIN teacher_profiles tp ON tp.id=c.teacher_id
    JOIN users u2 ON u2.id=tp.user_id
    WHERE e.user_id=? AND e.status='active'
    ORDER BY e.enrolled_at DESC
", [$uid]);

// Stats
$totalEnrolled  = count($enrolledCourses);
$totalCompleted = count(array_filter($enrolledCourses, fn($c) => (int)$c['progress_pct'] === 100));
$totalExams     = (int)db_val("SELECT COUNT(*) FROM exam_attempts WHERE user_id=? AND status='submitted'", [$uid]);
$avgScore       = (float)db_val("SELECT COALESCE(AVG(score),0) FROM exam_leaderboard WHERE user_id=?", [$uid]);
$totalHours     = (int)db_val("SELECT COALESCE(SUM(lp.watch_time),0)/3600 FROM lesson_progress lp JOIN enrollments e ON e.id=lp.enrollment_id WHERE e.user_id=?", [$uid]);
$certificates   = (int)db_val("SELECT COUNT(*) FROM enrollments WHERE user_id=? AND progress_pct=100", [$uid]);

// Exam history
$examHistory = db_query("
    SELECT ea.*, ex.title as exam_title, ex.total_marks, ex.pass_marks,
           c.title_bn as course_title
    FROM exam_attempts ea
    JOIN exams ex ON ex.id=ea.exam_id
    JOIN courses c ON c.id=ex.course_id
    WHERE ea.user_id=?
    ORDER BY ea.started_at DESC
    LIMIT 10
", [$uid]);

// Teacher feedback for this student
$teacherFeedback = db_query("
    SELECT tf.*, u.name as teacher_name, u.avatar as teacher_avatar,
           c.title_bn as course_title
    FROM teacher_feedback tf
    JOIN teacher_profiles tp ON tp.id=tf.teacher_profile_id
    JOIN users u ON u.id=tp.user_id
    JOIN courses c ON c.id=tf.course_id
    WHERE tf.student_id=?
       OR (tf.student_id IS NULL AND tf.course_id IN (
             SELECT course_id FROM enrollments WHERE user_id=? AND status='active'
           ))
    ORDER BY tf.created_at DESC
    LIMIT 20
", [$uid, $uid]);

// Recommended courses (same categories as enrolled, not yet enrolled)
$enrolledCatIds = $enrolledCourses ? implode(',', array_unique(array_map('intval', array_column($enrolledCourses, 'category_id')))) : '0';
$recommendedCourses = db_query("
    SELECT c.*, cat.name_bn as cat_name, cat.color as cat_color,
           u2.name as teacher_name, u2.avatar as teacher_avatar,
           (SELECT COUNT(*) FROM enrollments e WHERE e.course_id=c.id) as enrolled_count,
           (SELECT COALESCE(AVG(r.rating),0) FROM reviews r WHERE r.course_id=c.id) as rating_avg
    FROM courses c
    JOIN categories cat ON cat.id=c.category_id
    JOIN teacher_profiles tp ON tp.id=c.teacher_id
    JOIN users u2 ON u2.id=tp.user_id
    WHERE c.status='published'
      AND c.id NOT IN (SELECT course_id FROM enrollments WHERE user_id=?)
      AND c.category_id IN ($enrolledCatIds)
    ORDER BY c.enrolled_count DESC
    LIMIT 6
", [$uid]);

if (empty($recommendedCourses)) {
    $recommendedCourses = db_query("
        SELECT c.*, cat.name_bn as cat_name, cat.color as cat_color,
               u2.name as teacher_name, u2.avatar as teacher_avatar,
               (SELECT COUNT(*) FROM enrollments e WHERE e.course_id=c.id) as enrolled_count,
               (SELECT COALESCE(AVG(r.rating),0) FROM reviews r WHERE r.course_id=c.id) as rating_avg
        FROM courses c
        JOIN categories cat ON cat.id=c.category_id
        JOIN teacher_profiles tp ON tp.id=c.teacher_id
        JOIN users u2 ON u2.id=tp.user_id
        WHERE c.status='published' AND c.id NOT IN (SELECT course_id FROM enrollments WHERE user_id=?)
        ORDER BY c.is_featured DESC, c.enrolled_count DESC
        LIMIT 6
    ", [$uid]);
}

$page_title = 'My Profile';
$extra_css  = '<style>
.profile-hero{background:linear-gradient(135deg,#1A1033 0%,#2A0E4A 55%,#2D1B8A 100%);padding:var(--sp-12) 0 0;position:relative;overflow:hidden}
.profile-hero::before{content:"";position:absolute;top:-40%;right:-10%;width:600px;height:600px;background:radial-gradient(circle,rgba(91, 33, 182,.4) 0%,transparent 70%);pointer-events:none}
.profile-avatar-ring{width:96px;height:96px;border-radius:50%;border:4px solid rgba(255,255,255,.3);object-fit:cover;box-shadow:0 0 0 4px rgba(91, 33, 182,.4)}
.profile-tabs-bar{display:flex;gap:0;border-bottom:2px solid rgba(255,255,255,.12);margin-top:var(--sp-8)}
.profile-tab{padding:var(--sp-3) var(--sp-5);font-weight:700;font-size:var(--text-sm);color:rgba(255,255,255,.6);border-bottom:3px solid transparent;margin-bottom:-2px;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-block}
.profile-tab.active,.profile-tab:hover{color:#fff;border-bottom-color:#fff}
.stat-pill{display:flex;flex-direction:column;align-items:center;padding:var(--sp-4) var(--sp-6);background:rgba(255,255,255,.08);border-radius:var(--r-xl);border:1px solid rgba(255,255,255,.12);backdrop-filter:blur(8px)}
.stat-pill-val{font-family:var(--font-latin);font-size:var(--text-2xl);font-weight:800;color:#fff}
.stat-pill-lab{font-size:var(--text-xs);color:rgba(255,255,255,.6);margin-top:2px}
.skill-chip{display:inline-block;padding:4px 14px;border-radius:999px;background:var(--c-primary-light);color:var(--c-primary);font-size:var(--text-xs);font-weight:700;margin:3px}
.exam-row{display:flex;align-items:center;gap:var(--sp-4);padding:var(--sp-4);background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--r-lg);margin-bottom:var(--sp-3)}
.score-circle{width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:var(--font-latin);font-weight:800;font-size:var(--text-sm);flex-shrink:0}
@media(max-width:768px){.profile-tabs-bar{overflow-x:auto;scrollbar-width:none;-webkit-mask-image:linear-gradient(to right,#000 calc(100% - 32px),transparent 100%);mask-image:linear-gradient(to right,#000 calc(100% - 32px),transparent 100%)}.stat-pill{padding:var(--sp-3) var(--sp-4)}.profile-hero .hero-meta{flex-wrap:wrap}}
</style>';
include __DIR__ . '/includes/header.php';
?>

<!-- Profile Hero -->
<div class="profile-hero">
    <div class="container">
        <div style="display:flex;align-items:flex-end;gap:var(--sp-6);flex-wrap:wrap;position:relative;z-index:1">
            <div style="position:relative">
                <img src="<?= e(avatar_url($user['avatar'],$user['name'])) ?>"
                     alt="<?= e($user['name']) ?>" class="profile-avatar-ring" id="avatarImg">
                <label for="avatarFile" style="position:absolute;bottom:0;right:0;width:28px;height:28px;border-radius:50%;background:var(--c-primary);display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.3)">
                    <span class="material-icons-round" style="font-size:15px;color:#fff">edit</span>
                </label>
            </div>
            <div style="flex:1;min-width:0">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:4px">
                    <div style="font-size:var(--text-xs);font-weight:700;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.1em">Student</div>
                    <?php if (!empty($user['roll_number'])): ?>
                    <div style="background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);border-radius:999px;padding:2px 12px;font-family:var(--font-latin);font-size:var(--text-xs);font-weight:800;color:rgba(255,255,255,.85);letter-spacing:.05em;display:flex;align-items:center;gap:5px">
                        <span class="material-icons-round" style="font-size:12px">badge</span>
                        <?= e($user['roll_number']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <h1 style="color:#fff;font-size:clamp(1.5rem,3vw,2rem);margin-bottom:4px"><?= e($user['name']) ?></h1>
                <div class="hero-meta" style="display:flex;gap:var(--sp-4);flex-wrap:wrap;color:rgba(255,255,255,.6);font-size:var(--text-sm);margin-top:4px">
                    <?php if (!empty($user['phone'])): ?>
                    <span><span class="material-icons-round" style="font-size:14px;vertical-align:middle">phone</span> <?= e($user['phone']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($user['school'])): ?>
                    <span><span class="material-icons-round" style="font-size:14px;vertical-align:middle">school</span> <?= e($user['school']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($user['class_level'])): ?>
                    <span><span class="material-icons-round" style="font-size:14px;vertical-align:middle">grade</span> <?= e($user['class_level']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <a href="#edit-profile" class="btn btn-white btn-sm" style="flex-shrink:0">
                <span class="material-icons-round">edit</span> Edit Profile
            </a>
        </div>

        <!-- Stats pills -->
        <div style="display:flex;gap:var(--sp-3);margin-top:var(--sp-8);flex-wrap:wrap;position:relative;z-index:1">
            <div class="stat-pill">
                <span class="stat-pill-val"><?= $totalEnrolled ?></span>
                <span class="stat-pill-lab">Courses Enrolled</span>
            </div>
            <div class="stat-pill">
                <span class="stat-pill-val"><?= $totalCompleted ?></span>
                <span class="stat-pill-lab">Completed</span>
            </div>
            <div class="stat-pill">
                <span class="stat-pill-val"><?= $totalExams ?></span>
                <span class="stat-pill-lab">Exams Taken</span>
            </div>
            <div class="stat-pill">
                <span class="stat-pill-val"><?= number_format($avgScore, 1) ?>%</span>
                <span class="stat-pill-lab">Average Score</span>
            </div>
            <div class="stat-pill">
                <span class="stat-pill-val"><?= $totalHours ?>h</span>
                <span class="stat-pill-lab">Learning Time</span>
            </div>
            <div class="stat-pill">
                <span class="stat-pill-val"><?= $certificates ?></span>
                <span class="stat-pill-lab">Certificates</span>
            </div>
        </div>

        <!-- Tabs -->
        <div class="profile-tabs-bar" style="margin-top:var(--sp-6)">
            <a href="#overview" class="profile-tab active" data-profile-tab="overview">Overview</a>
            <a href="#my-courses" class="profile-tab" data-profile-tab="my-courses">My Courses</a>
            <a href="#exams" class="profile-tab" data-profile-tab="exams">Exams</a>
            <a href="#feedback" class="profile-tab" data-profile-tab="feedback">
                Feedback
                <?php if (!empty($teacherFeedback)): ?><span style="background:#EF4444;color:#fff;border-radius:999px;padding:1px 7px;font-size:10px;font-weight:800;margin-left:4px"><?= count($teacherFeedback) ?></span><?php endif; ?>
            </a>
            <a href="#recommendations" class="profile-tab" data-profile-tab="recommendations">Recommendations</a>
            <a href="#edit-profile" class="profile-tab" data-profile-tab="edit-profile">Edit</a>
        </div>
    </div>
</div>

<!-- Tab content -->
<div class="container" style="padding-top:var(--sp-10);padding-bottom:var(--sp-16)">

    <!-- Overview Tab -->
    <div class="profile-panel active" id="panel-overview">
        <div style="display:grid;grid-template-columns:1fr 340px;gap:var(--sp-8)" class="profile-two-col">
            <div>
                <?php if (!empty($user['bio'])): ?>
                <div class="card" style="padding:var(--sp-6);margin-bottom:var(--sp-5)">
                    <h3 style="margin-bottom:var(--sp-4)">About</h3>
                    <p style="color:var(--c-ink-light);line-height:1.8"><?= nl2br(e($user['bio'])) ?></p>
                </div>
                <?php endif; ?>

                <?php
                $hasAcademic = !empty($user['school']) || !empty($user['college']) || !empty($user['university']) || !empty($user['academic_bg']);
                if ($hasAcademic): ?>
                <div class="card" style="padding:var(--sp-6);margin-bottom:var(--sp-5)">
                    <h3 style="margin-bottom:var(--sp-4)">Academic Information</h3>
                    <?php $acadItems = [
                        ['school','School',$user['school']??''],
                        ['account_balance','College',$user['college']??''],
                        ['account_balance_wallet','University',$user['university']??''],
                        ['grade','Level',$user['class_level']??''],
                    ]; foreach ($acadItems as [$icon,$lab,$val]): if (!$val) continue; ?>
                    <div style="display:flex;align-items:center;gap:var(--sp-3);padding:var(--sp-2) 0;border-bottom:1px solid var(--c-border);font-size:var(--text-sm)">
                        <span class="material-icons-round" style="font-size:18px;color:var(--c-primary)"><?= $icon ?></span>
                        <span style="color:var(--c-ink-muted);min-width:80px"><?= $lab ?></span>
                        <span style="font-weight:600;color:var(--c-ink)"><?= e($val) ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!empty($user['academic_bg'])): ?>
                    <p style="color:var(--c-ink-light);line-height:1.8;margin-top:var(--sp-3);font-size:var(--text-sm)"><?= nl2br(e($user['academic_bg'])) ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($user['skills'])): ?>
                <div class="card" style="padding:var(--sp-6);margin-bottom:var(--sp-5)">
                    <h3 style="margin-bottom:var(--sp-4)">Skills & Interests</h3>
                    <div>
                        <?php foreach (explode(',', $user['skills']) as $sk): ?>
                        <?php if (trim($sk)): ?>
                        <span class="skill-chip"><?= e(trim($sk)) ?></span>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent courses -->
                <div class="card" style="padding:var(--sp-6)">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-5)">
                        <h3>Recent Courses</h3>
                        <a href="#" class="view-all-link" data-switch-tab="my-courses">View All →</a>
                    </div>
                    <?php if (empty($enrolledCourses)): ?>
                    <div style="text-align:center;padding:var(--sp-10);color:var(--c-ink-muted)">
                        <span class="material-icons-round" style="font-size:48px;display:block;margin-bottom:12px">school</span>
                        You haven't enrolled in any courses yet.
                        <div style="margin-top:12px"><a href="<?= APP_URL ?>/courses.php" class="btn btn-primary btn-sm">View Courses</a></div>
                    </div>
                    <?php else: ?>
                    <div style="display:flex;flex-direction:column;gap:var(--sp-3)">
                        <?php foreach (array_slice($enrolledCourses, 0, 4) as $ec): ?>
                        <a href="<?= APP_URL ?>/learn.php?course=<?= (int)$ec['id'] ?>"
                           style="display:flex;gap:var(--sp-4);align-items:center;text-decoration:none;padding:var(--sp-3);border-radius:var(--r-md);transition:background .2s"
                           onmouseover="this.style.background='var(--c-surface)'" onmouseout="this.style.background=''">
                            <img src="<?= e(course_thumbnail_url($ec['thumbnail'], $ec['title_bn'] ?? 'Course', '80x45')) ?>"
                                 style="width:80px;height:45px;object-fit:cover;border-radius:var(--r-sm);flex-shrink:0">
                            <div style="flex:1;min-width:0">
                                <div style="font-weight:700;font-size:var(--text-sm);color:var(--c-ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($ec['title_bn']) ?></div>
                                <div class="progress-bar" style="margin-top:6px">
                                    <div class="progress-fill" style="width:<?= (int)$ec['progress_pct'] ?>%"></div>
                                </div>
                                <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:3px"><?= (int)$ec['progress_pct'] ?>% complete</div>
                            </div>
                            <?php if ((int)$ec['progress_pct'] === 100): ?>
                            <span class="material-icons-round" style="color:var(--c-success);flex-shrink:0">verified</span>
                            <?php else: ?>
                            <span class="material-icons-round" style="color:var(--c-primary);flex-shrink:0">play_circle</span>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right sidebar -->
            <div>
                <!-- QR Code Card -->
                <?php if (!empty($user['roll_number'])): ?>
                <div class="card" style="padding:var(--sp-5);margin-bottom:var(--sp-5);text-align:center;background:linear-gradient(135deg,#1e1b4b 0%,#312e81 100%);border:none">
                    <div style="font-size:var(--text-xs);font-weight:700;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.12em;margin-bottom:var(--sp-3)">
                        My ID Card
                    </div>
                    <div id="profileQR" style="width:160px;height:160px;margin:0 auto var(--sp-3);background:#fff;border-radius:12px;padding:8px;display:flex;align-items:center;justify-content:center"></div>
                    <div style="font-family:var(--font-latin);font-size:var(--text-lg);font-weight:900;color:#fff;letter-spacing:.08em;margin-bottom:4px">
                        <?= e($user['roll_number']) ?>
                    </div>
                    <div style="font-size:var(--text-xs);color:rgba(255,255,255,.55);margin-bottom:var(--sp-4)">
                        Scan the QR code — view info
                    </div>
                    <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
                        <a href="<?= APP_URL ?>/student.php?roll=<?= urlencode($user['roll_number']) ?>" target="_blank"
                           style="display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);border-radius:8px;padding:6px 14px;color:rgba(255,255,255,.85);font-size:var(--text-xs);font-weight:700;text-decoration:none">
                            <span class="material-icons-round" style="font-size:13px">open_in_new</span>
                            Public Card
                        </a>
                        <button id="downloadQR"
                           style="display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);border-radius:8px;padding:6px 14px;color:rgba(255,255,255,.85);font-size:var(--text-xs);font-weight:700;cursor:pointer">
                            <span class="material-icons-round" style="font-size:13px">download</span>
                            Download QR
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Certificates -->
                <?php if ($certificates > 0): ?>
                <div class="card" style="padding:var(--sp-5);margin-bottom:var(--sp-5);background:linear-gradient(135deg,#F59E0B,#E5850A);color:#fff;border:none">
                    <div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:var(--sp-3)">
                        <span class="material-icons-round" style="font-size:32px">workspace_premium</span>
                        <div>
                            <div style="font-family:var(--font-latin);font-size:var(--text-2xl);font-weight:800"><?= $certificates ?></div>
                            <div style="font-size:var(--text-sm);opacity:.85">Course Certificates</div>
                        </div>
                    </div>
                    <a href="<?= APP_URL ?>/certificates.php" class="btn btn-white btn-sm btn-block">View Certificates</a>
                </div>
                <?php endif; ?>

                <!-- Quick actions -->
                <div class="card" style="padding:var(--sp-5);margin-bottom:var(--sp-5)">
                    <h4 style="margin-bottom:var(--sp-4)">Quick Access</h4>
                    <?php $quickLinks = [
                        [APP_URL.'/','home','Home Page'],
                        [APP_URL.'/courses.php','school','Courses Page'],
                        [APP_URL.'/stories.php','auto_stories','Success Stories'],
                        [APP_URL.'/exams.php','quiz','Take an Exam'],
                        [APP_URL.'/my-courses.php','play_circle','My Courses'],
                        [APP_URL.'/certificates.php','workspace_premium','Certificates'],
                        [APP_URL.'/orders.php','receipt','Order History'],
                        [APP_URL.'/help.php','help','Help Center'],
                    ]; foreach ($quickLinks as $ql): ?>
                    <a href="<?= $ql[0] ?>" style="display:flex;align-items:center;gap:var(--sp-3);padding:var(--sp-2) 0;color:var(--c-ink-light);font-size:var(--text-sm);font-weight:600;text-decoration:none;border-bottom:1px solid var(--c-border)">
                        <span class="material-icons-round" style="font-size:18px;color:var(--c-primary)"><?= $ql[1] ?></span>
                        <?= $ql[2] ?>
                        <span class="material-icons-round" style="font-size:14px;margin-left:auto;color:var(--c-ink-faint)">chevron_right</span>
                    </a>
                    <?php endforeach; ?>
                </div>

                <!-- Recent exam -->
                <?php if (!empty($examHistory)): ?>
                <div class="card" style="padding:var(--sp-5)">
                    <h4 style="margin-bottom:var(--sp-4)">Recent Exam</h4>
                    <?php $ex = $examHistory[0]; $pct = $ex['total_marks'] > 0 ? round($ex['score']/$ex['total_marks']*100) : 0; ?>
                    <div style="text-align:center;padding:var(--sp-4)">
                        <div style="width:72px;height:72px;border-radius:50%;background:<?= $pct>=60?'var(--c-success-light)':'var(--c-error-light)' ?>;border:4px solid <?= $pct>=60?'var(--c-success)':'var(--c-error)' ?>;display:flex;align-items:center;justify-content:center;margin:0 auto var(--sp-3)">
                            <span style="font-family:var(--font-latin);font-weight:800;font-size:var(--text-lg);color:<?= $pct>=60?'var(--c-success)':'var(--c-error)' ?>"><?= $pct ?>%</span>
                        </div>
                        <div style="font-weight:700;font-size:var(--text-sm)"><?= e($ex['exam_title']) ?></div>
                        <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:4px"><?= date('d M Y', strtotime($ex['started_at'])) ?></div>
                    </div>
                    <a href="<?= APP_URL ?>/exams.php" class="btn btn-primary btn-sm btn-block">View All Exams</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- My Courses Tab -->
    <div class="profile-panel" id="panel-my-courses" style="display:none">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
            <h2>My Courses <span style="font-family:var(--font-latin);font-size:var(--text-lg);font-weight:600;color:var(--c-ink-muted)"><?= $totalEnrolled ?></span></h2>
            <a href="<?= APP_URL ?>/courses.php" class="btn btn-primary btn-sm">
                <span class="material-icons-round">add</span> New Course
            </a>
        </div>

        <?php if (empty($enrolledCourses)): ?>
        <div style="text-align:center;padding:var(--sp-20);background:var(--c-surface);border-radius:var(--r-xl);border:2px dashed var(--c-border)">
            <span class="material-icons-round" style="font-size:64px;color:var(--c-ink-faint);display:block;margin-bottom:16px">school</span>
            <h3 style="color:var(--c-ink-light);margin-bottom:8px">No Courses Yet</h3>
            <p style="color:var(--c-ink-muted)">Enroll in a course today and start learning.</p>
            <a href="<?= APP_URL ?>/courses.php" class="btn btn-primary" style="margin-top:16px">View Courses</a>
        </div>
        <?php else: ?>
        <!-- In progress -->
        <?php $inProgress = array_filter($enrolledCourses, fn($c) => (int)$c['progress_pct'] < 100); ?>
        <?php $completed  = array_filter($enrolledCourses, fn($c) => (int)$c['progress_pct'] === 100); ?>

        <?php if ($inProgress): ?>
        <h3 style="font-size:var(--text-lg);margin-bottom:var(--sp-4)">In Progress</h3>
        <div class="grid-3" style="margin-bottom:var(--sp-8)">
            <?php foreach ($inProgress as $ec): ?>
            <a href="<?= APP_URL ?>/learn.php?course=<?= (int)$ec['id'] ?>" style="text-decoration:none">
                <div class="course-card">
                    <div class="course-thumb">
                        <img src="<?= e(course_thumbnail_url($ec['thumbnail'], $ec['title_bn'] ?? 'Course', '600x340')) ?>" alt="" loading="lazy">
                        <span class="course-badge" style="background:<?= e($ec['cat_color']) ?>"><?= e($ec['cat_name']) ?></span>
                    </div>
                    <div class="course-body">
                        <h3 class="course-title"><?= e($ec['title_bn']) ?></h3>
                        <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-bottom:var(--sp-3)"><?= e($ec['teacher_name']) ?></div>
                        <div style="margin-top:auto">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                                <span style="font-size:var(--text-xs);color:var(--c-ink-muted)">Progress</span>
                                <span style="font-family:var(--font-latin);font-size:var(--text-xs);font-weight:700;color:var(--c-primary)"><?= (int)$ec['progress_pct'] ?>%</span>
                            </div>
                            <div class="progress-bar"><div class="progress-fill" style="width:<?= (int)$ec['progress_pct'] ?>%"></div></div>
                            <div style="margin-top:var(--sp-3)">
                                <span class="btn btn-primary btn-sm btn-block" style="pointer-events:none">Continue Learning</span>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($completed): ?>
        <h3 style="font-size:var(--text-lg);margin-bottom:var(--sp-4)">Completed Courses</h3>
        <div class="grid-3">
            <?php foreach ($completed as $ec): ?>
            <a href="<?= APP_URL ?>/learn.php?course=<?= (int)$ec['id'] ?>" style="text-decoration:none">
                <div class="course-card" style="border-color:var(--c-success)">
                    <div class="course-thumb">
                        <img src="<?= e(course_thumbnail_url($ec['thumbnail'], $ec['title_bn'] ?? 'Done', '600x340', '15BE8B')) ?>" alt="" loading="lazy">
                        <span class="course-badge" style="background:var(--c-success)">Completed</span>
                    </div>
                    <div class="course-body">
                        <h3 class="course-title"><?= e($ec['title_bn']) ?></h3>
                        <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-bottom:var(--sp-3)"><?= e($ec['teacher_name']) ?></div>
                        <div style="margin-top:auto;display:flex;gap:var(--sp-2)">
                            <span class="btn btn-success btn-sm" style="flex:1;pointer-events:none">
                                <span class="material-icons-round" style="font-size:14px">check_circle</span> 100%
                            </span>
                            <a href="<?= APP_URL ?>/certificates.php?course=<?= (int)$ec['id'] ?>" class="btn btn-ghost btn-sm" title="Certificate">
                                <span class="material-icons-round" style="font-size:14px">workspace_premium</span>
                            </a>
                        </div>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Exams Tab -->
    <div class="profile-panel" id="panel-exams" style="display:none">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
            <h2>Exam History</h2>
            <a href="<?= APP_URL ?>/exams.php" class="btn btn-primary btn-sm">
                <span class="material-icons-round">quiz</span> Take an Exam
            </a>
        </div>

        <?php if (empty($examHistory)): ?>
        <div style="text-align:center;padding:var(--sp-20);background:var(--c-surface);border-radius:var(--r-xl);border:2px dashed var(--c-border)">
            <span class="material-icons-round" style="font-size:64px;color:var(--c-ink-faint);display:block;margin-bottom:16px">quiz</span>
            <h3 style="color:var(--c-ink-light)">You haven't taken any exams yet</h3>
            <a href="<?= APP_URL ?>/exams.php" class="btn btn-primary" style="margin-top:16px">Take an Exam</a>
        </div>
        <?php else: ?>
        <!-- Summary bar -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:var(--sp-4);margin-bottom:var(--sp-8)" class="exam-summary-grid">
            <div class="card" style="padding:var(--sp-5);text-align:center">
                <div style="font-family:var(--font-latin);font-size:var(--text-2xl);font-weight:800;color:var(--c-primary)"><?= $totalExams ?></div>
                <div style="font-size:var(--text-xs);color:var(--c-ink-muted)">Total Exams</div>
            </div>
            <div class="card" style="padding:var(--sp-5);text-align:center">
                <div style="font-family:var(--font-latin);font-size:var(--text-2xl);font-weight:800;color:var(--c-success)"><?= number_format($avgScore,1) ?>%</div>
                <div style="font-size:var(--text-xs);color:var(--c-ink-muted)">Average Score</div>
            </div>
            <div class="card" style="padding:var(--sp-5);text-align:center">
                <?php $passed = array_filter($examHistory, fn($e) => $e['pass_marks'] > 0 && $e['score'] >= $e['pass_marks']); ?>
                <div style="font-family:var(--font-latin);font-size:var(--text-2xl);font-weight:800;color:var(--c-success)"><?= count($passed) ?></div>
                <div style="font-size:var(--text-xs);color:var(--c-ink-muted)">Passed</div>
            </div>
            <div class="card" style="padding:var(--sp-5);text-align:center">
                <?php $bestScore = max(array_column($examHistory,'score')); ?>
                <div style="font-family:var(--font-latin);font-size:var(--text-2xl);font-weight:800;color:var(--c-warning)"><?= number_format($bestScore,0) ?></div>
                <div style="font-size:var(--text-xs);color:var(--c-ink-muted)">Highest Score</div>
            </div>
        </div>

        <div style="display:flex;flex-direction:column;gap:var(--sp-3)">
            <?php foreach ($examHistory as $ea):
                $pct = $ea['total_marks'] > 0 ? round($ea['score']/$ea['total_marks']*100) : 0;
                $passed = $ea['pass_marks'] > 0 && $ea['score'] >= $ea['pass_marks'];
                $color = $pct >= 80 ? 'var(--c-success)' : ($pct >= 50 ? 'var(--c-warning)' : 'var(--c-error)');
                $bg = $pct >= 80 ? 'var(--c-success-light)' : ($pct >= 50 ? 'var(--c-warning-light)' : 'var(--c-error-light)');
            ?>
            <div class="exam-row">
                <div class="score-circle" style="background:<?= $bg ?>;border:3px solid <?= $color ?>;color:<?= $color ?>">
                    <?= $pct ?>%
                </div>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:700;font-size:var(--text-sm);color:var(--c-ink)"><?= e($ea['exam_title']) ?></div>
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:2px"><?= e($ea['course_title']) ?></div>
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:4px">
                        Score: <strong><?= number_format((float)$ea['score'],0) ?></strong>/<?= (int)$ea['total_marks'] ?>
                        <?php if ($ea['rank']): ?> · Rank: <strong>#<?= (int)$ea['rank'] ?></strong><?php endif; ?>
                    </div>
                </div>
                <div style="text-align:right;flex-shrink:0">
                    <span class="status-badge <?= $passed ? 'status-active' : 'status-archived' ?>" style="display:inline-block;margin-bottom:4px">
                        <?= $passed ? 'Passed' : 'Failed' ?>
                    </span>
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= date('d M Y', strtotime($ea['started_at'])) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Feedback Tab -->
    <div class="profile-panel" id="panel-feedback" style="display:none">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
            <h2>Teacher Feedback</h2>
        </div>
        <?php if (empty($teacherFeedback)): ?>
        <div style="text-align:center;padding:var(--sp-20);background:var(--c-surface);border-radius:var(--r-xl);border:2px dashed var(--c-border)">
            <span class="material-icons-round" style="font-size:64px;color:var(--c-ink-faint);display:block;margin-bottom:16px">feedback</span>
            <h3 style="color:var(--c-ink-light)">No Feedback Yet</h3>
            <p style="color:var(--c-ink-muted)">When teachers review your progress and leave feedback, you'll see it here.</p>
        </div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:var(--sp-4)">
            <?php foreach ($teacherFeedback as $fb): ?>
            <div class="card" style="padding:var(--sp-6)">
                <div style="display:flex;align-items:center;gap:var(--sp-4);margin-bottom:var(--sp-4)">
                    <img src="<?= e(avatar_url($fb['teacher_avatar'],$fb['teacher_name'])) ?>"
                         style="width:44px;height:44px;border-radius:50%;object-fit:cover;flex-shrink:0" alt="">
                    <div style="flex:1">
                        <div style="font-weight:700"><?= e($fb['teacher_name']) ?></div>
                        <div style="font-size:var(--text-xs);color:var(--c-primary)"><?= e($fb['course_title']) ?></div>
                        <?php if ($fb['student_id'] === null): ?>
                        <span style="font-size:10px;background:var(--c-warning-light);color:var(--c-warning);border-radius:999px;padding:1px 8px;font-weight:700">For everyone</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= date('d M Y', strtotime($fb['created_at'])) ?></div>
                </div>
                <div style="font-weight:700;font-size:var(--text-base);margin-bottom:var(--sp-2)"><?= e($fb['subject']) ?></div>
                <p style="color:var(--c-ink-light);line-height:1.8;margin:0"><?= nl2br(e($fb['body'])) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Recommendations Tab -->
    <div class="profile-panel" id="panel-recommendations" style="display:none">
        <h2 style="margin-bottom:var(--sp-2)">Courses Recommended for You</h2>
        <p style="color:var(--c-ink-muted);margin-bottom:var(--sp-8)">Picked based on your learning pattern and enrolled courses.</p>
        <?php if (empty($recommendedCourses)): ?>
        <div style="text-align:center;padding:var(--sp-16);color:var(--c-ink-muted)">
            <span class="material-icons-round" style="font-size:56px;display:block;margin-bottom:12px">recommend</span>
            <h3>No Recommendations Available</h3>
            <p>Visit the explore page to see more courses.</p>
            <a href="<?= APP_URL ?>/courses.php" class="btn btn-primary" style="margin-top:12px">View All Courses</a>
        </div>
        <?php else: ?>
        <div class="grid-3">
            <?php foreach ($recommendedCourses as $course):
                $isFree = (int)($course['is_free'] ?? 0);
                $hasDiscount = !$isFree && isset($course['sale_price']) && $course['sale_price'] > 0 && $course['sale_price'] < $course['price'];
                $displayPrice = $hasDiscount ? $course['sale_price'] : $course['price'];
            ?>
            <?php include __DIR__ . '/includes/course_card.php'; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Edit Profile Tab -->
    <div class="profile-panel" id="panel-edit-profile" style="display:none">
        <h2 style="margin-bottom:var(--sp-8)">Edit Profile</h2>
        <div style="display:grid;grid-template-columns:1fr 300px;gap:var(--sp-8)" class="edit-grid">
            <div class="card" style="padding:var(--sp-6)">
                <form method="POST" enctype="multipart/form-data" id="profileForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="file" name="avatar" id="avatarFile" accept="image/*" style="display:none"
                           onchange="previewAvatar(this)">

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)" class="edit-fields">
                        <div class="form-group" style="grid-column:1/-1">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="name" class="form-control" required value="<?= e($user['name']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="tel" class="form-control" value="<?= e($user['phone']) ?>" disabled>
                            <div class="form-hint">Contact support to change your phone number.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= e($user['email'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">School</label>
                            <input type="text" name="school" class="form-control" value="<?= e($user['school'] ?? '') ?>" placeholder="School name">
                        </div>
                        <div class="form-group">
                            <label class="form-label">College</label>
                            <input type="text" name="college" class="form-control" value="<?= e($user['college'] ?? '') ?>" placeholder="College name">
                        </div>
                        <div class="form-group">
                            <label class="form-label">University</label>
                            <input type="text" name="university" class="form-control" value="<?= e($user['university'] ?? '') ?>" placeholder="University name">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Class / Level</label>
                            <select name="class" class="form-control">
                                <option value="">Choose</option>
                                <?php foreach (['JSC','SSC','HSC','Honours','Masters','Professional'] as $cl): ?>
                                <option value="<?= $cl ?>" <?= ($user['class_level']??'')===$cl?'selected':'' ?>><?= $cl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label class="form-label">Academic Background</label>
                            <textarea name="academic_bg" class="form-control" rows="3" placeholder="Your field of study, department, results, etc..."><?= e($user['academic_bg'] ?? '') ?></textarea>
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label class="form-label">Bio</label>
                            <textarea name="bio" class="form-control" rows="4" placeholder="Write something about yourself..."><?= e($user['bio'] ?? '') ?></textarea>
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label class="form-label">Skills & Interests <small style="font-weight:400">(separate with commas)</small></label>
                            <input type="text" name="skills" class="form-control" value="<?= e($user['skills'] ?? '') ?>" placeholder="Physics, English, Coding">
                        </div>
                        <div style="grid-column:1/-1"><hr style="border:none;border-top:1px solid var(--c-border);margin:var(--sp-2) 0"><p style="font-size:var(--text-xs);color:var(--c-ink-muted);font-weight:700;margin-bottom:var(--sp-3)">Parent & Contact Information</p></div>
                        <div class="form-group">
                            <label class="form-label">Parent's Name</label>
                            <input type="text" name="parent_name" class="form-control" value="<?= e($user['parent_name'] ?? '') ?>" placeholder="Father's/Mother's name">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Parent's Phone</label>
                            <input type="tel" name="parent_phone" class="form-control" value="<?= e($user['parent_phone'] ?? '') ?>" placeholder="01XXXXXXXXX">
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label class="form-label">Current Address</label>
                            <textarea name="address" class="form-control" rows="2" placeholder="Village/Area, Thana, District..."><?= e($user['address'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <span class="material-icons-round">save</span> Save Changes
                    </button>
                </form>
            </div>
            <div>
                <div class="card" style="padding:var(--sp-6);text-align:center">
                    <h4 style="margin-bottom:var(--sp-4)">Profile Photo</h4>
                    <img src="<?= e(avatar_url($user['avatar'],$user['name'])) ?>"
                         id="avatarPreview"
                         style="width:100px;height:100px;border-radius:50%;object-fit:cover;border:4px solid var(--c-primary-light);margin:0 auto var(--sp-4);display:block">
                    <label for="avatarFile" class="btn btn-outline btn-sm btn-block" style="cursor:pointer">
                        <span class="material-icons-round">photo_camera</span> Change Photo
                    </label>
                    <p style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:8px">JPG/PNG/WebP · up to 10MB</p>
                </div>
                <div class="card" style="padding:var(--sp-5);margin-top:var(--sp-4)">
                    <h4 style="margin-bottom:var(--sp-4)">Account</h4>
                    <a href="<?= APP_URL ?>/settings.php" class="btn btn-ghost btn-sm btn-block" style="margin-bottom:8px">
                        <span class="material-icons-round">settings</span> Settings
                    </a>
                    <a href="<?= APP_URL ?>/logout.php?token=<?= csrf_token() ?>" class="btn btn-ghost btn-sm btn-block" style="color:var(--c-error)">
                        <span class="material-icons-round">logout</span> Log Out
                    </a>
                </div>

                <!-- Active sessions -->
                <?php
                $activeSessions  = get_active_sessions($uid);
                $currentToken    = $_SESSION['_session_token'] ?? '';
                ?>
                <div class="card" style="padding:var(--sp-5);margin-top:var(--sp-4)">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-4)">
                        <h4 style="margin:0">Active Sessions</h4>
                        <span style="font-size:var(--text-xs);font-weight:700;background:var(--c-primary-light);color:var(--c-primary);border-radius:999px;padding:2px 10px"><?= count($activeSessions) ?>/<?= MAX_DEVICE_SESSIONS ?></span>
                    </div>
                    <p style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-bottom:var(--sp-4)">The same account can be logged in on up to <?= MAX_DEVICE_SESSIONS ?> devices.</p>
                    <?php foreach ($activeSessions as $s):
                        $isCurrent = $s['id'] === (int)db_val("SELECT id FROM user_sessions WHERE session_token=?", [$currentToken]);
                    ?>
                    <div style="display:flex;align-items:center;gap:var(--sp-3);padding:var(--sp-3) 0;border-bottom:1px solid var(--c-border)">
                        <span class="material-icons-round" style="font-size:22px;color:<?= $isCurrent ? 'var(--c-success)' : 'var(--c-ink-muted)' ?>;flex-shrink:0">devices</span>
                        <div style="flex:1;min-width:0">
                            <div style="font-weight:700;font-size:var(--text-sm)">
                                <?= e($s['device_name'] ?? 'Unknown device') ?>
                                <?php if ($isCurrent): ?>
                                <span style="font-size:10px;background:var(--c-success-light);color:var(--c-success);border-radius:999px;padding:1px 8px;font-weight:700;margin-left:4px">This device</span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:2px">
                                IP: <?= e($s['ip'] ?? '?') ?> · <?= date('d M, H:i', strtotime($s['last_seen_at'])) ?>
                            </div>
                        </div>
                        <?php if (!$isCurrent): ?>
                        <form method="POST" style="flex-shrink:0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="kill_session">
                            <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="background:var(--c-error-light);color:var(--c-error);border:none;padding:4px 10px"
                                    onclick="return confirm('Log out from this device?')">
                                <span class="material-icons-round" style="font-size:14px">logout</span>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($activeSessions)): ?>
                    <p style="font-size:var(--text-xs);color:var(--c-ink-muted);text-align:center;padding:var(--sp-4) 0">No active sessions.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<style>
@media(max-width:1024px){.profile-two-col{grid-template-columns:1fr !important}}
@media(max-width:768px){
    .exam-summary-grid{grid-template-columns:repeat(2,1fr) !important}
    .edit-grid{grid-template-columns:1fr !important}
    .edit-fields{grid-template-columns:1fr !important}
    .profile-tabs-bar{padding-bottom:var(--sp-2)}
}
@media(max-width:480px){.exam-summary-grid{grid-template-columns:1fr 1fr !important}}
@media(max-width:400px){.exam-summary-grid{grid-template-columns:1fr !important}}
</style>

<script>
function previewAvatar(input) {
    if (input.files && input.files[0]) {
        const r = new FileReader();
        r.onload = e => {
            document.getElementById('avatarImg').src = e.target.result;
            document.getElementById('avatarPreview').src = e.target.result;
        };
        r.readAsDataURL(input.files[0]);
        document.getElementById('profileForm').dispatchEvent(new Event('change'));
    }
}

// Tab system
const tabs    = document.querySelectorAll('[data-profile-tab]');
const panels  = document.querySelectorAll('.profile-panel');

function switchTab(name) {
    tabs.forEach(t => t.classList.toggle('active', t.dataset.profileTab === name));
    panels.forEach(p => {
        const show = p.id === 'panel-' + name;
        p.style.display = show ? 'block' : 'none';
        p.classList.toggle('active', show);
    });
    history.replaceState(null,'','#'+name);
}

tabs.forEach(t => t.addEventListener('click', e => { e.preventDefault(); switchTab(t.dataset.profileTab); }));

// "View All →" links that switch tabs
document.querySelectorAll('[data-switch-tab]').forEach(el => {
    el.addEventListener('click', e => { e.preventDefault(); switchTab(el.dataset.switchTab); });
});

// Init from hash
const hash = (location.hash || '').replace('#','');
const valid = ['overview','my-courses','exams','feedback','recommendations','edit-profile'];
if (valid.includes(hash)) switchTab(hash);
</script>

<?php if (!empty($user['roll_number'])): ?>
<script src="<?= APP_URL ?>/assets/js/qrcode.min.js"></script>
<script>
(function() {
    const el = document.getElementById('profileQR');
    if (!el) return;
    if (typeof QRCode === 'undefined') {
        console.error("QRCode library failed to load");
        return;
    }
    new QRCode(el, {
        text: '<?= APP_URL ?>/student.php?roll=<?= urlencode($user['roll_number']) ?>',
        width: 144,
        height: 144,
        colorDark: '#1e1b4b',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.H
    });

    // Download QR button
    const dlBtn = document.getElementById('downloadQR');
    if (dlBtn) {
        dlBtn.addEventListener('click', function() {
            setTimeout(() => {
                const img = el.querySelector('img') || el.querySelector('canvas');
                if (!img) return;
                const a = document.createElement('a');
                if (img.tagName === 'CANVAS') {
                    a.href = img.toDataURL('image/png');
                } else {
                    a.href = img.src;
                }
                a.download = 'QR-<?= e($user['roll_number']) ?>.png';
                a.click();
            }, 100);
        });
    }
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

