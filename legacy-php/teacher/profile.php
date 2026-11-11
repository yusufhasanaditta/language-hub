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

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $postAction = sanitize_string($_POST['action'] ?? '', 20);

    if ($postAction === 'save_all') {
        // ── User table fields ──
        $name  = sanitize_string($_POST['name'] ?? '', 100);
        $email = sanitize_string($_POST['email'] ?? '', 180);
        if (!$name) { flash('error','Name is required.'); redirect(APP_URL.'/teacher/profile.php'); }

        $avatar = $user['avatar'];
        if (!empty($_FILES['avatar']['tmp_name'])) {
            $up = handle_image_upload('avatar','avatars');
            if ($up) $avatar = $up;
        }
        db_exec("UPDATE users SET name=?,email=?,avatar=? WHERE id=?", [$name, $email ?: null, $avatar, $uid]);

        // ── Teacher profile fields ──
        $headline  = sanitize_string($_POST['headline'] ?? '', 255);
        $bio       = sanitize_string($_POST['bio'] ?? '', 3000);
        $expertise = sanitize_string($_POST['expertise'] ?? '', 500);
        $fb_url    = sanitize_string($_POST['fb_url'] ?? '', 300);
        $yt_url    = sanitize_string($_POST['yt_url'] ?? '', 300);
        $website   = sanitize_string($_POST['website'] ?? '', 300);

        db_exec("UPDATE teacher_profiles SET headline=?,bio=?,expertise=?,fb_url=?,yt_url=?,website=?,updated_at=NOW() WHERE id=?",
            [$headline,$bio,$expertise,$fb_url,$yt_url,$website,$tpId]);

        // ── Optional: new education entry (added if either field is filled) ──
        $degree      = sanitize_string($_POST['degree'] ?? '', 200);
        $institution = sanitize_string($_POST['institution'] ?? '', 300);
        if ($degree !== '' || $institution !== '') {
            $year_from = sanitize_int($_POST['year_from'] ?? 0) ?: null;
            $year_to   = sanitize_int($_POST['year_to'] ?? 0) ?: null;
            $desc      = sanitize_string($_POST['description'] ?? '', 500);
            db_insert("INSERT INTO teacher_education (teacher_profile_id,degree,institution,year_from,year_to,description,sort_order) VALUES (?,?,?,?,?,?,0)",
                [$tpId,$degree,$institution,$year_from,$year_to,$desc]);
        }

        // ── Optional: password change (only if any password field filled) ──
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if ($current !== '' || $new !== '' || $confirm !== '') {
            if (!password_verify($current, $user['password_hash'])) {
                flash('error','Current password is incorrect.'); redirect(APP_URL.'/teacher/profile.php#password');
            }
            if ($new !== $confirm) {
                flash('error','New passwords do not match.'); redirect(APP_URL.'/teacher/profile.php#password');
            }
            if (!validate_password($new)) {
                flash('error','Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.'); redirect(APP_URL.'/teacher/profile.php#password');
            }
            db_exec("UPDATE users SET password_hash=? WHERE id=?", [password_hash($new, PASSWORD_BCRYPT, ['cost'=>BCRYPT_COST]), $uid]);
        }

        flash('success','Profile updated.');
        redirect(APP_URL.'/teacher/profile.php');

    } elseif ($postAction === 'delete_education') {
        $edId = sanitize_int($_POST['edu_id'] ?? 0);
        db_exec("DELETE FROM teacher_education WHERE id=? AND teacher_profile_id=?", [$edId, $tpId]);
        flash('success','Deleted.');
        redirect(APP_URL.'/teacher/profile.php#education');
    }
}

// Refresh data after possible update
$user = current_user();
$tp   = db_row("SELECT * FROM teacher_profiles WHERE user_id=?", [$uid]);
$educations = db_query("SELECT * FROM teacher_education WHERE teacher_profile_id=? ORDER BY year_to DESC, sort_order ASC", [$tpId]);

