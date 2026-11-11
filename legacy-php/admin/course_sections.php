<?php
/**
 * Course page builder — admins compose the public course detail page out of
 * ordered blocks (text, feature cards, FAQ, routine table, stats, gallery,
 * video, CTA). Rendered by includes/course_sections.php on course.php.
 */
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/course_sections.php';

session_start_secure();
send_security_headers();
require_admin();

$kinds = cs_kinds();
$courseId = (int) ($_GET['course'] ?? $_POST['course_id'] ?? 0);
$course = $courseId ? db_row("SELECT * FROM courses WHERE id=?", [$courseId]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && $course) {
    $act = $_POST['action'] ?? '';

    if ($act === 'add') {
        $kind = $_POST['kind'] ?? 'rich';
        if (!isset($kinds[$kind]))
            $kind = 'rich';
        $next = (int) db_val("SELECT COALESCE(MAX(sort_order),0)+10 FROM course_sections WHERE course_id=?", [$courseId]);
        db_insert(
            "INSERT INTO course_sections (course_id, kind, title, sort_order, content) VALUES (?,?,?,?,?)",
            [$courseId, $kind, $kinds[$kind]['label'], $next, json_encode($kinds[$kind]['repeat'] ? [] : new stdClass())]
        );
        flash('success', $kinds[$kind]['label'] . ' block added.');

    } elseif ($act === 'save') {
        $sid = (int) ($_POST['section_id'] ?? 0);
        $sec = db_row("SELECT * FROM course_sections WHERE id=? AND course_id=?", [$sid, $courseId]);
        if ($sec) {
            $kind = $sec['kind'];
            $def = $kinds[$kind];
            $fields = array_keys($def['fields']);

            if ($def['repeat']) {
                // Parallel arrays: rows[icon][], rows[title][] …
                $rows = $_POST['rows'] ?? [];
                $count = 0;
                foreach ($fields as $f) {
                    $count = max($count, count($rows[$f] ?? []));
                }
                $data = [];
                for ($i = 0; $i < $count; $i++) {
                    $row = [];
                    $blank = true;
                    foreach ($fields as $f) {
                        $val = trim((string) ($rows[$f][$i] ?? ''));
                        $row[$f] = $val;
                        if ($val !== '')
                            $blank = false;
                    }
                    if (!$blank)
                        $data[] = $row;
                }
            } else {
                $data = [];
                foreach ($fields as $f) {
                    $data[$f] = trim((string) ($_POST['single'][$f] ?? ''));
                }
            }

            db_exec(
                "UPDATE course_sections
                    SET title=?, subtitle=?, content=?, bg_style=?, placement=?, sort_order=?, is_active=?
                  WHERE id=? AND course_id=?",
                [
                    sanitize_string($_POST['title'] ?? '', 200) ?: null,
                    sanitize_string($_POST['subtitle'] ?? '', 300) ?: null,
                    json_encode($data, JSON_UNESCAPED_UNICODE),
                    in_array($_POST['bg_style'] ?? 'plain', ['plain', 'tint', 'dark'], true) ? $_POST['bg_style'] : 'plain',
                    in_array($_POST['placement'] ?? 'overview', ['overview', 'curriculum'], true) ? $_POST['placement'] : 'overview',
                    sanitize_int($_POST['sort_order'] ?? 0),
                    !empty($_POST['is_active']) ? 1 : 0,
                    $sid,
                    $courseId,
                ]
            );
            flash('success', 'Block saved.');
        }

    } elseif ($act === 'delete') {
        db_exec("DELETE FROM course_sections WHERE id=? AND course_id=?", [(int) ($_POST['section_id'] ?? 0), $courseId]);
        flash('success', 'Block deleted.');

    } elseif ($act === 'preset') {
        // Seeds the full landing-page structure in one click; every block stays
        // editable afterwards.
        $preset = [
            [
                'checklist',
                'What this course includes',
                null,
                'tint',
                [
                    ['text' => '120+ live classes'],
                    ['text' => '100 recorded archive classes'],
                    ['text' => '120+ class slides'],
                    ['text' => '120+ daily quizzes'],
                    ['text' => '11 full model tests'],
                    ['text' => 'Suggestion PDF for all subjects'],
                ]
            ],
            [
                'notice_cta',
                null,
                null,
                'plain',
                [
                    'note' => 'N.B. Please watch this video before you buy.',
                    'btn_label' => 'Watch tutorial video',
                    'btn_url' => '',
                    'tone' => 'red',
                ]
            ],
            [
                'teachers',
                'Who takes the live classes',
                null,
                'plain',
                [
                    ['photo' => '', 'name' => 'Teacher name', 'institution' => 'BUET', 'ring' => ''],
                    ['photo' => '', 'name' => 'Teacher name', 'institution' => 'DU', 'ring' => ''],
                ]
            ],
            [
                'teachers',
                'Who takes the archive classes',
                null,
                'tint',
                [
                    ['photo' => '', 'name' => 'Teacher name', 'institution' => 'DU', 'ring' => ''],
                ]
            ],
            [
                'rich',
                'About this course',
                null,
                'plain',
                [
                    'html' => "Write the full course description here.\n\nUse a blank line to start a new paragraph.",
                ]
            ],
            [
                'subjects',
                'Subjects covered',
                null,
                'tint',
                [
                    ['name' => 'Physics'],
                    ['name' => 'Chemistry'],
                    ['name' => 'Biology'],
                    ['name' => 'English'],
                    ['name' => 'General Math'],
                    ['name' => 'Higher Math'],
                ]
            ],
            [
                'faq',
                'Common questions',
                null,
                'plain',
                [
                    ['q' => 'Who is this course for?', 'a' => 'Write the answer here.'],
                    ['q' => 'How do the classes run?', 'a' => 'Write the answer here.'],
                    ['q' => 'What will I learn?', 'a' => 'Write the answer here.'],
                    ['q' => 'What is the course fee?', 'a' => 'Write the answer here.'],
                    ['q' => 'How do I get help?', 'a' => 'Write the answer here.'],
                ]
            ],
        ];
        $order = (int) db_val("SELECT COALESCE(MAX(sort_order),0) FROM course_sections WHERE course_id=?", [$courseId]);
        foreach ($preset as [$kind, $title, $subtitle, $bg, $content]) {
            $order += 10;
            db_insert(
                "INSERT INTO course_sections (course_id, kind, title, subtitle, content, bg_style, sort_order)
                 VALUES (?,?,?,?,?,?,?)",
                [$courseId, $kind, $title, $subtitle, json_encode($content, JSON_UNESCAPED_UNICODE), $bg, $order]
            );
        }
        flash('success', 'Starter layout added — edit each block to fill in your content.');

    } elseif ($act === 'page_settings') {
        db_exec(
            "UPDATE courses SET campaign_text=?, emi_text=?, discount_note=?,
                                helpline_phone=?, helpline_label=?,
                                hero_video_url=?, tutorial_url=?, tutorial_note=?
              WHERE id=?",
            [
                sanitize_string($_POST['campaign_text'] ?? '', 255) ?: null,
                sanitize_string($_POST['emi_text'] ?? '', 160) ?: null,
                sanitize_string($_POST['discount_note'] ?? '', 80) ?: null,
                sanitize_string($_POST['helpline_phone'] ?? '', 40) ?: null,
                sanitize_string($_POST['helpline_label'] ?? '', 60) ?: null,
                trim((string) ($_POST['hero_video_url'] ?? '')) ?: null,
                trim((string) ($_POST['tutorial_url'] ?? '')) ?: null,
                sanitize_string($_POST['tutorial_note'] ?? '', 255) ?: null,
                $courseId,
            ]
        );
        flash('success', 'Page settings saved.');

    } elseif ($act === 'move') {
        $sid = (int) ($_POST['section_id'] ?? 0);
        $dir = $_POST['dir'] === 'up' ? -15 : 15;
        db_exec("UPDATE course_sections SET sort_order = GREATEST(0, sort_order + ?) WHERE id=? AND course_id=?", [$dir, $sid, $courseId]);

    } elseif ($act === 'mat_add') {
        $title = sanitize_string($_POST['mat_title'] ?? '', 255);
        $fileUrl = sanitize_string($_POST['mat_file_url'] ?? '', 500);
        $fileType = in_array($_POST['mat_file_type'] ?? '', ['pdf', 'video', 'doc', 'image', 'link', 'other'], true)
            ? $_POST['mat_file_type'] : 'other';
        $category = in_array($_POST['mat_category'] ?? '', ['general', 'routine'], true) ? $_POST['mat_category'] : 'general';
        $isFree = !empty($_POST['mat_is_free']) ? 1 : 0;

        if (!empty($_FILES['mat_file']['tmp_name'])) {
            $up = handle_file_upload('mat_file', 'materials');
            if ($up) {
                $fileUrl = APP_URL . $up;
            }
        }

        if (!$title || !$fileUrl || !filter_var($fileUrl, FILTER_VALIDATE_URL)) {
            flash('error', 'Title and a valid file or link URL are required.');
        } else {
            $maxOrder = (int) db_val("SELECT COALESCE(MAX(sort_order),0)+1 FROM course_materials WHERE course_id=?", [$courseId]);
            db_insert(
                "INSERT INTO course_materials (course_id, title, file_url, file_type, category, sort_order, is_free) VALUES (?,?,?,?,?,?,?)",
                [$courseId, $title, $fileUrl, $fileType, $category, $maxOrder, $isFree]
            );
            flash('success', 'Material added.');
        }

    } elseif ($act === 'mat_delete') {
        db_exec("DELETE FROM course_materials WHERE id=? AND course_id=?", [(int) ($_POST['material_id'] ?? 0), $courseId]);
        flash('success', 'Material deleted.');

    } elseif ($act === 'mat_toggle_free') {
        db_exec("UPDATE course_materials SET is_free=1-is_free WHERE id=? AND course_id=?", [(int) ($_POST['material_id'] ?? 0), $courseId]);
    }

    redirect(APP_URL . '/admin/course_sections.php?course=' . $courseId);
}

