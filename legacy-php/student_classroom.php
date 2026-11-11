<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_login();

ensure_classroom_subject_column();

$uid = (int)current_user()['id'];
$classroom_id = sanitize_int($_GET['id'] ?? 0);
if (!$classroom_id) redirect(APP_URL.'/dashboard.php');

$classroom = db_row("SELECT * FROM virtual_classrooms WHERE id=?", [$classroom_id]);
if (!$classroom) { flash('error','Virtual Classroom not found.'); redirect(APP_URL.'/dashboard.php'); }

$course_id = (int)$classroom['course_id'];
$course = db_row("SELECT * FROM courses WHERE id=?", [$course_id]);

// Verify enrollment
$enrollment = db_row("SELECT * FROM enrollments WHERE user_id=? AND course_id=? AND status='active'", [$uid, $course_id]);
if (!$enrollment) { flash('error','You are not enrolled in this course.'); redirect(APP_URL.'/dashboard.php'); }

$materials = db_query("SELECT * FROM classroom_materials WHERE classroom_id=? ORDER BY created_at ASC", [$classroom_id]);

// Fetch course teachers
$teachers_query = db_query("
    SELECT u.id 
    FROM users u
    JOIN teacher_profiles tp ON u.id = tp.user_id
    WHERE tp.id = ? OR tp.id IN (SELECT teacher_id FROM course_teachers WHERE course_id = ?)
", [$course['teacher_id'], $course_id]);
$teacher_user_ids = array_column($teachers_query, 'id');

// Handle comments
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_comment') {
        $body = sanitize_string($_POST['body']);
        $parent_id = !empty($_POST['parent_id']) ? sanitize_int($_POST['parent_id']) : null;
        if ($body) {
            db_insert("INSERT INTO classroom_comments (classroom_id, user_id, body, parent_id) VALUES (?, ?, ?, ?)", [$classroom_id, $uid, $body, $parent_id]);
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                $myAvatar = current_user()['avatar'] ? APP_URL.'/'.ltrim(current_user()['avatar'], '/') : 'https://ui-avatars.com/api/?name='.urlencode(current_user()['name']).'&background=3b82f6&color=fff';
                header('Content-Type: application/json');
                echo json_encode([
                    'status' => 'success',
                    'id' => db_insert_id(),
                    'parent_id' => $parent_id,
                    'name' => current_user()['name'],
                    'avatar' => $myAvatar,
                    'time' => date('M j, g:i A'),
                    'body' => nl2br(e($body)),
                    'is_teacher' => in_array($uid, $teacher_user_ids),
                    'is_owner' => true
                ]);
                exit;
            }
            flash('success', 'Comment posted successfully!');
        }
    } elseif ($_POST['action'] === 'edit_comment') {
        $comment_id = sanitize_int($_POST['comment_id']);
        $body = sanitize_string($_POST['body']);
        if ($body) {
            db_query("UPDATE classroom_comments SET body = ? WHERE id = ? AND user_id = ?", [$body, $comment_id, $uid]);
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'body' => nl2br(e($body))]);
                exit;
            }
            flash('success', 'Comment updated.');
        }
    } elseif ($_POST['action'] === 'delete_comment') {
        $comment_id = sanitize_int($_POST['comment_id']);
        db_query("DELETE FROM classroom_comments WHERE id = ? AND user_id = ?", [$comment_id, $uid]);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success']);
            exit;
        }
        flash('success', 'Comment deleted.');
    }
    
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['status' => 'error']);
        exit;
    }
    redirect(APP_URL."/student_classroom.php?id=$classroom_id");
}

