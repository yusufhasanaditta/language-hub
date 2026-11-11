<?php
/**
 * Notice board — a social feed of notices from admins and teachers.
 * Students read, react and comment; admins/teachers post from the composer.
 */
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notice_lib.php';

session_start_secure();
send_security_headers();

$me       = current_user();
$canPost  = can_post_notice();
$courseId = isset($_GET['course']) ? (int)$_GET['course'] : null;

// ── Create / update a notice ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_login();
    if (!csrf_verify()) {
        flash('error', 'Session expired. Please try again.');
        redirect(APP_URL . '/notices.php');
    }
    if (!$canPost) {
        flash('error', 'Only admins and teachers can post notices.');
        redirect(APP_URL . '/notices.php');
    }

    $formAction = $_POST['form_action'] ?? 'create';

    if ($formAction === 'toggle_pin' || $formAction === 'toggle_lock') {
        $nid    = (int)($_POST['notice_id'] ?? 0);
        $notice = db_row("SELECT * FROM notices WHERE id=?", [$nid]);
        if ($notice && can_manage_notice($notice)) {
            $col = $formAction === 'toggle_pin' ? 'is_pinned' : 'is_locked';
            db_exec("UPDATE notices SET $col = 1 - $col WHERE id=?", [$nid]);
            flash('success', 'Notice updated.');
        } else {
            flash('error', 'Not allowed.');
        }
        redirect(APP_URL . '/notices.php');
    }

    $body = trim((string)($_POST['body'] ?? ''));
    if ($body === '') {
        flash('error', 'Notice text cannot be empty.');
        redirect(APP_URL . '/notices.php');
    }

    $title    = sanitize_string((string)($_POST['title'] ?? ''), 200) ?: null;
    $link     = trim((string)($_POST['link_url'] ?? '')) ?: null;
    $courseFor = (int)($_POST['course_id'] ?? 0) ?: null;
    $audience = $courseFor ? 'course' : (($_POST['audience'] ?? 'all') === 'students' ? 'students' : 'all');
    $pinned   = !empty($_POST['is_pinned']) ? 1 : 0;

    if ($link && !filter_var($link, FILTER_VALIDATE_URL)) {
        flash('error', 'That link does not look like a valid URL.');
        redirect(APP_URL . '/notices.php');
    }

    // Teachers may only target their own courses (co-teachers included)
    if ($courseFor && !is_admin()) {
        $myTpId = (int) db_val("SELECT id FROM teacher_profiles WHERE user_id=?", [$me['id']]);
        if (!$myTpId || !teacher_owns_course($courseFor, $myTpId)) {
            flash('error', 'You can only post notices to your own courses.');
            redirect(APP_URL . '/notices.php');
        }
    }

    $image = handle_image_upload('image', 'notices');

    $newId = db_insert(
        "INSERT INTO notices (author_id, course_id, audience, title, body, image, link_url, is_pinned)
         VALUES (?,?,?,?,?,?,?,?)",
        [$me['id'], $courseFor, $audience, $title, $body, $image, $link, $pinned]
    );

    // Push a bell notification to whoever this notice targets.
    $noticeUrl = APP_URL . '/notices.php#notice-' . $newId;
    $noticeSnippet = mb_substr($body, 0, 140, 'UTF-8');
    if ($audience === 'course' && $courseFor) {
        $courseTitle = db_val("SELECT title_bn FROM courses WHERE id=?", [$courseFor]) ?? 'your course';
        notify_course_students($courseFor, 'notice', ($title ?: ('New notice: ' . $courseTitle)), $noticeSnippet, $noticeUrl);
    } elseif ($audience === 'students') {
        notify_all_students('notice', ($title ?: 'New notice from Language Hub'), $noticeSnippet, $noticeUrl);
    } else {
        notify_all_users('notice', ($title ?: 'New notice from Language Hub'), $noticeSnippet, $noticeUrl);
    }

    flash('success', 'Notice published.');
    redirect(APP_URL . '/notices.php#notice-' . $newId);
}

// ── Read ──────────────────────────────────────────────────────────────────
$notices = notice_feed(20, 0, $courseId);

// Courses the composer can target
$myCourses = [];
if ($canPost) {
    $myCourses = is_admin()
        ? db_query("SELECT id, title_bn FROM courses WHERE status='published' ORDER BY title_bn LIMIT 200")
        : db_query(
            "SELECT c.id, c.title_bn FROM courses c
             JOIN teacher_profiles tp ON tp.id = c.teacher_id
             WHERE tp.user_id = ? OR EXISTS (
                 SELECT 1 FROM course_teachers ct
                 JOIN teacher_profiles tp2 ON tp2.id = ct.teacher_id
                 WHERE ct.course_id = c.id AND tp2.user_id = ?
             ) ORDER BY c.title_bn",
            [$me['id'], $me['id']]
          );
}

