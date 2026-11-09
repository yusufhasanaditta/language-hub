<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_admin();
ensure_course_fb_group_column();
ensure_course_fake_enrollment_column();

$action = sanitize_string($_GET['action'] ?? 'list', 20);
$edit_id = sanitize_int($_GET['id'] ?? 0);

// ── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $postAction = sanitize_string($_POST['action'] ?? '', 20);

    if ($postAction === 'save') {
        $id          = sanitize_int($_POST['id'] ?? 0);
        $title_bn    = sanitize_string($_POST['title_bn'] ?? '', 255);
        $title_en    = sanitize_string($_POST['title_en'] ?? '', 255);
        $slug        = sanitize_string($_POST['slug'] ?? '', 200);
        $cat_id      = sanitize_int($_POST['category_id'] ?? 0);
        $type        = in_array($_POST['type'] ?? '', ['recorded','live','hybrid']) ? $_POST['type'] : 'recorded';
        $price       = max(0, (float)($_POST['price'] ?? 0));
        $sale_price  = max(0, (float)($_POST['sale_price'] ?? 0));
        $is_free     = isset($_POST['is_free']) ? 1 : 0;
        $is_featured = isset($_POST['is_featured']) ? 1 : 0;
        $is_exam_course = isset($_POST['is_exam_course']) ? 1 : 0;
        $status      = in_array($_POST['status'] ?? '', ['draft','published','archived']) ? $_POST['status'] : 'draft';
        $desc_bn     = $_POST['description_bn'] ?? '';
        $what_learn  = $_POST['what_you_learn'] ?? '';
        $requirements= $_POST['requirements'] ?? '';
        $level       = in_array($_POST['level'] ?? '', ['beginner','intermediate','advanced']) ? $_POST['level'] : 'beginner';
        $language    = sanitize_string($_POST['language'] ?? 'Bangla', 50);
        $target_audience = sanitize_string($_POST['target_audience'] ?? '', 255);
        $intro_video  = sanitize_string($_POST['intro_video_url'] ?? '', 500);
        $short_desc   = sanitize_string($_POST['short_desc'] ?? '', 400);
        $fake_enrolled_count = sanitize_int($_POST['fake_enrolled_count'] ?? 0);
        $fake_enrolled_count = $fake_enrolled_count > 0 ? $fake_enrolled_count : null;
        $teacher_ids  = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['teacher_ids'] ?? [])))));
        $teacher_id   = $teacher_ids[0] ?? 0;

        if (!$title_bn || !$slug || !$cat_id || !$teacher_id) {
            flash('error', 'Title, slug, category and at least one teacher are required.');
            redirect(APP_URL . '/admin/courses.php?action=' . ($id ? 'edit&id='.$id : 'add'));
        }

        // Thumbnail upload
        $thumbnail = sanitize_string($_POST['existing_thumbnail'] ?? '', 500);
        if (!empty($_FILES['thumbnail']['tmp_name'])) {
            $up = handle_image_upload('thumbnail', 'courses');
            if ($up) $thumbnail = $up;
        }

        // Routine PDF upload
        $routine_pdf = sanitize_string($_POST['existing_routine_pdf'] ?? '', 500);
        if (!empty($_FILES['routine_pdf']['tmp_name'])) {
            $pdf_up = handle_file_upload('routine_pdf', 'courses'); // empty array allows PDF among other defaults
            if ($pdf_up) $routine_pdf = $pdf_up;
        }

        $routine_link = sanitize_string($_POST['routine_link'] ?? '', 500);
        $fb_group_link = sanitize_string($_POST['fb_group_link'] ?? '', 500);
        if ($fb_group_link && !filter_var($fb_group_link, FILTER_VALIDATE_URL)) {
            flash('error', 'Facebook group link does not look like a valid URL.');
            redirect(APP_URL . '/admin/courses.php?action=' . ($id ? 'edit&id='.$id : 'add'));
        }

        if ($id) {
            db_exec("UPDATE courses SET
                title_bn=?, title_en=?, slug=?, category_id=?, teacher_id=?, type=?,
                price=?, sale_price=?, is_free=?, is_featured=?, is_exam_course=?, status=?,
                description_bn=?, what_you_learn=?, requirements=?, level=?,
                language=?, target_audience=?, intro_video_url=?, short_desc=?,
                thumbnail=CASE WHEN ?!='' THEN ? ELSE thumbnail END,
                routine_pdf=CASE WHEN ?!='' THEN ? ELSE routine_pdf END,
                routine_link=?, fb_group_link=?, fake_enrolled_count=?,
                updated_at=NOW()
                WHERE id=?",
                [$title_bn, $title_en, $slug, $cat_id, $teacher_id, $type,
                 $price, $sale_price, $is_free, $is_featured, $is_exam_course, $status,
                 $desc_bn, $what_learn, $requirements, $level,
                 $language, $target_audience, $intro_video, $short_desc,
                 $thumbnail, $thumbnail, $routine_pdf, $routine_pdf, $routine_link, $fb_group_link, $fake_enrolled_count, $id]);
            flash('success', 'Course updated.');
        } else {
            $id = db_insert("INSERT INTO courses
                (title_bn, title_en, slug, category_id, teacher_id, type,
                 price, sale_price, is_free, is_featured, is_exam_course, status, description_bn,
                 what_you_learn, requirements, level, language, target_audience,
                 intro_video_url, short_desc, thumbnail, routine_pdf, routine_link, fb_group_link, fake_enrolled_count, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())",
                [$title_bn, $title_en, $slug, $cat_id, $teacher_id, $type,
                 $price, $sale_price, $is_free, $is_featured, $is_exam_course, $status, $desc_bn,
                 $what_learn, $requirements, $level, $language, $target_audience,
                 $intro_video, $short_desc, $thumbnail, $routine_pdf, $routine_link, $fb_group_link, $fake_enrolled_count]);
            flash('success', 'Course created.');
        }
        // Sync course_teachers pivot — all selected teachers are equal
        db_exec("DELETE FROM course_teachers WHERE course_id=?", [$id]);
        foreach ($teacher_ids as $i => $tid) {
            db_exec("INSERT IGNORE INTO course_teachers (course_id, teacher_id, is_primary, sort_order) VALUES (?,?,1,?)",
                [$id, $tid, $i]);
        }
        redirect(APP_URL . '/admin/courses.php');

    } elseif ($postAction === 'toggle_status') {
        $id = sanitize_int($_POST['id'] ?? 0);
        if ($id) {
            db_exec("UPDATE courses SET status=IF(status='published','draft','published'), updated_at=NOW() WHERE id=?", [$id]);
            flash('success', 'Course status changed.');
        }
        redirect(APP_URL . '/admin/courses.php');

    } elseif ($postAction === 'delete') {
        $id = sanitize_int($_POST['id'] ?? 0);
        if ($id) {
            $enrolled = (int)db_val("SELECT COUNT(*) FROM enrollments WHERE course_id=?", [$id]);
            if ($enrolled > 0) {
                flash('error', "Cannot delete this course. $enrolled students are enrolled.");
            } else {
                db_exec("DELETE FROM courses WHERE id=?", [$id]);
                flash('success', 'Course deleted.');
            }
        }
        redirect(APP_URL . '/admin/courses.php');
    }
}