$all_comments = db_query("
    SELECT c.*, u.name, u.avatar 
    FROM classroom_comments c 
    JOIN users u ON c.user_id = u.id 
    WHERE c.classroom_id = ? AND c.is_approved = 1 
    ORDER BY c.created_at ASC
", [$classroom_id]);

$comments_by_parent = [];
foreach ($all_comments as $c) {
    $pid = $c['parent_id'] ?: 0;
    $comments_by_parent[$pid][] = $c;
}

$all_classrooms = db_query("SELECT id, title, subject FROM virtual_classrooms WHERE course_id = ? ORDER BY sort_order ASC, id ASC", [$course_id]);
$all_classroom_groups = group_classrooms_by_subject($all_classrooms);



// Helper to render nested comments
function render_comments($parent_id, $comments_by_parent, $teacher_user_ids, $uid, $depth = 0) {
    if (empty($comments_by_parent[$parent_id])) return;
    $margin = $depth > 0 ? 40 : 0;
    echo '<div class="comment-group" style="margin-left: '.$margin.'px; margin-top: '.($depth>0 ? '16px' : '0').';">';
    foreach ($comments_by_parent[$parent_id] as $c) {
        $cAvatar = $c['avatar'] ? APP_URL.'/'.ltrim($c['avatar'], '/') : 'https://ui-avatars.com/api/?name='.urlencode($c['name']).'&background=3b82f6&color=fff';
        $isTeacher = in_array($c['user_id'], $teacher_user_ids);
        $isOwner = ($c['user_id'] == $uid);
        
        echo '
        <div class="comment-item" id="comment-'.$c['id'].'" data-id="'.$c['id'].'" style="animation: fadeIn 0.3s ease;">
            <img src="'.e($cAvatar).'" class="comment-avatar" alt="">
            <div class="comment-content">
                <div class="comment-header">
                    <span class="comment-author">'.e($c['name']).'</span>
                    '.($isTeacher ? '<span class="instructor-tag">Instructor</span>' : '').'
                    <span class="comment-time">'.date('M j, g:i A', strtotime($c['created_at'])).'</span>
                </div>
                <div class="comment-body" id="comment-body-'.$c['id'].'">'.nl2br(e($c['body'])).'</div>
                
                <div class="comment-actions" style="margin-top:6px; display:flex; gap:12px; font-size:12px;">
                    <a href="javascript:void(0)" onclick="showReplyForm('.$c['id'].')" style="color:#64748b; font-weight:600;">Reply</a>
                    '.($isOwner ? '<a href="javascript:void(0)" onclick="showEditForm('.$c['id'].')" style="color:#64748b; font-weight:600;">Edit</a>
                    <a href="javascript:void(0)" onclick="deleteComment('.$c['id'].')" style="color:#ef4444; font-weight:600;">Delete</a>' : '').'
                </div>
                
                <!-- Reply Form (Hidden) -->
                <div id="reply-form-'.$c['id'].'" class="reply-form-container" style="display:none; margin-top:16px;">
                    <form method="POST" action="" class="ajax-comment-form">
                        '.csrf_field().'
                        <input type="hidden" name="action" value="add_comment">
                        <input type="hidden" name="parent_id" value="'.$c['id'].'">
                        <div class="comment-input-wrap">
                            <textarea name="body" rows="2" placeholder="Write a reply..." required></textarea>
                            <div style="text-align:right; margin-top:8px;">
                                <button type="button" class="btn btn-ghost btn-sm" onclick="hideReplyForm('.$c['id'].')">Cancel</button>
                                <button type="submit" class="btn btn-primary btn-sm" style="border-radius:16px;">Post Reply</button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Edit Form (Hidden) -->
                <div id="edit-form-'.$c['id'].'" class="edit-form-container" style="display:none; margin-top:16px;">
                    <form method="POST" action="" class="ajax-edit-form">
                        '.csrf_field().'
                        <input type="hidden" name="action" value="edit_comment">
                        <input type="hidden" name="comment_id" value="'.$c['id'].'">
                        <div class="comment-input-wrap">
                            <textarea name="body" rows="2" required>'.e($c['body']).'</textarea>
                            <div style="text-align:right; margin-top:8px;">
                                <button type="button" class="btn btn-ghost btn-sm" onclick="hideEditForm('.$c['id'].')">Cancel</button>
                                <button type="submit" class="btn btn-primary btn-sm" style="border-radius:16px;">Save Changes</button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <div class="nested-comments-container" id="replies-'.$c['id'].'">';
                render_comments($c['id'], $comments_by_parent, $teacher_user_ids, $uid, $depth + 1);
        echo '  </div>
            </div>
        </div>';
    }
    echo '</div>';
}

$groups = [
    'video_live' => [],
    'documents' => [],
    'notices' => [],
    'exams' => []
];

foreach ($materials as $mat) {
    if (in_array($mat['kind'], ['video', 'live'])) {
        $groups['video_live'][] = $mat;
    } elseif (in_array($mat['kind'], ['slide', 'pdf'])) {
        $groups['documents'][] = $mat;
    } elseif (in_array($mat['kind'], ['note', 'notice'])) {
        $groups['notices'][] = $mat;
    } elseif (in_array($mat['kind'], ['exam', 'cq_exam'])) {
        $groups['exams'][] = $mat;
    }
}

function get_viewer_html($mat) {
    if (!$mat || !$mat['url']) return '<div class="empty-viewer"><span class="material-icons-round">description</span><p>No media attached to this content.</p></div>';
    
    $url = $mat['url'];
    $kind = $mat['kind'];
    
    if ($kind === 'video' || $kind === 'live') {
        $isYoutubeHost = strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false;
        if ($isYoutubeHost) {
            $yid = extract_youtube_id($url);

            // Extract playlist/index
            preg_match('/[?&]list=([^&]+)/i', $url, $listMatch);
            preg_match('/[?&]index=([^&]+)/i', $url, $indexMatch);
            $listParam = !empty($listMatch[1]) ? '&amp;list=' . htmlspecialchars($listMatch[1], ENT_QUOTES) : '';
            $indexParam = !empty($indexMatch[1]) ? '&amp;index=' . htmlspecialchars($indexMatch[1], ENT_QUOTES) : '';

            if ($yid) {
                return '
                <div class="plyr__video-embed plyr-player">
                    <iframe
                        src="https://www.youtube.com/embed/'.$yid.'?origin='.APP_URL.$listParam.$indexParam.'&amp;iv_load_policy=3&amp;modestbranding=1&amp;playsinline=1&amp;showinfo=0&amp;rel=0&amp;enablejsapi=1"
                        allowfullscreen
                        allowtransparency
                        allow="autoplay"
                        style="pointer-events: none;">
                    </iframe>
                </div>';
            }
            return '<div class="empty-viewer"><span class="material-icons-round">error_outline</span><p>Couldn\'t recognize this YouTube link. Please edit the material and paste the full video URL (e.g. https://www.youtube.com/watch?v=...).</p></div>';
        } elseif (strpos($url, 'vimeo.com') !== false) {
            preg_match('/vimeo\.com\/([0-9]+)/i', $url, $match);
            $vid = $match[1] ?? '';
            if ($vid) return '<div class="plyr__video-embed plyr-player"><iframe src="https://player.vimeo.com/video/'.$vid.'?loop=false&amp;byline=false&amp;portrait=false&amp;title=false&amp;speed=true&amp;transparent=0&amp;gesture=media" allowfullscreen allowtransparency allow="autoplay"></iframe></div>';
        }
        return '<video class="plyr-player" playsinline controls data-poster=""><source src="'.e($url).'" type="video/mp4" /></video>';
    }
    
    if ($kind === 'slide' || $kind === 'pdf') {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            return '<div class="img-viewer"><img src="'.e($url).'" alt="Image"></div>';
        }
        if (in_array($ext, ['ppt','pptx','doc','docx']) && strpos($url, 'http') === 0) {
            return '<iframe src="https://view.officeapps.live.com/op/embed.aspx?src='.urlencode($url).'" frameborder="0"></iframe>';
        }
        return '<iframe src="'.e($url).'" frameborder="0"></iframe>';
    }
    
    return '<div class="empty-viewer"><span class="material-icons-round">open_in_new</span><p><a href="'.e($url).'" target="_blank" class="btn btn-primary" style="border-radius:24px;margin-top:16px;">Open Link Externally</a></p></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($classroom['title']) ?> — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.plyr.io/3.7.8/plyr.css" />
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<style>
body { 
    background: #f8f9fe; 
    color: #1a1a2e;
    font-family: 'Plus Jakarta Sans', sans-serif;
    margin: 0; padding: 0;
    min-height: 100vh;
    display: flex; flex-direction: column;
}
.app-header {
    background: #ffffff;
    border-bottom: 1px solid var(--c-border);
    box-shadow: 0 4px 12px rgba(0,0,0,0.02);
    height: 70px;
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 24px; flex-shrink: 0;
    position: sticky; top: 0; z-index: 100;
}
.header-title {
    display: flex; align-items: center; gap: 16px;
    min-width: 0;
}
.header-title-text { min-width: 0; }
.header-title h1 {
    font-size: 20px; font-weight: 800; margin: 0;
    color: #1a1a2e;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.course-name {
    font-size: 13px; color: #64748b; font-weight: 600;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.btn-back {
    display: flex; align-items: center; justify-content: center;
    width: 40px; height: 40px; border-radius: 50%;
    background: #f1f5f9; color: #334155;
    text-decoration: none; transition: all .2s;
}
.btn-back:hover { background: #e2e8f0; transform: translateX(-2px); }

.app-container {
    display: grid;
    grid-template-columns: 280px 1fr 340px;
    gap: 24px;
    padding: 32px 24px;
    max-width: 1600px;
    margin: 0 auto;
    width: 100%; box-sizing: border-box;
    align-items: start;
}

@media (max-width: 1100px) {
    .app-container { grid-template-columns: 250px 1fr; }
    .sidebar-right { grid-column: span 2; }
}
@media (max-width: 800px) {
    .app-container { grid-template-columns: 1fr; }
    .sidebar-right, .sidebar-left { grid-column: span 1; }
    .sidebar-right { position: static; max-height: none; }
}

/* Left Sidebar */
.sidebar-left {
    background: transparent;
}
.sidebar-left h3 { margin: 0 0 16px; font-size: 16px; font-weight: 800; color: #1a1a2e; }
.chapter-list { display: flex; flex-direction: column; gap: 8px; }
.chapter-item {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 16px; border-radius: 12px;
    text-decoration: none; color: #475569; font-weight: 600; font-size: 14px;
    background: transparent; transition: all 0.2s;
}
.chapter-item:hover { background: #f1f5f9; color: #1a1a2e; }
.chapter-item.active { background: var(--c-primary); color: #fff; box-shadow: 0 4px 12px rgba(91, 33, 182,0.3); }
.chapter-item .material-icons-round { font-size: 18px; }

/* Main Content */
.main-content {
    display: flex; flex-direction: column; gap: 32px;
}
.page-title { margin: 0 0 24px; font-size: 28px; font-weight: 800; color: #1a1a2e; }

/* Viewer Container */
.viewer-container {
    background: #000;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    position: relative;
    border: 1px solid var(--c-border);
    aspect-ratio: 16/9;
}
.viewer-container iframe, .viewer-container video {
    width: 100%; height: 100%; border: none; object-fit: contain;
}
.viewer-container .plyr { width: 100%; height: 100%; --plyr-color-main: var(--c-primary); }

/* Colorful Cards Grid */
.cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 20px;
}

.colorful-card {
    border-radius: 24px;
    padding: 24px;
    display: flex; flex-direction: column; gap: 16px;
    color: #fff; text-decoration: none;
    transition: transform 0.2s, box-shadow 0.2s;
    position: relative; overflow: hidden;
    min-height: 180px;
}
.colorful-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(0,0,0,0.15); }
.colorful-card h3 { margin: 0; font-size: 20px; font-weight: 800; z-index: 2; line-height: 1.3; }
.colorful-card p { margin: 0; font-size: 14px; opacity: 0.9; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; z-index: 2; }
.card-icon {
    position: absolute; top: 16px; right: 16px; width: 40px; height: 40px; border-radius: 50%;
    background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; z-index: 2;
}

/* Card Themes */
.card-exam { background: linear-gradient(135deg, #ff7a85 0%, #ff5263 100%); }
.card-doc { background: linear-gradient(135deg, #7c4dff 0%, #651fff 100%); }
.card-notice { background: linear-gradient(135deg, #ffb74d 0%, #ff9800 100%); }
.card-video { background: linear-gradient(135deg, #4dd0e1 0%, #00bcd4 100%); }

.colorful-card .btn-card {
    margin-top: auto; z-index: 2; background: #fff; color: #1a1a2e; border: none;
    padding: 8px 16px; border-radius: 20px; font-weight: 700; font-size: 13px; text-align: center;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: max-content;
}

/* Right Sidebar (Discussion) */
.sidebar-right {
    background: #fff;
    border-radius: 24px; border: 1px solid var(--c-border);
    padding: 24px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.02);
    position: sticky; top: 100px;
    max-height: calc(100vh - 120px);
    display: flex; flex-direction: column;
}
.sidebar-right h3 { margin: 0 0 20px; font-size: 18px; font-weight: 800; color: #1a1a2e; }

.discussion-scroll {
    flex: 1; overflow-y: auto; padding-right: 8px;
    display: flex; flex-direction: column; gap: 20px;
}
.comment-form { display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid var(--c-border); }
.comment-avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; background: #e2e8f0; flex-shrink: 0; }
.comment-input-wrap textarea {
    width: 100%; background: #f8f9fe; border: 1px solid var(--c-border); box-sizing: border-box;
    color: #1a1a2e; border-radius: 16px; padding: 12px; font-family: inherit; font-size: 14px;
    resize: none; outline: none; transition: border-color 0.2s;
}
.comment-input-wrap textarea:focus { border-color: var(--c-primary); }
.comment-item { display: flex; gap: 12px; }
.comment-content { flex: 1; }
.comment-header { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap; }
.comment-author { font-weight: 700; color: #1a1a2e; font-size: 14px; }
.comment-time { color: #64748b; font-size: 11px; }
.comment-body { color: #334155; line-height: 1.5; margin: 0; font-size: 14px; }

.instructor-tag { font-size: 9px; background: var(--c-primary); color: #fff; padding: 2px 6px; border-radius: 10px; font-weight: 700; }
.btn-sm { padding: 4px 12px; font-size: 12px; }

@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

/* Custom Scrollbar */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1); border-radius: 10px; }
::-webkit-scrollbar-thumb:hover { background: rgba(0,0,0,0.2); }
</style>
</head>
<body>

<header class="app-header">
    <div class="header-title">
        <a href="<?= APP_URL ?>/learn.php?course=<?= $course_id ?>" class="btn-back" aria-label="Back to Course">
            <span class="material-icons-round">arrow_back</span>
        </a>
        <div class="header-title-text">
            <div class="course-name"><?= e($course['title_en']?:$course['title_bn']) ?></div>
            <h1><?= e($classroom['title']) ?></h1>
        </div>
    </div>
    <div>
        <a href="<?= APP_URL ?>/dashboard.php" style="color:#94a3b8;text-decoration:none;font-weight:600;font-size:14px;display:flex;align-items:center;gap:8px">
            <span class="material-icons-round" style="font-size:18px">dashboard</span> Dashboard
        </a>
    </div>
</header>

<div class="app-container">
    
    <!-- LEFT SIDEBAR: Navigation -->
    <aside class="sidebar-left">
        <h3>Classrooms</h3>
        <?php foreach ($all_classroom_groups as $roomSubject => $roomGroup): ?>
            <?php if (count($all_classroom_groups) > 1): ?>
            <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin:16px 0 8px 4px"><?= e($roomSubject) ?></div>
            <?php endif; ?>
            <div class="chapter-list">
                <?php foreach ($roomGroup as $room): ?>
                    <a href="?id=<?= $room['id'] ?>" class="chapter-item <?= $room['id'] == $classroom_id ? 'active' : '' ?>">
                        <span class="material-icons-round"><?= $room['id'] == $classroom_id ? 'play_circle' : 'article' ?></span>
                        <?= e($room['title']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </aside>

    <!-- MAIN CONTENT: Videos and Cards -->
    <main class="main-content">
        <h2 class="page-title">Today's Tasks</h2>
        
        <?php if (empty($materials)): ?>
            <div style="text-align:center; padding: 100px 0; background:#fff; border-radius:24px; border:1px solid var(--c-border);">
                <span class="material-icons-round" style="font-size:64px; color:#cbd5e1; margin-bottom:16px;">inventory_2</span>
                <h2 style="font-size:24px; font-weight:800; color:#1a1a2e; margin:0 0 8px;">No Materials Found</h2>
                <p style="color:#64748b;">The instructor has not added any content to this classroom yet.</p>
            </div>
        <?php endif; ?>

        <!-- Videos & Live Streams (Full Width Viewer) -->
        <?php if (!empty($groups['video_live'])): ?>
            <?php foreach ($groups['video_live'] as $mat): ?>
                <div style="margin-bottom: 8px;">
                    <div class="viewer-container">
                        <?= get_viewer_html($mat) ?>
                    </div>
                    <div style="margin-top:16px;">
                        <h2 style="margin:0 0 8px; font-size:20px; font-weight:800; color:#1a1a2e;"><?= e($mat['title']) ?></h2>
                        <?php if ($mat['content_text']): ?>
                            <p style="color:#64748b; line-height:1.6; font-size:14px; margin:0;"><?= nl2br(e($mat['content_text'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Exams Section -->
        <?php if (!empty($groups['exams'])): ?>
            <div style="margin-top: 40px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:16px;">
                    <span class="material-icons-round" style="color:var(--c-primary); font-size:24px;">quiz</span>
                    <h3 style="margin:0; font-size:22px; font-weight:800; color:#1a1a2e;">Exams &amp; Quizzes</h3>
                </div>
                <div class="cards-grid">
                    <?php foreach ($groups['exams'] as $mat): 
                        $has_attempted = false;
                        $result_url = '#';
                        
                        if ($mat['kind'] === 'cq_exam') {
                            $sub = db_row("SELECT id FROM cq_submissions WHERE exam_id=? AND user_id=?", [(int)$mat['reference_id'], $uid]);
                            if ($sub) {
                                $has_attempted = true;
                                $result_url = APP_URL.'/cq_take.php?id='.$mat['reference_id'];
                            }
                        } else {
                            $att = db_row("SELECT id FROM exam_attempts WHERE exam_id=? AND user_id=? AND status IN ('submitted','graded')", [(int)$mat['reference_id'], $uid]);
                            if ($att) {
                                $has_attempted = true;
                                $result_url = APP_URL.'/exam-result.php?attempt='.$att['id'];
                            }
                        }
                    ?>
                        <div class="colorful-card card-exam">
                            <div class="card-icon"><span class="material-icons-round" style="font-size:20px">quiz</span></div>
                            <h3><?= e($mat['title']) ?></h3>
                            <?php if ($mat['content_text']): ?>
                                <p><?= e($mat['content_text']) ?></p>
                            <?php endif; ?>
                            <div style="margin-top:auto; display:flex; gap:8px;">
                                <a href="<?= e($mat['url']) ?>" target="_blank" class="btn-card" style="flex:1; text-align:center;">Take Exam</a>
                                <?php if ($has_attempted): ?>
                                    <a href="<?= e($result_url) ?>" target="_blank" class="btn-card" style="flex:1; text-align:center; background:#fff; color:var(--c-primary);">View Result</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Documents Section -->
        <?php if (!empty($groups['documents'])): ?>
            <div style="margin-top: 40px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:16px;">
                    <span class="material-icons-round" style="color:var(--c-primary); font-size:24px;">library_books</span>
                    <h3 style="margin:0; font-size:22px; font-weight:800; color:#1a1a2e;">Reading Materials</h3>
                </div>
                <div class="cards-grid">
                    <?php foreach ($groups['documents'] as $mat): ?>
                        <a href="<?= e($mat['url']) ?>" target="_blank" class="colorful-card card-doc">
                            <div class="card-icon"><span class="material-icons-round" style="font-size:20px">description</span></div>
                            <h3><?= e($mat['title']) ?></h3>
                            <?php if ($mat['content_text']): ?>
                                <p><?= e($mat['content_text']) ?></p>
                            <?php endif; ?>
                            <span class="btn-card">Read / Download</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Announcements Section -->
        <?php if (!empty($groups['notices'])): ?>
            <div style="margin-top: 40px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:16px;">
                    <span class="material-icons-round" style="color:var(--c-primary); font-size:24px;">campaign</span>
                    <h3 style="margin:0; font-size:22px; font-weight:800; color:#1a1a2e;">Announcements</h3>
                </div>
                <div class="cards-grid">
                    <?php foreach ($groups['notices'] as $mat): ?>
                        <div class="colorful-card card-notice">
                            <div class="card-icon"><span class="material-icons-round" style="font-size:20px">campaign</span></div>
                            <h3><?= e($mat['title']) ?></h3>
                            <?php if ($mat['content_text']): ?>
                                <p><?= nl2br(e($mat['content_text'])) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- RIGHT SIDEBAR: Discussion -->
    <aside class="sidebar-right">
        <h3>Classroom Discussion</h3>
        
        <form method="POST" action="" class="comment-form ajax-comment-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_comment">
            <?php $myAvatar = current_user()['avatar'] ? APP_URL.'/'.ltrim(current_user()['avatar'], '/') : 'https://ui-avatars.com/api/?name='.urlencode(current_user()['name']).'&background=3b82f6&color=fff'; ?>
            <div style="display:flex; gap:12px;">
                <img src="<?= e($myAvatar) ?>" class="comment-avatar" alt="">
                <div class="comment-input-wrap" style="flex:1;">
                    <textarea name="body" rows="2" placeholder="Ask a question..." required></textarea>
                </div>
            </div>
            <div style="text-align:right;">
                <button type="submit" class="btn btn-primary" style="border-radius:24px;padding:8px 20px;font-weight:700;">Post</button>
            </div>
        </form>
        
        <div class="discussion-scroll">
            <div class="comment-list">
                <?php if (empty($comments_by_parent)): ?>
                    <div class="empty-state-msg" style="text-align:center;color:#94a3b8;padding:20px;font-size:14px;">No comments yet.</div>
                <?php else: ?>
                    <?php render_comments(0, $comments_by_parent, $teacher_user_ids, $uid, 0); ?>
                <?php endif; ?>
            </div>
        </div>
    </aside>
    
</div>

<script src="https://cdn.plyr.io/3.7.8/plyr.polyfilled.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Initialize Plyr for all video players
    const players = document.querySelectorAll('.plyr-player');
    players.forEach(playerEl => {
        const player = new Plyr(playerEl, {
            youtube: { noCookie: false, rel: 0, showinfo: 0, iv_load_policy: 3, modestbranding: 1 },
            controls: ['play-large', 'play', 'progress', 'current-time', 'mute', 'volume', 'captions', 'settings', 'pip', 'airplay', 'fullscreen'],
            hideControls: true
        });
        playerEl.addEventListener('contextmenu', e => e.preventDefault());
    });
    
    // Handle Comment Forms (Main & Replies)
    document.addEventListener('submit', function(e) {
        if (e.target.matches('.ajax-comment-form')) {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);
            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerText = 'Posting...';

            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    form.reset();
                    
                    const tag = data.is_teacher ? '<span class="instructor-tag">Instructor</span>' : '';
                    const editDelete = data.is_owner ? `
                        <a href="javascript:void(0)" onclick="showEditForm(${data.id})" style="color:#64748b; font-weight:600;">Edit</a>
                        <a href="javascript:void(0)" onclick="deleteComment(${data.id})" style="color:#ef4444; font-weight:600;">Delete</a>
                    ` : '';

                    const html = `
                    <div class="comment-item" id="comment-${data.id}" data-id="${data.id}" style="animation: fadeIn 0.3s ease;">
                        <img src="${data.avatar}" class="comment-avatar" alt="">
                        <div class="comment-content">
                            <div class="comment-header">
                                <span class="comment-author">${data.name}</span>
                                ${tag}
                                <span class="comment-time">${data.time}</span>
                            </div>
                            <div class="comment-body" id="comment-body-${data.id}">${data.body}</div>
                            
                            <div class="comment-actions" style="margin-top:6px; display:flex; gap:12px; font-size:12px;">
                                <a href="javascript:void(0)" onclick="showReplyForm(${data.id})" style="color:#64748b; font-weight:600;">Reply</a>
                                ${editDelete}
                            </div>
                            
                            <!-- Reply Form (Hidden) -->
                            <div id="reply-form-${data.id}" class="reply-form-container" style="display:none; margin-top:16px;">
                                <form method="POST" action="" class="ajax-comment-form">
                                    <input type="hidden" name="csrf_token" value="${form.querySelector('[name=csrf_token]').value}">
                                    <input type="hidden" name="action" value="add_comment">
                                    <input type="hidden" name="parent_id" value="${data.id}">
                                    <div class="comment-input-wrap">
                                        <textarea name="body" rows="2" placeholder="Write a reply..." required></textarea>
                                        <div style="text-align:right; margin-top:8px;">
                                            <button type="button" class="btn btn-ghost btn-sm" onclick="hideReplyForm(${data.id})">Cancel</button>
                                            <button type="submit" class="btn btn-primary btn-sm" style="border-radius:16px;">Post Reply</button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <!-- Edit Form (Hidden) -->
                            <div id="edit-form-${data.id}" class="edit-form-container" style="display:none; margin-top:16px;">
                                <form method="POST" action="" class="ajax-edit-form">
                                    <input type="hidden" name="csrf_token" value="${form.querySelector('[name=csrf_token]').value}">
                                    <input type="hidden" name="action" value="edit_comment">
                                    <input type="hidden" name="comment_id" value="${data.id}">
                                    <div class="comment-input-wrap">
                                        <textarea name="body" rows="2" required>${data.body.replace(/<br\s*[\/]?>/gi, "\n")}</textarea>
                                        <div style="text-align:right; margin-top:8px;">
                                            <button type="button" class="btn btn-ghost btn-sm" onclick="hideEditForm(${data.id})">Cancel</button>
                                            <button type="submit" class="btn btn-primary btn-sm" style="border-radius:16px;">Save Changes</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            <div class="nested-comments-container" id="replies-${data.id}"></div>
                        </div>
                    </div>`;

                    if (data.parent_id) {
                        const repliesContainer = document.getElementById('replies-' + data.parent_id);
                        if (!repliesContainer.querySelector('.comment-group')) {
                            repliesContainer.innerHTML = '<div class="comment-group" style="margin-left: 40px; margin-top: 16px;"></div>';
                        }
                        repliesContainer.querySelector('.comment-group').insertAdjacentHTML('beforeend', html);
                        hideReplyForm(data.parent_id);
                    } else {
                        const list = document.querySelector('.comment-list');
                        const emptyMsg = list.querySelector('.empty-state-msg');
                        if (emptyMsg) emptyMsg.remove();
                        list.insertAdjacentHTML('afterbegin', html);
                    }
                }
                submitBtn.disabled = false;
                submitBtn.innerText = data.parent_id ? 'Post Reply' : 'Post Comment';
            })
            .catch(err => {
                console.error(err);
                submitBtn.disabled = false;
                submitBtn.innerText = data.parent_id ? 'Post Reply' : 'Post Comment';
            });
        }

        if (e.target.matches('.ajax-edit-form')) {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);
            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerText = 'Saving...';
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    const cid = formData.get('comment_id');
                    document.getElementById('comment-body-' + cid).innerHTML = data.body;
                    hideEditForm(cid);
                }
                submitBtn.disabled = false;
                submitBtn.innerText = 'Save Changes';
            })
            .catch(err => {
                console.error(err);
                submitBtn.disabled = false;
                submitBtn.innerText = 'Save Changes';
            });
        }
    });
});

function showReplyForm(id) {
    document.getElementById('reply-form-'+id).style.display = 'block';
    document.getElementById('edit-form-'+id).style.display = 'none';
}
function hideReplyForm(id) {
    document.getElementById('reply-form-'+id).style.display = 'none';
}
function showEditForm(id) {
    document.getElementById('edit-form-'+id).style.display = 'block';
    document.getElementById('reply-form-'+id).style.display = 'none';
}
function hideEditForm(id) {
    document.getElementById('edit-form-'+id).style.display = 'none';
}
function deleteComment(id) {
    if (confirm('Are you sure you want to delete this comment? Replies will also be deleted.')) {
        const formData = new FormData();
        formData.append('action', 'delete_comment');
        formData.append('comment_id', id);
        formData.append('csrf_token', document.querySelector('[name=csrf_token]').value);
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const el = document.getElementById('comment-'+id);
                el.style.opacity = '0';
                setTimeout(() => el.remove(), 300);
            }
        });
    }
}
</script>
</body>
</html>