$page_title = 'Notice Board';
$page_desc  = 'Announcements, class updates and news from ' . APP_NAME . '.';
include __DIR__ . '/includes/header.php';
?>

<section class="section-sm" style="background:linear-gradient(135deg,#150F3D 0%,#2A1B7A 100%);color:#fff">
    <div class="container">
        <h1 style="color:#fff;font-size:clamp(1.5rem,4vw,2.2rem)">Notice Board</h1>
        <p style="color:rgba(255,255,255,.75);margin-top:var(--sp-2)">
            Announcements and class updates from your teachers and Language Hub.
        </p>
    </div>
</section>

<section class="section-sm">
    <div class="container">
        <div class="feed-wrap">

            <?php if ($canPost): ?>
            <!-- ── Composer ─────────────────────────────────────────────── -->
            <form class="composer" method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="create">

                <div class="composer-row">
                    <img src="<?= e(avatar_url($me['avatar'] ?? null, $me['name'])) ?>" alt=""
                         style="width:44px;height:44px;border-radius:50%;object-fit:cover;flex:0 0 auto">
                    <div style="flex:1;min-width:0">
                        <input type="text" name="title" placeholder="Notice title (optional)" maxlength="200"
                               style="width:100%;border:1px solid var(--c-border);border-radius:var(--r-md);padding:10px var(--sp-3);margin-bottom:var(--sp-2);font-family:inherit">
                        <textarea name="body" required placeholder="Write a notice for your students…" maxlength="5000"></textarea>
                    </div>
                </div>

                <div class="composer-tools">
                    <?php if ($myCourses): ?>
                    <select name="course_id" style="padding:9px var(--sp-3);border:1px solid var(--c-border);border-radius:var(--r-sm);font-family:inherit;max-width:100%">
                        <option value="">📣 Everyone</option>
                        <?php foreach ($myCourses as $c): ?>
                        <option value="<?= (int)$c['id'] ?>">🎓 <?= e($c['title_bn']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <label class="chip" style="cursor:pointer">
                        <span class="material-icons-round" style="font-size:16px">image</span>
                        <span id="imgLabel">Photo</span>
                        <input type="file" name="image" accept="image/*" hidden
                               onchange="document.getElementById('imgLabel').textContent = this.files[0] ? this.files[0].name.slice(0,18) : 'Photo'">
                    </label>

                    <input type="url" name="link_url" placeholder="Link (optional)"
                           style="flex:1;min-width:160px;padding:9px var(--sp-3);border:1px solid var(--c-border);border-radius:var(--r-sm);font-family:inherit">

                    <label class="chip" style="cursor:pointer">
                        <input type="checkbox" name="is_pinned" value="1" style="margin-right:4px">
                        <span class="material-icons-round" style="font-size:15px">push_pin</span> Pin
                    </label>

                    <button type="submit" class="btn btn-primary" style="margin-left:auto">
                        <span class="material-icons-round">send</span> Post Notice
                    </button>
                </div>
            </form>
            <?php endif; ?>

            <!-- ── Feed ─────────────────────────────────────────────────── -->
            <div id="noticeFeed">
                <?php if (empty($notices)): ?>
                <div class="empty-state">
                    <span class="material-icons-round">campaign</span>
                    <h3>No notices yet</h3>
                    <p>When your teachers post an announcement, it shows up here.</p>
                </div>
                <?php else: ?>
                    <?php foreach ($notices as $n): ?>
                        <?= render_notice_card($n) ?>
                        <?php if (can_manage_notice($n)): ?>
                        <div style="display:flex;gap:var(--sp-2);margin:calc(var(--sp-5) * -1) 0 var(--sp-5);justify-content:flex-end">
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="toggle_pin">
                                <input type="hidden" name="notice_id" value="<?= (int)$n['id'] ?>">
                                <button class="chip" type="submit">
                                    <span class="material-icons-round" style="font-size:14px">push_pin</span>
                                    <?= $n['is_pinned'] ? 'Unpin' : 'Pin' ?>
                                </button>
                            </form>
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="toggle_lock">
                                <input type="hidden" name="notice_id" value="<?= (int)$n['id'] ?>">
                                <button class="chip" type="submit">
                                    <span class="material-icons-round" style="font-size:14px"><?= $n['is_locked'] ? 'lock_open' : 'lock' ?></span>
                                    <?= $n['is_locked'] ? 'Open comments' : 'Close comments' ?>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