// Stats for sidebar display
$totalCourses  = (int)db_val("SELECT COUNT(*) FROM courses WHERE (teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=courses.id AND ct.teacher_id=?)) AND status='published'", [$tpId, $tpId]);
$totalStudents = (int)db_val("SELECT COUNT(DISTINCT e.user_id) FROM enrollments e JOIN courses c ON c.id=e.course_id WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))", [$tpId, $tpId]);
$avgRating     = (float)db_val("SELECT COALESCE(AVG(r.rating),0) FROM reviews r JOIN courses c ON c.id=r.course_id WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))", [$tpId, $tpId]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Teacher Profile — <?= APP_NAME ?></title>
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

        <h1 style="font-size:var(--text-2xl);margin-bottom:var(--sp-6)">Teacher Profile</h1>

        <div style="display:grid;grid-template-columns:280px 1fr;gap:var(--sp-6)" class="profile-grid">

            <!-- Left: avatar + preview -->
            <div style="display:flex;flex-direction:column;gap:var(--sp-5)">
                <div class="card" style="padding:var(--sp-6);text-align:center">
                    <img src="<?= e(avatar_url($user['avatar'], $user['name'])) ?>"
                         alt="" id="avatarPreview"
                         style="width:100px;height:100px;border-radius:50%;object-fit:cover;border:4px solid var(--c-primary-light);margin:0 auto 12px">
                    <div style="font-weight:800;font-size:var(--text-lg)"><?= e($user['name']) ?></div>
                    <?php if (!empty($tp['headline'])): ?>
                    <div style="font-size:var(--text-sm);color:var(--c-ink-muted);margin-top:4px"><?= e($tp['headline']) ?></div>
                    <?php endif; ?>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:var(--sp-3);margin-top:var(--sp-5)">
                        <div style="text-align:center">
                            <div style="font-family:var(--font-latin);font-weight:800;font-size:var(--text-lg);color:var(--c-primary)"><?= $totalCourses ?></div>
                            <div style="font-size:10px;color:var(--c-ink-muted)">Courses</div>
                        </div>
                        <div style="text-align:center">
                            <div style="font-family:var(--font-latin);font-weight:800;font-size:var(--text-lg);color:var(--c-success)"><?= number_format($totalStudents) ?></div>
                            <div style="font-size:10px;color:var(--c-ink-muted)">Students</div>
                        </div>
                        <div style="text-align:center">
                            <div style="font-family:var(--font-latin);font-weight:800;font-size:var(--text-lg);color:var(--c-warning)"><?= number_format($avgRating,1) ?></div>
                            <div style="font-size:10px;color:var(--c-ink-muted)">Rating</div>
                        </div>
                    </div>
                </div>

                <!-- Social links preview -->
                <?php if (!empty($tp['fb_url']) || !empty($tp['yt_url']) || !empty($tp['website'])): ?>
                <div class="card" style="padding:var(--sp-5)">
                    <h4 style="margin-bottom:var(--sp-3);font-size:var(--text-sm)">Social Links</h4>
                    <div style="display:flex;flex-direction:column;gap:var(--sp-2)">
                        <?php if (!empty($tp['fb_url'])): ?>
                        <a href="<?= e($tp['fb_url']) ?>" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:8px;font-size:var(--text-sm);color:var(--c-ink);text-decoration:none">
                            <span style="color:#1877F2">&#xFB;</span> Facebook
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($tp['yt_url'])): ?>
                        <a href="<?= e($tp['yt_url']) ?>" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:8px;font-size:var(--text-sm);color:var(--c-ink);text-decoration:none">
                            <span style="color:#FF0000">▶</span> YouTube
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($tp['website'])): ?>
                        <a href="<?= e($tp['website']) ?>" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:8px;font-size:var(--text-sm);color:var(--c-ink);text-decoration:none">
                            <span class="material-icons-round" style="font-size:16px">language</span> Website
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right: forms -->
            <div style="display:flex;flex-direction:column;gap:var(--sp-6)">

                <!-- Profile form (covers whole page: personal info, education, password) -->
                <form method="POST" enctype="multipart/form-data" id="fullProfileForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_all">

                <div class="card" style="padding:var(--sp-6)">
                    <h3 style="margin-bottom:var(--sp-5)">Personal Information</h3>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)" class="profile-fields">
                            <div class="form-group">
                                <label class="form-label">Full Name *</label>
                                <input type="text" name="name" class="form-control" required value="<?= e($user['name']) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" value="<?= e($user['email'] ?? '') ?>">
                            </div>
                            <div class="form-group" style="grid-column:1/-1">
                                <label class="form-label">Professional Headline</label>
                                <input type="text" name="headline" class="form-control" value="<?= e($tp['headline'] ?? '') ?>"
                                       placeholder="e.g. Physics Teacher, HSC Specialist">
                            </div>
                            <div class="form-group" style="grid-column:1/-1">
                                <label class="form-label">Expertise (comma separated)</label>
                                <input type="text" name="expertise" class="form-control" value="<?= e($tp['expertise'] ?? '') ?>"
                                       placeholder="Physics, Math, HSC">
                            </div>
                            <div class="form-group" style="grid-column:1/-1">
                                <label class="form-label">Bio</label>
                                <textarea name="bio" class="form-control" rows="6"><?= e($tp['bio'] ?? '') ?></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Facebook Link</label>
                                <input type="url" name="fb_url" class="form-control" value="<?= e($tp['fb_url'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">YouTube Link</label>
                                <input type="url" name="yt_url" class="form-control" value="<?= e($tp['yt_url'] ?? '') ?>">
                            </div>
                            <div class="form-group" style="grid-column:1/-1">
                                <label class="form-label">Website</label>
                                <input type="url" name="website" class="form-control" value="<?= e($tp['website'] ?? '') ?>">
                            </div>
                            <div class="form-group" style="grid-column:1/-1">
                                <label class="form-label">Change Profile Picture</label>
                                <input type="file" name="avatar" class="form-control" accept="image/jpeg,image/png,image/webp"
                                       onchange="const r=new FileReader();r.onload=e=>document.getElementById('avatarPreview').src=e.target.result;r.readAsDataURL(this.files[0])">
                                <small style="color:var(--c-ink-muted)">JPG/PNG/WebP · Max 10MB</small>
                            </div>
                        </div>
                </div>

                <!-- Education background -->
                <div class="card" style="padding:var(--sp-6)" id="education">
                    <h3 style="margin-bottom:var(--sp-5)">Education</h3>

                    <?php if (!empty($educations)): ?>
                    <div style="display:flex;flex-direction:column;gap:var(--sp-3);margin-bottom:var(--sp-5)">
                        <?php foreach ($educations as $edu): ?>
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:var(--sp-4);background:var(--c-surface);border-radius:var(--r-md);border-left:3px solid var(--c-primary)">
                            <div>
                                <div style="font-weight:700"><?= e($edu['degree']) ?></div>
                                <div style="color:var(--c-ink-muted);font-size:var(--text-sm)"><?= e($edu['institution']) ?></div>
                                <?php if ($edu['year_from'] || $edu['year_to']): ?>
                                <div style="font-size:11px;color:var(--c-ink-faint);font-family:var(--font-latin)"><?= $edu['year_from'] ?: '' ?><?= ($edu['year_from'] && $edu['year_to']) ? ' — ' : '' ?><?= $edu['year_to'] ?: '' ?></div>
                                <?php endif; ?>
                                <?php if (!empty($edu['description'])): ?>
                                <div style="font-size:var(--text-sm);color:var(--c-ink-muted);margin-top:4px"><?= e($edu['description']) ?></div>
                                <?php endif; ?>
                            </div>
                            <button type="submit" form="delEdu<?= (int)$edu['id'] ?>" class="btn btn-ghost btn-sm" style="color:var(--c-error)" onclick="return confirm('Delete this?')">
                                <span class="material-icons-round" style="font-size:15px">delete</span>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)">
                        <div class="form-group" style="grid-column:1/-1">
                            <label class="form-label">Degree / Certificate</label>
                            <input type="text" name="degree" class="form-control" placeholder="e.g. BSc Physics, SSC, HSC, M.Phil">
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label class="form-label">Institution</label>
                            <input type="text" name="institution" class="form-control" placeholder="e.g. Dhaka University">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Start (Year)</label>
                            <input type="number" name="year_from" class="form-control" min="1980" max="2030" placeholder="2018">
                        </div>
                        <div class="form-group">
                            <label class="form-label">End (Year)</label>
                            <input type="number" name="year_to" class="form-control" min="1980" max="2030" placeholder="2022">
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label class="form-label">Details (optional)</label>
                            <input type="text" name="description" class="form-control" placeholder="Department, CGPA, achievements...">
                        </div>
                    </div>
                    <small style="color:var(--c-ink-muted)">Fill Degree + Institution above and hit Save Profile to add a new entry.</small>
                </div>

                <!-- Password change -->
                <div class="card" style="padding:var(--sp-6)" id="password">
                    <h3 style="margin-bottom:var(--sp-5)">Change Password</h3>
                    <div class="form-group">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" autocomplete="current-password">
                    </div>
                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" autocomplete="new-password" minlength="8">
                        <small style="color:var(--c-ink-muted)">Leave blank to keep current password. Otherwise at least 8 characters — any password is fine.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm Password</label>
                        <input type="password" name="confirm_password" class="form-control" autocomplete="new-password">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg">
                    <span class="material-icons-round">save</span> Save Profile
                </button>
                </form>

                <!-- Hidden delete-education forms (outside main form, referenced via button form="" attribute) -->
                <?php foreach ($educations as $edu): ?>
                <form method="POST" id="delEdu<?= (int)$edu['id'] ?>" style="display:none">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_education">
                    <input type="hidden" name="edu_id" value="<?= (int)$edu['id'] ?>">
                </form>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<style>
@media(max-width:1024px){.profile-grid{grid-template-columns:1fr !important}}
@media(max-width:600px){.profile-fields{grid-template-columns:1fr !important}}
</style>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