// ── Load edit course ──────────────────────────────────────────────────────────
$editCourse = null;
$editTeacherIds = [];
if (($action === 'edit' || $action === 'add') && $edit_id) {
    $editCourse = db_row("SELECT * FROM courses WHERE id=?", [$edit_id]);
    if ($editCourse) {
        $editTeacherIds = array_column(
            db_query("SELECT teacher_id FROM course_teachers WHERE course_id=?", [$edit_id]),
            'teacher_id'
        );
        if (!$editTeacherIds && $editCourse['teacher_id']) {
            $editTeacherIds = [(int)$editCourse['teacher_id']];
        }
    }
}

// ── List data ─────────────────────────────────────────────────────────────────
$search  = sanitize_string($_GET['q'] ?? '', 100);
$cat_f   = sanitize_int($_GET['cat'] ?? 0);
$status_f= sanitize_string($_GET['status'] ?? '', 20);
$page    = max(1, sanitize_int($_GET['page'] ?? 1));
$perPage = COURSES_PER_PAGE;

$where  = ['1=1'];
$params = [];
if ($search) {
    $where[]  = '(c.title_bn LIKE ? OR c.title_en LIKE ?)';
    $s = '%'.$search.'%';
    $params = array_merge($params, [$s,$s]);
}
if ($cat_f)   { $where[] = 'c.category_id=?'; $params[] = $cat_f; }
if ($status_f){ $where[] = 'c.status=?'; $params[] = $status_f; }