$courses = db_query("SELECT id, title_bn, slug, status FROM courses ORDER BY created_at DESC LIMIT 300");
$sections = $course ? cs_load($courseId, false) : [];
$materials = $course ? db_query("SELECT * FROM course_materials WHERE course_id=? ORDER BY sort_order ASC, id ASC", [$courseId]) : [];
$page_title = 'Course Page Builder';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($page_title) ?> — <?= APP_NAME ?></title>
    <link
        href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@700;800&family=Hind+Siliguri:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap"
        rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
    <script>const APP_URL = '<?= APP_URL ?>'; const CSRF_TOKEN = '<?= csrf_token() ?>';</script>
</head>

<body>
    <div class="admin-layout">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <div class="admin-content">

            <?php foreach (get_flash() as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:16px">
                    <span class="material-icons-round"><?= $f['type'] === 'success' ? 'check_circle' : 'error' ?></span>
                    <span><?= e($f['message']) ?></span>
                </div>
            <?php endforeach; ?>

            <div class="section-header flex" style="margin-bottom:var(--sp-6)">
                <div>
                    <h1 style="font-size:var(--text-2xl)">Course Page Builder</h1>
                    <p class="text-muted text-sm">Add blocks to build the public course detail page — no code needed.
                    </p>
                </div>
                <?php if ($course): ?>
                    <a class="btn btn-ghost" target="_blank"
                        href="<?= APP_URL ?>/course.php?slug=<?= e($course['slug']) ?>">
                        <span class="material-icons-round">visibility</span> Preview page
                    </a>
                <?php endif; ?>
            </div>

            <!-- Course picker -->
            <form method="GET" class="card"
                style="padding:var(--sp-4);margin-bottom:var(--sp-6);display:flex;gap:var(--sp-3);flex-wrap:wrap;align-items:center">
                <label class="font-semi">Course:</label>
                <select name="course" onchange="this.form.submit()"
                    style="flex:1;min-width:220px;padding:10px;border:1px solid var(--c-border);border-radius:var(--r-sm)">
                    <option value="">— Select a course —</option>
                    <?php foreach ($courses as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $courseId === (int) $c['id'] ? 'selected' : '' ?>>
                            <?= e($c['title_bn']) ?> (<?= e($c['status']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="btn btn-primary">Load</button></noscript>
            </form>

            <?php if (!$course): ?>
                <div class="empty-state">
                    <span class="material-icons-round">web</span>
                    <h3>Pick a course to start building</h3>
                    <p>Every course gets its own stack of content blocks.</p>
                </div>
            <?php else: ?>

                <!-- Page settings: sticky purchase bar, helpline, hero video -->
                <form method="POST" class="card" style="padding:var(--sp-5);margin-bottom:var(--sp-6)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="page_settings">
                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                    <div class="font-semi" style="margin-bottom:var(--sp-1)">Page settings</div>
                    <p class="text-muted text-xs" style="margin-bottom:var(--sp-4)">
                        Controls the sticky bar at the bottom of the course page, the floating helpline button and the hero
                        video.
                        Leave a field empty to hide that element.
                    </p>
                    <div class="sb-rows">
                        <div class="sb-row">
                            <label style="display:block">
                                <span class="text-xs font-semi">Campaign strip (green bar)</span>
                                <input type="text" name="campaign_text" maxlength="255"
                                    value="<?= e($course['campaign_text'] ?? '') ?>"
                                    placeholder="Early bird campaign — extra 500 discount">
                            </label>
                            <label style="display:block">
                                <span class="text-xs font-semi">EMI / finance note</span>
                                <input type="text" name="emi_text" maxlength="160"
                                    value="<?= e($course['emi_text'] ?? '') ?>" placeholder="EMI available on credit cards">
                            </label>
                        </div>
                        <div class="sb-row">
                            <label style="display:block">
                                <span class="text-xs font-semi">Discount badge text</span>
                                <input type="text" name="discount_note" maxlength="80"
                                    value="<?= e($course['discount_note'] ?? '') ?>"
                                    placeholder="1,500 off (auto-calculated if empty)">
                            </label>
                            <label style="display:block">
                                <span class="text-xs font-semi">Helpline number</span>
                                <input type="text" name="helpline_phone" maxlength="40"
                                    value="<?= e($course['helpline_phone'] ?? '') ?>" placeholder="01XXXXXXXXX">
                            </label>
                            <label style="display:block">
                                <span class="text-xs font-semi">Helpline button label</span>
                                <input type="text" name="helpline_label" maxlength="60"
                                    value="<?= e($course['helpline_label'] ?? '') ?>" placeholder="Helpline">
                            </label>
                        </div>
                        <div class="sb-row">
                            <label style="display:block">
                                <span class="text-xs font-semi">Hero video URL (play button on thumbnail)</span>
                                <input type="text" name="hero_video_url" value="<?= e($course['hero_video_url'] ?? '') ?>"
                                    placeholder="https://youtu.be/…">
                            </label>
                            <label style="display:block">
                                <span class="text-xs font-semi">Tutorial video URL</span>
                                <input type="text" name="tutorial_url" value="<?= e($course['tutorial_url'] ?? '') ?>"
                                    placeholder="https://youtu.be/…">
                            </label>
                            <label style="display:block">
                                <span class="text-xs font-semi">Tutorial note</span>
                                <input type="text" name="tutorial_note" maxlength="255"
                                    value="<?= e($course['tutorial_note'] ?? '') ?>"
                                    placeholder="N.B. Watch this video before buying">
                            </label>
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit" style="margin-top:var(--sp-4)">
                        <span class="material-icons-round">save</span> Save page settings
                    </button>
                </form>

                <!-- Downloadable materials: class routine PDF, notes, syllabus, etc. -->
                <div class="card" style="padding:var(--sp-5);margin-bottom:var(--sp-6)">
                    <div class="font-semi" style="margin-bottom:var(--sp-1)">Downloadable materials</div>
                    <p class="text-muted text-xs" style="margin-bottom:var(--sp-4)">
                        Class routine PDF, syllabus, notes — shown as download buttons on the course page's Materials tab.
                        "Free access" makes a file downloadable even by students who haven't enrolled.
                    </p>

                    <form method="POST" enctype="multipart/form-data"
                        style="display:flex;gap:var(--sp-2);flex-wrap:wrap;align-items:flex-end;margin-bottom:var(--sp-5)">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mat_add">
                        <input type="hidden" name="course_id" value="<?= $courseId ?>">
                        <label style="flex:2;min-width:180px">
                            <span class="text-xs font-semi">Title</span>
                            <input type="text" name="mat_title" required placeholder="e.g. Class Routine">
                        </label>
                        <label style="flex:2;min-width:180px">
                            <span class="text-xs font-semi">File Upload</span>
                            <input type="file" name="mat_file" class="form-control">
                        </label>
                        <label style="flex:2;min-width:180px">
                            <span class="text-xs font-semi">OR Link URL</span>
                            <input type="url" name="mat_file_url"
                                placeholder="https://drive.google.com/...">
                        </label>
                        <label style="width:120px">
                            <span class="text-xs font-semi">Category</span>
                            <select name="mat_category">
                                <option value="general">General Material</option>
                                <option value="routine">Course Routine</option>
                            </select>
                        </label>
                        <label style="width:120px">
                            <span class="text-xs font-semi">Type</span>
                            <select name="mat_file_type">
                                <option value="pdf">PDF</option>
                                <option value="doc">Word/Doc</option>
                                <option value="image">Image</option>
                                <option value="video">Video</option>
                                <option value="link">Link</option>
                                <option value="other">Other</option>
                            </select>
                        </label>
                        <label class="chip" style="cursor:pointer">
                            <input type="checkbox" name="mat_is_free" value="1"> Free access
                        </label>
                        <button class="btn btn-primary btn-sm" type="submit">
                            <span class="material-icons-round">add</span> Add
                        </button>
                    </form>

                    <?php if (!$materials): ?>
                        <p class="text-muted text-sm">No materials added yet.</p>
                    <?php else: ?>
                        <div class="data-table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Category</th>
                                        <th>Type</th>
                                        <th>Access</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($materials as $mat): ?>
                                        <tr>
                                            <td>
                                                <a href="<?= e($mat['file_url']) ?>" target="_blank"
                                                    rel="noopener"><?= e($mat['title']) ?></a>
                                            </td>
                                            <td class="text-xs" style="text-transform:capitalize"><?= e($mat['category'] ?? 'general') ?></td>
                                            <td class="text-xs" style="text-transform:uppercase"><?= e($mat['file_type']) ?></td>
                                            <td>
                                                <form method="POST" style="display:inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="mat_toggle_free">
                                                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                                                    <input type="hidden" name="material_id" value="<?= (int) $mat['id'] ?>">
                                                    <button type="submit" class="chip" style="font-size:11px">
                                                        <?= $mat['is_free'] ? 'Free' : 'Paid (enrolled only)' ?>
                                                    </button>
                                                </form>
                                            </td>
                                            <td>
                                                <form method="POST" style="display:inline"
                                                    onsubmit="return confirm('Delete this material?')">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="mat_delete">
                                                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                                                    <input type="hidden" name="material_id" value="<?= (int) $mat['id'] ?>">
                                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--c-error)">
                                                        <span class="material-icons-round" style="font-size:16px">delete</span>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Add a block -->
                <form method="POST" class="card" style="padding:var(--sp-4);margin-bottom:var(--sp-6)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                    <div class="font-semi" style="margin-bottom:var(--sp-3)">Add a block</div>
                    <div style="display:flex;gap:var(--sp-2);flex-wrap:wrap;margin-bottom:var(--sp-4)">
                        <button class="btn btn-accent btn-sm" name="action" value="preset" type="submit"
                            onclick="return confirm('Add the full starter layout (checklist, teachers, description, subjects, FAQ)?')">
                            <span class="material-icons-round">auto_fix_high</span> Add starter layout
                        </button>
                        <span class="text-xs text-muted" style="align-self:center">
                            Builds the whole page structure in one click — then edit each block.
                        </span>
                    </div>
                    <div style="display:flex;gap:var(--sp-2);flex-wrap:wrap">
                        <?php foreach ($kinds as $k => $def): ?>
                            <button class="chip" name="kind" value="<?= $k ?>" type="submit">
                                <span class="material-icons-round" style="font-size:16px"><?= $def['icon'] ?></span>
                                <?= e($def['label']) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </form>

                <?php if (!$sections): ?>
                    <div class="empty-state">
                        <span class="material-icons-round">dashboard_customize</span>
                        <h3>No blocks yet</h3>
                        <p>Use the buttons above to add your first block.</p>
                    </div>
                <?php endif; ?>

                <?php foreach ($sections as $s):
                    $def = $kinds[$s['kind']] ?? $kinds['rich'];
                    $fields = $def['fields'];
                    $data = $s['data'];
                    $rows = $def['repeat'] ? ($data ?: [[]]) : [];
                    ?>
                    <form method="POST" class="sb-item">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="course_id" value="<?= $courseId ?>">
                        <input type="hidden" name="section_id" value="<?= (int) $s['id'] ?>">

                        <div class="sb-item-head">
                            <span class="sb-kind"><span class="material-icons-round"
                                    style="font-size:13px;vertical-align:-2px"><?= $def['icon'] ?></span>
                                <?= e($def['label']) ?></span>
                            <input type="text" name="title" value="<?= e($s['title'] ?? '') ?>" placeholder="Section heading"
                                style="flex:2;min-width:180px">
                            <input type="text" name="subtitle" value="<?= e($s['subtitle'] ?? '') ?>"
                                placeholder="Sub heading (optional)" style="flex:2;min-width:180px">
                            <select name="bg_style" style="width:120px">
                                <option value="plain" <?= $s['bg_style'] === 'plain' ? 'selected' : '' ?>>White</option>
                                <option value="tint" <?= $s['bg_style'] === 'tint' ? 'selected' : '' ?>>Tinted</option>
                                <option value="dark" <?= $s['bg_style'] === 'dark' ? 'selected' : '' ?>>Dark</option>
                            </select>
                            <select name="placement" style="width:140px" title="Which tab this block shows on">
                                <option value="overview" <?= ($s['placement'] ?? 'overview') === 'overview' ? 'selected' : '' ?>>Show: Overview</option>
                                <option value="curriculum" <?= ($s['placement'] ?? 'overview') === 'curriculum' ? 'selected' : '' ?>>Show: Curriculum</option>
                            </select>
                            <input type="number" name="sort_order" value="<?= (int) $s['sort_order'] ?>" style="width:90px"
                                title="Sort order">
                            <label class="chip" style="cursor:pointer">
                                <input type="checkbox" name="is_active" value="1" <?= $s['is_active'] ? 'checked' : '' ?>> Live
                            </label>
                        </div>

                        <?php if ($def['repeat']): ?>
                            <div class="sb-rows" data-rows>
                                <?php foreach ($rows as $r): ?>
                                    <div class="sb-row">
                                        <?php foreach ($fields as $fname => [$flabel, $ftype]): ?>
                                            <?php if ($ftype === 'textarea'): ?>
                                                <textarea name="rows[<?= $fname ?>][]" rows="2"
                                                    placeholder="<?= e($flabel) ?>"><?= e($r[$fname] ?? '') ?></textarea>
                                            <?php else: ?>
                                                <input type="text" name="rows[<?= $fname ?>][]" value="<?= e($r[$fname] ?? '') ?>"
                                                    placeholder="<?= e($flabel) ?>">
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <button type="button" class="sb-del" onclick="this.closest('.sb-row').remove()"
                                            title="Remove row">
                                            <span class="material-icons-round" style="font-size:18px">close</span>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="chip" style="margin-top:var(--sp-3)" onclick="cloneRow(this)">
                                <span class="material-icons-round" style="font-size:16px">add</span> Add row
                            </button>
                        <?php else: ?>
                            <div class="sb-rows">
                                <?php foreach ($fields as $fname => [$flabel, $ftype]): ?>
                                    <label class="text-sm font-semi" style="margin-top:var(--sp-2)"><?= e($flabel) ?></label>
                                    <?php if ($ftype === 'textarea'): ?>
                                        <textarea name="single[<?= $fname ?>]" rows="5"
                                            placeholder="<?= e($flabel) ?>"><?= e($data[$fname] ?? '') ?></textarea>
                                    <?php else: ?>
                                        <input type="text" name="single[<?= $fname ?>]" value="<?= e($data[$fname] ?? '') ?>"
                                            placeholder="<?= e($flabel) ?>">
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div style="display:flex;gap:var(--sp-2);margin-top:var(--sp-4);flex-wrap:wrap;align-items:center">
                            <button class="btn btn-primary btn-sm" type="submit">
                                <span class="material-icons-round">save</span> Save block
                            </button>
                            <button class="chip" type="submit" name="action" value="move" formnovalidate
                                onclick="this.form.dir.value='up'">
                                <span class="material-icons-round" style="font-size:15px">arrow_upward</span> Up
                            </button>
                            <button class="chip" type="submit" name="action" value="move" formnovalidate
                                onclick="this.form.dir.value='down'">
                                <span class="material-icons-round" style="font-size:15px">arrow_downward</span> Down
                            </button>
                            <input type="hidden" name="dir" value="up">
                            <button class="chip" type="submit" name="action" value="delete" formnovalidate
                                style="margin-left:auto;color:var(--c-error)" onclick="return confirm('Delete this block?')">
                                <span class="material-icons-round" style="font-size:15px">delete</span> Delete
                            </button>
                        </div>
                    </form>
                <?php endforeach; ?>

            <?php endif; ?>

        </div><!-- /admin-content -->
    </div><!-- /admin-layout -->

    <script>
        function cloneRow(btn) {
            var wrap = btn.previousElementSibling;
            var last = wrap.querySelector('.sb-row:last-child');
            if (!last) return;
            var copy = last.cloneNode(true);
            copy.querySelectorAll('input, textarea').forEach(function (el) { el.value = ''; });
            wrap.appendChild(copy);
            var first = copy.querySelector('input, textarea');
            if (first) first.focus();
        }
    </script>
    <script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body>

</html>