$whereSQL = 'WHERE '.implode(' AND ', $where);
$total    = (int)db_val("SELECT COUNT(*) FROM courses c $whereSQL", $params);
$offset   = ($page - 1) * $perPage;
$courses  = db_query("
    SELECT c.*, cat.name_bn as cat_name, u.name as teacher_name,
           (SELECT COUNT(*) FROM enrollments e WHERE e.course_id=c.id) as enrolls
    FROM courses c
    JOIN categories cat ON cat.id=c.category_id
    JOIN teacher_profiles tp ON tp.id=c.teacher_id
    JOIN users u ON u.id=tp.user_id
    $whereSQL
    ORDER BY c.created_at DESC
    LIMIT $perPage OFFSET $offset
", $params);

$categories = db_query("SELECT id, name_bn FROM categories ORDER BY sort_order");
$teachers   = db_query("SELECT tp.id, u.name FROM teacher_profiles tp JOIN users u ON u.id=tp.user_id ORDER BY u.name");
$urlPattern = APP_URL . '/admin/courses.php?' . http_build_query(array_filter(['q'=>$search,'cat'=>$cat_f,'status'=>$status_f,'page'=>'{page}']));

$page_title = 'Course Management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?> — <?= APP_NAME ?></title>
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
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="admin-content">
        <?php foreach (get_flash() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:16px">
            <span class="material-icons-round"><?= $f['type']==='success'?'check_circle':'error' ?></span>
            <span><?= e($f['message']) ?></span>
        </div>
        <?php endforeach; ?>

        <?php if ($action === 'add' || $action === 'edit'): ?>
        <!-- ── Add / Edit Form ──────────────────────────────────────────── -->
        <div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:var(--sp-6)">
            <a href="<?= APP_URL ?>/admin/courses.php" class="btn btn-ghost btn-sm">
                <span class="material-icons-round">arrow_back</span>
            </a>
            <h1 style="font-size:var(--text-2xl)"><?= $action==='edit' ? 'Edit Course' : 'New Course' ?></h1>
        </div>

        <form method="POST" enctype="multipart/form-data" id="courseForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($editCourse['id'] ?? 0) ?>">
            <input type="hidden" name="existing_thumbnail" value="<?= e($editCourse['thumbnail'] ?? '') ?>">

            <div style="display:grid;grid-template-columns:1fr 320px;gap:var(--sp-6)">
                <!-- Left: Main content -->
                <div style="display:flex;flex-direction:column;gap:var(--sp-5)">
                    <div class="card" style="padding:var(--sp-6)">
                        <h3 style="margin-bottom:var(--sp-4)">Main Info</h3>
                        <div class="form-group">
                            <label class="form-label">Course Name (Bangla) *</label>
                            <input type="text" name="title_bn" class="form-control" required
                                   value="<?= e($editCourse['title_bn'] ?? '') ?>" id="titleBn">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Course Name (English)</label>
                            <input type="text" name="title_en" class="form-control"
                                   value="<?= e($editCourse['title_en'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Slug (URL) *</label>
                            <input type="text" name="slug" class="form-control" required id="courseSlug"
                                   value="<?= e($editCourse['slug'] ?? '') ?>"
                                   pattern="[a-z0-9\-]+" title="Lowercase letters, numbers and hyphens only">
                            <small style="color:var(--c-ink-muted)">Example: hsc-physics-2024</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Short Description (shown on cards)</label>
                            <textarea name="short_desc" class="form-control" rows="2" maxlength="400" placeholder="One or two lines about the course..."><?= e($editCourse['short_desc'] ?? '') ?></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Full Description (Bangla)</label>
                            <textarea name="description_bn" class="form-control" rows="4"><?= e($editCourse['description_bn'] ?? '') ?></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">What You'll Learn</label>
                            <textarea name="what_you_learn" class="form-control" rows="3"
                                      placeholder="Write each point on a new line"><?= e($editCourse['what_you_learn'] ?? '') ?></textarea>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)">
                            <div class="form-group">
                                <label class="form-label">Requirements</label>
                                <textarea name="requirements" class="form-control" rows="2"><?= e($editCourse['requirements'] ?? '') ?></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Target Audience</label>
                                <input type="text" name="target_audience" class="form-control"
                                       value="<?= e($editCourse['target_audience'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="card" style="padding:var(--sp-6)">
                        <h3 style="margin-bottom:var(--sp-4)">Media</h3>
                        <div class="form-group">
                            <label class="form-label">Thumbnail Image</label>
                            <?php if (!empty($editCourse['thumbnail'])): ?>
                            <img src="<?= e(course_thumbnail_url($editCourse['thumbnail'] ?? '', $editCourse['title_bn'] ?? '', '800x600', 'e2e8f0')) ?>" alt="Thumbnail" style="width:200px;height:112px;object-fit:cover;border-radius:var(--r-md);margin-bottom:var(--sp-3);display:block">
                            <?php endif; ?>
                            <input type="file" name="thumbnail" id="courseThumbnail" class="form-control" accept="image/jpeg,image/png,image/webp">
                            <small style="color:var(--c-ink-muted)">JPG/PNG/WebP, max 2MB, 16:9 ratio is best</small>
                            <div id="aiLoadingIndicator" style="display:none; align-items:center; gap:8px; margin-top:12px; color:var(--c-primary); font-size:14px; font-weight:600;">
                                <style>@keyframes spin { 100% { transform: rotate(360deg); } }</style>
                                <span class="material-icons-round" style="animation: spin 1.5s linear infinite;">autorenew</span> 
                                Analyzing thumbnail and generating details...
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Intro Video URL</label>
                            <input type="url" name="intro_video_url" class="form-control"
                                   value="<?= e($editCourse['intro_video_url'] ?? '') ?>"
                                   placeholder="https://...">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Routine PDF (Upload)</label>
                            <?php if (!empty($editCourse['routine_pdf'])): ?>
                            <a href="<?= APP_URL ?><?= e($editCourse['routine_pdf']) ?>" target="_blank" class="btn btn-sm btn-outline" style="margin-bottom:var(--sp-3);display:inline-block">View Current PDF</a>
                            <?php endif; ?>
                            <input type="hidden" name="existing_routine_pdf" value="<?= e($editCourse['routine_pdf'] ?? '') ?>">
                            <input type="file" name="routine_pdf" id="courseRoutinePdf" class="form-control" accept="application/pdf">
                            <small style="color:var(--c-ink-muted)">Or provide a link below if you don't upload a PDF.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Routine Link (URL)</label>
                            <input type="url" name="routine_link" class="form-control"
                                   value="<?= e($editCourse['routine_link'] ?? '') ?>"
                                   placeholder="https://docs.google.com/...">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Facebook Private Group Link</label>
                            <input type="url" name="fb_group_link" class="form-control"
                                   value="<?= e($editCourse['fb_group_link'] ?? '') ?>"
                                   placeholder="https://facebook.com/groups/...">
                            <small style="color:var(--c-ink-muted)">Shown as a "Join Facebook Private Group" button to enrolled students on the course study page.</small>
                        </div>
                    </div>
                </div>

                <!-- Right: Settings -->
                <div style="display:flex;flex-direction:column;gap:var(--sp-5)">
                    <div class="card" style="padding:var(--sp-6)">
                        <h3 style="margin-bottom:var(--sp-4)">Publishing</h3>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-control">
                                <?php foreach (['draft'=>'Draft','published'=>'Published','archived'=>'Archived'] as $v=>$l): ?>
                                <option value="<?= $v ?>" <?= ($editCourse['status']??'draft')===$v?'selected':'' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="display:flex;gap:var(--sp-4);align-items:center">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                                <input type="checkbox" name="is_featured" value="1"
                                       <?= !empty($editCourse['is_featured'])?'checked':'' ?>>
                                <span>Feature this</span>
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                                <input type="checkbox" name="is_free" value="1"
                                       <?= !empty($editCourse['is_free'])?'checked':'' ?>>
                                <span>Free</span>
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                                <input type="checkbox" name="is_exam_course" value="1"
                                       <?= !empty($editCourse['is_exam_course'])?'checked':'' ?>>
                                <span>Exam Course</span>
                            </label>
                        </div>
                    </div>

                    <div class="card" style="padding:var(--sp-6)">
                        <h3 style="margin-bottom:var(--sp-4)">Category & Teacher</h3>
                        <div class="form-group">
                            <label class="form-label">Category *</label>
                            <select name="category_id" class="form-control" required>
                                <option value="">Choose category</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>" <?= ($editCourse['category_id']??0)==$cat['id']?'selected':'' ?>>
                                    <?= e($cat['name_bn']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Teachers *</label>
                            <select name="teacher_ids[]" class="form-control" multiple size="5" style="height:auto" required>
                                <?php foreach ($teachers as $t): ?>
                                <option value="<?= (int)$t['id'] ?>"
                                    <?= in_array((int)$t['id'], $editTeacherIds) ? 'selected' : '' ?>>
                                    <?= e($t['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color:var(--c-ink-muted)">Hold Ctrl/Cmd to select more than one. All selected teachers are equal.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-control">
                                <?php foreach (['recorded'=>'Recorded','live'=>'Live','hybrid'=>'Mixed'] as $v=>$l): ?>
                                <option value="<?= $v ?>" <?= ($editCourse['type']??'recorded')===$v?'selected':'' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Level</label>
                            <select name="level" class="form-control">
                                <?php foreach (['beginner'=>'Beginner','intermediate'=>'Intermediate','advanced'=>'Advanced'] as $v=>$l): ?>
                                <option value="<?= $v ?>" <?= ($editCourse['level']??'beginner')===$v?'selected':'' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Language</label>
                            <input type="text" name="language" class="form-control"
                                   value="<?= e($editCourse['language'] ?? 'Bangla') ?>">
                        </div>
                    </div>

                    <div class="card" style="padding:var(--sp-6)">
                        <h3 style="margin-bottom:var(--sp-4)">Price</h3>
                        <div class="form-group">
                            <label class="form-label">Regular Price</label>
                            <input type="number" name="price" class="form-control" min="0" step="1"
                                   value="<?= (int)($editCourse['price'] ?? 0) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Sale Price</label>
                            <input type="number" name="sale_price" class="form-control" min="0" step="1"
                                   value="<?= (int)($editCourse['sale_price'] ?? 0) ?>">
                        </div>
                    </div>

                    <div class="card" style="padding:var(--sp-6)">
                        <h3 style="margin-bottom:var(--sp-4)">Enrollment Display</h3>
                        <?php if ($action === 'edit'): ?>
                        <?php $realEnrolled = (int)db_val("SELECT COUNT(*) FROM enrollments WHERE course_id=?", [$edit_id]); ?>
                        <div class="form-group">
                            <label class="form-label">Real Enrollment (actual paying students)</label>
                            <div style="font-weight:700;font-family:var(--font-latin);font-size:var(--text-lg)"><?= number_format($realEnrolled) ?></div>
                            <small style="color:var(--c-ink-muted)">This is the true count — only visible here in admin, never affected by the field below.</small>
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label class="form-label">Displayed Enrollment Count (optional)</label>
                            <input type="number" name="fake_enrolled_count" class="form-control" min="0" step="1"
                                   value="<?= (int)($editCourse['fake_enrolled_count'] ?? 0) ?: '' ?>" placeholder="Leave empty to show the real count">
                            <small style="color:var(--c-ink-muted)">If set, students and visitors see THIS number on the course page and cards instead of the real enrollment count. Set to 0 or leave empty to show the real count.</small>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%">
                        <span class="material-icons-round">save</span>
                        <?= $action==='edit' ? 'Save Changes' : 'Create Course' ?>
                    </button>
                    <a href="<?= APP_URL ?>/admin/courses.php" class="btn btn-ghost" style="width:100%;text-align:center">Cancel</a>
                </div>
            </div>
        </form>

        <?php else: ?>
        <!-- ── List View ────────────────────────────────────────────────── -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
            <h1 style="font-size:var(--text-2xl)">Courses</h1>
            <a href="<?= APP_URL ?>/admin/courses.php?action=add" class="btn btn-primary btn-sm">
                <span class="material-icons-round">add</span> New Course
            </a>
        </div>

        <!-- Filters -->
        <form method="GET" style="display:flex;gap:var(--sp-3);margin-bottom:var(--sp-6);flex-wrap:wrap;align-items:center">
            <input type="search" name="q" value="<?= e($search) ?>"
                   placeholder="Search courses..." class="form-control" style="max-width:280px">
            <select name="cat" class="form-control" style="width:auto" onchange="this.form.submit()">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>" <?= $cat_f==$cat['id']?'selected':'' ?>>
                    <?= e($cat['name_bn']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="form-control" style="width:auto" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <?php foreach (['draft'=>'Draft','published'=>'Published','archived'=>'Archived'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= $status_f===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">
                <span class="material-icons-round">search</span> Search
            </button>
            <?php if ($search||$cat_f||$status_f): ?>
            <a href="<?= APP_URL ?>/admin/courses.php" class="btn btn-ghost btn-sm">Clear</a>
            <?php endif; ?>
            <span style="margin-left:auto;color:var(--c-ink-muted);font-size:var(--text-sm)"><?= number_format($total) ?> courses</span>
        </form>

        <div class="data-table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th>Course</th>
                        <th>Teacher</th>
                        <th>Price</th>
                        <th>Enrolled</th>
                        <th>Rating</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($courses)): ?>
                    <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--c-ink-muted)">No courses found</td></tr>
                    <?php else: foreach ($courses as $c): ?>
                    <tr>
                        <td style="color:var(--c-ink-muted);font-family:var(--font-latin)"><?= (int)$c['id'] ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:var(--sp-3)">
                                <?php if ($c['thumbnail']): ?>
                                <?php $thumbUrl = course_thumbnail_url($c['thumbnail'] ?? '', $c['title_bn'] ?? '', '80x45', 'e2e8f0'); ?>
                                <img src="<?= e($thumbUrl) ?>" alt="Thumbnail" style="width:60px;height:36px;object-fit:cover;border-radius:6px;flex-shrink:0">
                                <?php else: ?>
                                <div style="width:60px;height:36px;background:var(--c-surface);border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                                    <span class="material-icons-round" style="font-size:16px;color:var(--c-ink-muted)">play_circle</span>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight:700;font-size:var(--text-sm)"><?= e($c['title_bn']) ?></div>
                                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= e($c['cat_name']) ?> · <?= e($c['type']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td style="font-size:var(--text-sm)"><?= e($c['teacher_name']) ?></td>
                        <td style="font-family:var(--font-latin)">
                            <?php if ($c['is_free']): ?>
                            <span style="color:var(--c-success);font-weight:700">Free</span>
                            <?php elseif ($c['sale_price'] > 0): ?>
                            <div style="font-weight:700"><?= number_format($c['sale_price'],0) ?></div>
                            <div style="text-decoration:line-through;font-size:var(--text-xs);color:var(--c-ink-muted)"><?= number_format($c['price'],0) ?></div>
                            <?php else: ?>
                            <div style="font-weight:700"><?= number_format($c['price'],0) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="font-family:var(--font-latin);font-weight:700"><?= number_format((int)$c['enrolls']) ?></td>
                        <td>
                            <?php if ($c['rating_avg'] > 0): ?>
                            <span style="color:var(--c-warning)">★</span>
                            <span style="font-family:var(--font-latin);font-size:var(--text-sm)"><?= number_format($c['rating_avg'],1) ?></span>
                            <?php else: ?>
                            <span style="color:var(--c-ink-muted);font-size:var(--text-sm)">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $statusMap = ['published'=>'status-active','draft'=>'status-draft','archived'=>'status-archived']; ?>
                            <?php $statusLabel = ['published'=>'Published','draft'=>'Draft','archived'=>'Archived']; ?>
                            <span class="status-badge <?= $statusMap[$c['status']] ?? '' ?>">
                                <?= $statusLabel[$c['status']] ?? $c['status'] ?>
                            </span>
                        </td>
                        <td>
                            <div style="display:flex;gap:4px">
                                <a href="<?= APP_URL ?>/admin/courses.php?action=edit&id=<?= (int)$c['id'] ?>"
                                   class="btn btn-ghost btn-sm" title="Edit">
                                    <span class="material-icons-round" style="font-size:15px">edit</span>
                                </a>
                                <a href="<?= APP_URL ?>/teacher/lessons.php?course=<?= (int)$c['id'] ?>"
                                   class="btn btn-ghost btn-sm" title="Curriculum (videos/lessons)">
                                    <span class="material-icons-round" style="font-size:15px">video_library</span>
                                </a>
                                <a href="<?= APP_URL ?>/teacher/classroom.php?id=<?= (int)$c['id'] ?>"
                                   class="btn btn-ghost btn-sm" title="Virtual Classrooms (subject sections)">
                                    <span class="material-icons-round" style="font-size:15px">meeting_room</span>
                                </a>
                                <a href="<?= APP_URL ?>/course.php?slug=<?= e($c['slug']) ?>"
                                   target="_blank" class="btn btn-ghost btn-sm" title="View">
                                    <span class="material-icons-round" style="font-size:15px">open_in_new</span>
                                </a>
                                <a href="<?= APP_URL ?>/admin/course_analytics.php?id=<?= (int)$c['id'] ?>"
                                   class="btn btn-ghost btn-sm" title="Analytics">
                                    <span class="material-icons-round" style="font-size:15px">insights</span>
                                </a>
                                <form method="POST" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm"
                                            title="<?= $c['status']==='published'?'Set to draft':'Publish' ?>">
                                        <span class="material-icons-round" style="font-size:15px">
                                            <?= $c['status']==='published' ? 'visibility_off' : 'visibility' ?>
                                        </span>
                                    </button>
                                </form>
                                <?php if ((int)$c['enrolls'] === 0): ?>
                                <form method="POST" style="display:inline"
                                      onsubmit="return confirm('Delete this course?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--c-error)">
                                        <span class="material-icons-round" style="font-size:15px">delete</span>
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
// Auto-generate slug from Bangla title on add form
const titleBn = document.getElementById('titleBn');
const slugField = document.getElementById('courseSlug');
if (titleBn && slugField && !slugField.value) {
    titleBn.addEventListener('input', () => {
        const slug = titleBn.value
            .toLowerCase()
            .replace(/[ঀ-৿]/g, '') // strip bangla chars
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
        slugField.value = slug || 'course-' + Date.now().toString(36);
    });
}

// Auto-generate course details from thumbnail
const thumbnailInput = document.getElementById('courseThumbnail');
const aiLoadingIndicator = document.getElementById('aiLoadingIndicator');
if (thumbnailInput) {
    thumbnailInput.addEventListener('change', async (e) => {
        if (!e.target.files || e.target.files.length === 0) return;
        
        const file = e.target.files[0];
        const formData = new FormData();
        formData.append('thumbnail', file);
        
        // Show loading
        if (aiLoadingIndicator) aiLoadingIndicator.style.display = 'flex';
        
        try {
            const res = await fetch(APP_URL + '/admin/ajax_generate_course_details.php', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: formData
            });
            const data = await res.json();
            
            if (data.success && data.data) {
                const info = data.data;
                // Auto-fill fields if they exist and are empty
                const fill = (name, val) => {
                    const el = name === 'titleBn' ? document.getElementById('titleBn') : document.querySelector(`[name="${name}"]`);
                    if (el && !el.value && val) el.value = val;
                };
                
                fill('titleBn', info.title_bn);
                fill('title_en', info.title_en);
                fill('short_desc', info.short_desc);
                fill('description_bn', info.description_bn);
                fill('what_you_learn', info.what_you_learn);
                fill('requirements', info.requirements);
                fill('target_audience', info.target_audience);
                if (info.price && info.price > 0) fill('price', info.price);
                
                // Trigger input event on titleBn to generate slug
                const titleBnEl = document.getElementById('titleBn');
                if (titleBnEl) titleBnEl.dispatchEvent(new Event('input'));
                
                // Show brief success flash or log
                console.log('AI generation complete.');
            } else {
                console.error('AI Generation Error:', data.error);
                if (data.error && data.error.includes('not configured')) {
                    console.log('Gemini API key not configured, skipping auto-fill.');
                }
            }
        } catch (err) {
            console.error('Error generating course details:', err);
        } finally {
            if (aiLoadingIndicator) aiLoadingIndicator.style.display = 'none';
        }
    });
}
</script>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>


