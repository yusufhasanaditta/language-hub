<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_teacher();

ensure_classroom_subject_column();

$user = current_user();
$uid = (int)$user['id'];
$isAdmin = is_admin();
$tpId = 0;
if (!$isAdmin) {
    $tp = db_row("SELECT * FROM teacher_profiles WHERE user_id=?", [$uid]);
    if (!$tp) { die('Teacher profile not found.'); }
    $tpId = (int)$tp['id'];
}

$classroom_id = sanitize_int($_GET['id'] ?? 0);
if (!$classroom_id) { redirect(APP_URL."/".($isAdmin?'admin':'teacher')."/courses.php"); }

// Fetch classroom
$classroom = db_row("SELECT * FROM virtual_classrooms WHERE id=?", [$classroom_id]);
if (!$classroom) { flash('error','Classroom not found.'); redirect(APP_URL."/".($isAdmin?'admin':'teacher')."/courses.php"); }

$course_id = (int)$classroom['course_id'];

// Check permission (admins can manage any course)
$course = $isAdmin
    ? db_row("SELECT c.* FROM courses c WHERE c.id=?", [$course_id])
    : db_row("
        SELECT c.*
        FROM courses c
        WHERE c.id=? AND (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))
    ", [$course_id, $tpId, $tpId]);

if (!$course) { flash('error', 'You do not have access to this course.'); redirect(APP_URL."/".($isAdmin?'admin':'teacher')."/courses.php"); }

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_check();
    $action = $_POST['action'];
    
    if ($action === 'add_material') {
        $kind = $_POST['kind'];
        $title = sanitize_string($_POST['title']);
        $content_text = $_POST['content_text'] ?? '';
        $url = $_POST['url'] ?? '';
        
        // Handle file upload if present
        if (isset($_FILES['upload_file']) && $_FILES['upload_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['upload_file'];
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $allowed = ['pdf', 'ppt', 'pptx', 'doc', 'docx', 'jpg', 'png', 'jpeg'];
            if (in_array(strtolower($ext), $allowed)) {
                $dir = UPLOAD_PATH . 'materials/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = bin2hex(random_bytes(12)) . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                    $url = APP_URL . '/uploads/materials/' . $filename;
                }
            }
        }
        
        // Handle exam selection
        $reference_id = null;
        if (in_array($kind, ['exam', 'cq_exam'])) {
            $exam_sel = $_POST['exam_selection'] ?? '';
            if ($exam_sel) {
                list($ex_type, $ex_id) = explode('_', $exam_sel);
                $reference_id = (int)$ex_id;
                $kind = ($ex_type === 'mcq') ? 'exam' : 'cq_exam';
                $url = APP_URL . '/' . ($ex_type === 'mcq' ? 'exam.php?exam=' : 'cq_take.php?id=') . $reference_id;
            }
        }
        
        if ($title) {
            db_insert("INSERT INTO classroom_materials (classroom_id, kind, title, content_text, url, reference_id, sort_order) VALUES (?, ?, ?, ?, ?, ?, 0)", [$classroom_id, $kind, $title, $content_text, $url, $reference_id]);
            flash('success', ucfirst($kind) . ' added successfully!');
        }
    } elseif ($action === 'edit_material') {
        $mat_id = (int)$_POST['material_id'];
        $title = sanitize_string($_POST['title']);
        $content_text = $_POST['content_text'] ?? '';
        $url = $_POST['url'] ?? '';
        
        // Handle file upload if present
        if (isset($_FILES['upload_file']) && $_FILES['upload_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['upload_file'];
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $allowed = ['pdf', 'ppt', 'pptx', 'doc', 'docx', 'jpg', 'png', 'jpeg'];
            if (in_array(strtolower($ext), $allowed)) {
                $dir = UPLOAD_PATH . 'materials/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = bin2hex(random_bytes(12)) . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                    $url = APP_URL . '/uploads/materials/' . $filename;
                }
            }
        }
        
        $mat = db_row("SELECT id FROM classroom_materials WHERE id=? AND classroom_id=?", [$mat_id, $classroom_id]);
        if ($mat && $title) {
            db_query("UPDATE classroom_materials SET title=?, content_text=?, url=? WHERE id=?", [$title, $content_text, $url, $mat_id]);
            flash('success', 'Material updated successfully.');
        }
    }
    elseif ($action === 'delete_material') {
        $mat_id = (int)$_POST['material_id'];
        $mat = db_row("SELECT id FROM classroom_materials WHERE id=? AND classroom_id=?", [$mat_id, $classroom_id]);
        if ($mat) {
            db_query("DELETE FROM classroom_materials WHERE id=?", [$mat_id]);
            flash('success', 'Material deleted.');
        }
    }
    elseif ($action === 'edit_classroom_settings') {
        $subject = sanitize_string($_POST['subject'] ?? '');
        $title = sanitize_string($_POST['title']);
        $desc = sanitize_string($_POST['description']);

        if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg','jpeg','png','webp','gif'];
            $ext = strtolower(pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $dir = __DIR__ . '/../uploads/classrooms/';
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                $filename = uniqid('cr_') . '.' . $ext;
                if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $dir . $filename)) {
                    $thumbPath = 'uploads/classrooms/' . $filename;
                    db_query("UPDATE virtual_classrooms SET thumbnail=? WHERE id=?", [$thumbPath, $classroom_id]);
                }
            }
        }
        
        db_query("UPDATE virtual_classrooms SET subject=?, title=?, description=? WHERE id=?", [$subject ?: null, $title, $desc, $classroom_id]);
        flash('success', 'Classroom settings updated.');
    }
    elseif ($action === 'add_comment') {
        $body = sanitize_string($_POST['body']);
        $parent_id = !empty($_POST['parent_id']) ? sanitize_int($_POST['parent_id']) : null;
        if ($body) {
            db_insert("INSERT INTO classroom_comments (classroom_id, user_id, body, parent_id) VALUES (?, ?, ?, ?)", [$classroom_id, $uid, $body, $parent_id]);
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                $myAvatar = current_user()['avatar'] ? APP_URL.'/'.ltrim(current_user()['avatar'], '/') : 'https://ui-avatars.com/api/?name='.urlencode(current_user()['name']).'&background=5B21B6&color=fff';
                header('Content-Type: application/json');
                echo json_encode([
                    'status' => 'success',
                    'id' => db_insert_id(),
                    'parent_id' => $parent_id,
                    'name' => current_user()['name'],
                    'avatar' => $myAvatar,
                    'time' => date('M j, g:i A'),
                    'body' => nl2br(e($body)),
                    'is_teacher' => true,
                    'is_owner' => true
                ]);
                exit;
            }
            flash('success', 'Reply posted successfully!');
        }
    } elseif ($action === 'edit_comment') {
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
    } elseif ($action === 'delete_comment') {
        $comment_id = sanitize_int($_POST['comment_id']);
        db_query("DELETE FROM classroom_comments WHERE id = ? AND user_id = ?", [$comment_id, $uid]);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success']);
            exit;
        }
        flash('success', 'Comment deleted.');
    } elseif ($action === 'delete_classroom') {
        db_query("DELETE FROM virtual_classrooms WHERE id=?", [$classroom_id]);
        flash('success', 'Classroom deleted.');
        redirect(APP_URL."/teacher/classroom.php?id=$course_id");
    }

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['status' => 'error']);
        exit;
    }
    
    redirect(APP_URL."/teacher/manage_classroom.php?id=$classroom_id");
}

$materials = db_query("SELECT * FROM classroom_materials WHERE classroom_id=? ORDER BY created_at ASC", [$classroom_id]);

// Fetch course teachers
$teachers_query = db_query("
    SELECT u.id 
    FROM users u
    JOIN teacher_profiles tp ON u.id = tp.user_id
    WHERE tp.id = ? OR tp.id IN (SELECT teacher_id FROM course_teachers WHERE course_id = ?)
", [$course['teacher_id'], $course_id]);
$teacher_user_ids = array_column($teachers_query, 'id');

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
    if (!$mat || !$mat['url']) return '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:#fff;opacity:0.6;"><span class="material-icons-round" style="font-size:48px;margin-bottom:12px;">description</span><p>No media attached to this content.</p></div>';
    
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
                    <iframe src="https://www.youtube.com/embed/'.$yid.'?origin='.APP_URL.$listParam.$indexParam.'&amp;iv_load_policy=3&amp;modestbranding=1&amp;playsinline=1&amp;showinfo=0&amp;rel=0&amp;enablejsapi=1" allowfullscreen allowtransparency allow="autoplay" style="pointer-events: none;"></iframe>
                </div>';
            }
            return '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:#fff;opacity:0.6;text-align:center;padding:0 24px;"><span class="material-icons-round" style="font-size:48px;margin-bottom:12px;">error_outline</span><p>Couldn\'t recognize this YouTube link. Edit the material and paste the full video URL (e.g. https://www.youtube.com/watch?v=...).</p></div>';
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
            return '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:#000;"><img src="'.e($url).'" alt="Image" style="max-width:100%;max-height:100%;object-fit:contain;"></div>';
        }
        if (in_array($ext, ['ppt','pptx','doc','docx']) && strpos($url, 'http') === 0) {
            return '<iframe src="https://view.officeapps.live.com/op/embed.aspx?src='.urlencode($url).'" style="width:100%;height:100%;border:none;"></iframe>';
        }
        return '<iframe src="'.e($url).'" style="width:100%;height:100%;border:none;"></iframe>';
    }
    
    return '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:#fff;"><span class="material-icons-round" style="font-size:48px;margin-bottom:12px;opacity:0.6;">open_in_new</span><a href="'.e($url).'" target="_blank" class="btn btn-primary" style="border-radius:24px;">Open Link Externally</a></div>';
}

$_active = 'courses.php';

// Fetch exams for dropdown — only exams created for THIS classroom
$mcq_exams = db_query("SELECT id, title FROM exams WHERE classroom_id=?", [$classroom_id]);
$cq_exams = db_query("SELECT id, title FROM cq_exams WHERE classroom_id=?", [$classroom_id]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Manage Classroom: <?= e($classroom['title']) ?> — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.plyr.io/3.7.8/plyr.css" />
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<style>
body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8f9fe; }
.header-box { background: #fff; padding: var(--sp-6) var(--sp-8); border-radius: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); margin-bottom: var(--sp-8); }
.add-btn-small {
    background: #f8f9fe; border: 1px dashed var(--c-border); border-radius: 12px; padding: 12px; 
    text-align: center; cursor: pointer; transition: all .2s; 
    display: flex; flex-direction: column; align-items: center; gap: 6px;
    font-size: 13px; font-weight: 700; color: #1a1a2e;
}
.add-btn-small:hover { background: rgba(91, 33, 182,0.05); border-color: var(--c-primary); color: var(--c-primary); }
.add-btn-small .material-icons-round { font-size: 24px; color: var(--c-primary); }

.icon-btn-small { background:none; border:none; padding:4px; border-radius:6px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .2s; }
.icon-btn-small:hover { background: rgba(0,0,0,0.05); }
.icon-btn-small .material-icons-round { font-size: 18px; }

/* Custom Scrollbar */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1); border-radius: 10px; }
::-webkit-scrollbar-thumb:hover { background: rgba(0,0,0,0.2); }


/* Modals */
.modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 999; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(8px); opacity: 0; transition: opacity 0.3s ease; }
.modal.show { display: flex; opacity: 1; }
.modal-content { background: #fff; border-radius: 24px; width: 100%; max-width: 500px; padding: 32px; box-shadow: 0 20px 50px rgba(0,0,0,0.2); transform: scale(0.95); transition: transform 0.3s ease; }
.modal.show .modal-content { transform: scale(1); }

/* Drawer for Exam Creator */
.drawer-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 1000; backdrop-filter: blur(4px); opacity: 0; transition: opacity 0.3s ease; }
.drawer { position: fixed; top: 0; right: -100%; width: 100%; max-width: 900px; height: 100vh; background: #fff; z-index: 1001; box-shadow: -10px 0 30px rgba(0,0,0,0.1); transition: right 0.4s cubic-bezier(0.25, 0.8, 0.25, 1); display: flex; flex-direction: column; }
.drawer.show { right: 0; }
.drawer-overlay.show { display: block; opacity: 1; }
.drawer-header { padding: 20px 32px; border-bottom: 1px solid var(--c-border); display: flex; justify-content: space-between; align-items: center; background: #f8f9fe; }
.drawer-body { flex: 1; padding: 0; overflow: hidden; }
.drawer-iframe { width: 100%; height: 100%; border: none; }

.content-wrapper {
    display: grid;
    grid-template-columns: 280px 1fr 340px;
    gap: 24px;
    align-items: start;
}

@media (max-width: 1100px) {
    .content-wrapper { grid-template-columns: 250px 1fr; }
    .sidebar-right { grid-column: span 2; }
}
@media (max-width: 800px) {
    .content-wrapper { grid-template-columns: 1fr; }
    .sidebar-right, .sidebar-left { grid-column: span 1; }
}

/* Left Sidebar */
.sidebar-left { background: transparent; }
.sidebar-left h3 { margin: 0 0 16px; font-size: 16px; font-weight: 800; color: #1a1a2e; }
.chapter-list { display: flex; flex-direction: column; gap: 8px; }
.chapter-item {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 16px; border-radius: 12px;
    text-decoration: none; color: #475569; font-weight: 600; font-size: 14px;
    background: transparent; transition: all 0.2s;
}
.chapter-item:hover { background: #fff; color: #1a1a2e; }
.chapter-item.active { background: var(--c-primary); color: #fff; box-shadow: 0 4px 12px rgba(91, 33, 182,0.3); }

/* Main Content */
.main-content { display: flex; flex-direction: column; gap: 32px; }

/* Add Content Panel */
.add-content-panel { background: #fff; border-radius: 24px; padding: 24px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); }

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

.colorful-card .btn-card {
    margin-top: auto; z-index: 2; background: #fff; color: #1a1a2e; border: none;
    padding: 8px 16px; border-radius: 20px; font-weight: 700; font-size: 13px; text-align: center;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: max-content; text-decoration: none; display: inline-block;
}

/* Block Actions for Teacher */
.block-actions { position: absolute; top: 12px; right: 12px; display: flex; gap: 8px; z-index: 10; }

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
</style>
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . ($isAdmin ? '/../admin/includes/sidebar.php' : '/includes/sidebar.php'); ?>
    <div class="admin-content" style="background:#f8f9fe;padding:32px">
        <?php foreach(get_flash() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:24px;border-radius:12px">
            <span class="material-icons-round"><?= $f['type']==='success'?'check_circle':'error' ?></span>
            <span><?= e($f['message']) ?></span>
        </div>
        <?php endforeach; ?>

        <div class="header-box" style="position:relative;">
            <button type="button" class="btn btn-outline" style="position:absolute;top:24px;right:24px;border-radius:12px;display:flex;align-items:center;gap:6px" onclick="document.getElementById('editClassroomModal').classList.add('show')">
                <span class="material-icons-round" style="font-size:18px">edit</span> Edit Settings
            </button>
            <a href="<?= APP_URL ?>/teacher/classroom.php?id=<?= $course_id ?>" class="btn btn-ghost" style="color:var(--c-ink-light);margin-bottom:16px;font-weight:600;display:inline-flex;align-items:center;gap:4px">
                <span class="material-icons-round" style="font-size:18px">arrow_back</span> Back to Virtual Classrooms
            </a>
            <h1 style="margin:0 0 8px;font-size:32px;font-weight:800;color:#1a1a2e"><?= e($classroom['title']) ?></h1>
            <?php if($classroom['description']): ?><p style="margin:0;color:#666;font-size:15px;max-width:80%"><?= e($classroom['description']) ?></p><?php endif; ?>
        </div>

        <div class="content-wrapper">
            
            <!-- Left Sidebar (Chapters & Add Content) -->
            <aside class="sidebar-left">
                <!-- Add Content Section -->
                <div class="add-content-panel" style="margin-bottom: 24px; padding: 16px;">
                    <h3 style="margin:0 0 12px; font-size:15px; font-weight:800; color:#1a1a2e; text-align:center;">Add Content to Classroom</h3>
                    <div style="display:grid; grid-template-columns:repeat(2, 1fr); gap:8px;">
                        <button class="add-btn-small" style="font-size:12px; padding:8px; display:flex; flex-direction:column; align-items:center; gap:4px; border:1px dashed var(--c-border); background:transparent; border-radius:12px; cursor:pointer;" onclick="openModal('live', 'Live Class')"><span class="material-icons-round" style="color:#ff5263;">live_tv</span> Live</button>
                        <button class="add-btn-small" style="font-size:12px; padding:8px; display:flex; flex-direction:column; align-items:center; gap:4px; border:1px dashed var(--c-border); background:transparent; border-radius:12px; cursor:pointer;" onclick="openModal('video', 'Video')"><span class="material-icons-round" style="color:#651fff;">play_circle</span> Video</button>
                        <button class="add-btn-small" style="font-size:12px; padding:8px; display:flex; flex-direction:column; align-items:center; gap:4px; border:1px dashed var(--c-border); background:transparent; border-radius:12px; cursor:pointer;" onclick="openModal('slide', 'Slide/PDF')"><span class="material-icons-round" style="color:#ff9800;">slideshow</span> Slide</button>
                        <button class="add-btn-small" style="font-size:12px; padding:8px; display:flex; flex-direction:column; align-items:center; gap:4px; border:1px dashed var(--c-border); background:transparent; border-radius:12px; cursor:pointer;" onclick="openModal('exam', 'Exam/CQ')"><span class="material-icons-round" style="color:#10b981;">quiz</span> Exam</button>
                        <button class="add-btn-small" style="font-size:12px; padding:8px; display:flex; flex-direction:column; align-items:center; gap:4px; border:1px dashed var(--c-border); background:transparent; border-radius:12px; cursor:pointer;" onclick="openModal('note', 'Note')"><span class="material-icons-round" style="color:#64748b;">description</span> Note</button>
                        <button class="add-btn-small" style="font-size:12px; padding:8px; display:flex; flex-direction:column; align-items:center; gap:4px; border:1px dashed var(--c-border); background:transparent; border-radius:12px; cursor:pointer;" onclick="openModal('notice', 'Notice')"><span class="material-icons-round" style="color:#f43f5e;">campaign</span> Notice</button>
                    </div>
                </div>

                <h3>Course Chapters</h3>
                <?php
                $ch_res = db_query("SELECT * FROM virtual_classrooms WHERE course_id=? ORDER BY sort_order ASC, id ASC", [$course_id]);
                $ch_groups = group_classrooms_by_subject($ch_res);
                ?>
                <?php foreach ($ch_groups as $ch_subject => $ch_group): ?>
                <?php if (count($ch_groups) > 1): ?>
                <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin:16px 0 8px 4px"><?= e($ch_subject) ?></div>
                <?php endif; ?>
                <div class="chapter-list">
                    <?php foreach ($ch_group as $ch): ?>
                    <a href="?id=<?= $ch['id'] ?>" class="chapter-item <?= $ch['id'] == $classroom_id ? 'active' : '' ?>">
                        <span class="material-icons-round" style="font-size:20px"><?= $ch['id'] == $classroom_id ? 'play_circle' : 'play_circle_outline' ?></span>
                        <?= e($ch['title']) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </aside>
            
            <main class="main-content">

            <!-- 1. Videos & Live Streams -->
            <?php if (!empty($groups['video_live'])): ?>
                <?php foreach ($groups['video_live'] as $mat): 
                    $matJson = htmlspecialchars(json_encode(['id'=>$mat['id'],'kind'=>$mat['kind'],'title'=>$mat['title'],'url'=>$mat['url'],'content_text'=>$mat['content_text'],'reference_id'=>$mat['reference_id']]), ENT_QUOTES, 'UTF-8');
                ?>
                    <div style="margin-bottom: 40px; position: relative;">
                        <div class="block-actions" style="top: 12px; right: 12px; z-index: 10;">
                            <button type="button" class="icon-btn-small" style="color:var(--c-primary); background:rgba(255,255,255,0.9); border:none; border-radius:50%; width:36px; height:36px; display:flex; align-items:center; justify-content:center; cursor:pointer; padding:0; box-shadow:0 2px 8px rgba(0,0,0,0.1);" onclick="openEditModal(<?= $matJson ?>)"><span class="material-icons-round" style="font-size:20px;">edit</span></button>
                            <form method="POST" action="" onsubmit="return confirm('Delete this video?');" style="margin:0">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete_material"><input type="hidden" name="material_id" value="<?= $mat['id'] ?>">
                                <button type="submit" class="icon-btn-small" style="color:var(--c-danger); background:rgba(255,255,255,0.9); border:none; border-radius:50%; width:36px; height:36px; display:flex; align-items:center; justify-content:center; cursor:pointer; padding:0; box-shadow:0 2px 8px rgba(0,0,0,0.1);"><span class="material-icons-round" style="font-size:20px;">delete</span></button>
                            </form>
                        </div>
                        <div class="viewer-container" style="border-radius:24px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.1); background:#000;">
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
                            $matJson = htmlspecialchars(json_encode(['id'=>$mat['id'],'kind'=>$mat['kind'],'title'=>$mat['title'],'url'=>$mat['url'],'content_text'=>$mat['content_text'],'reference_id'=>$mat['reference_id']]), ENT_QUOTES, 'UTF-8');
                        ?>
                            <div class="colorful-card card-exam">
                                <div class="block-actions" style="display:flex; gap:8px; top:12px; right:12px; z-index:10;">
                                    <button type="button" class="icon-btn-small" style="color:var(--c-primary); background:#fff; border:none; border-radius:50%; width:32px; height:32px; display:flex; align-items:center; justify-content:center; cursor:pointer; padding:0;" onclick="openEditModal(<?= $matJson ?>)"><span class="material-icons-round" style="font-size:18px;">edit</span></button>
                                    <form method="POST" action="" onsubmit="return confirm('Delete this exam?');" style="margin:0">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="delete_material"><input type="hidden" name="material_id" value="<?= $mat['id'] ?>">
                                        <button type="submit" class="icon-btn-small" style="color:#ef4444; background:#fff; border:none; border-radius:50%; width:32px; height:32px; display:flex; align-items:center; justify-content:center; cursor:pointer; padding:0;"><span class="material-icons-round" style="font-size:18px;">delete</span></button>
                                    </form>
                                </div>
                                <div class="card-icon"><span class="material-icons-round" style="font-size:20px">quiz</span></div>
                                <div style="font-size:10px; opacity:0.8; margin-bottom:-8px; z-index:2;"><?= e($mat['kind']) ?></div>
                                <h3><?= e($mat['title']) ?></h3>
                                <?php if ($mat['content_text']): ?>
                                    <p><?= e($mat['content_text']) ?></p>
                                <?php endif; ?>
                                <div style="margin-top:auto; display:flex; gap:8px;">
                                    <a href="<?= e($mat['url']) ?>" class="btn-card" style="flex:1; text-align:center;" target="_blank">Preview</a>
                                    <a href="exam_info.php?material_id=<?= $mat['id'] ?>" class="btn-card" style="flex:1; text-align:center; background:#fff; color:var(--c-primary);">Exam Info</a>
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
                        <?php foreach ($groups['documents'] as $mat): 
                            $matJson = htmlspecialchars(json_encode(['id'=>$mat['id'],'kind'=>$mat['kind'],'title'=>$mat['title'],'url'=>$mat['url'],'content_text'=>$mat['content_text'],'reference_id'=>$mat['reference_id']]), ENT_QUOTES, 'UTF-8');
                        ?>
                            <div class="colorful-card card-doc">
                                <div class="block-actions" style="display:flex; gap:8px; top:12px; right:12px; z-index:10;">
                                    <button type="button" class="icon-btn-small" style="color:var(--c-primary); background:#fff; border:none; border-radius:50%; width:32px; height:32px; display:flex; align-items:center; justify-content:center; cursor:pointer; padding:0;" onclick="openEditModal(<?= $matJson ?>)"><span class="material-icons-round" style="font-size:18px;">edit</span></button>
                                    <form method="POST" action="" onsubmit="return confirm('Delete this document?');" style="margin:0">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="delete_material"><input type="hidden" name="material_id" value="<?= $mat['id'] ?>">
                                        <button type="submit" class="icon-btn-small" style="color:#ef4444; background:#fff; border:none; border-radius:50%; width:32px; height:32px; display:flex; align-items:center; justify-content:center; cursor:pointer; padding:0;"><span class="material-icons-round" style="font-size:18px;">delete</span></button>
                                    </form>
                                </div>
                                <div class="card-icon"><span class="material-icons-round" style="font-size:20px">description</span></div>
                                <div style="font-size:10px; opacity:0.8; margin-bottom:-8px; z-index:2;"><?= e($mat['kind']) ?></div>
                                <h3><?= e($mat['title']) ?></h3>
                                <?php if ($mat['content_text']): ?>
                                    <p><?= e($mat['content_text']) ?></p>
                                <?php endif; ?>
                                <a href="<?= e($mat['url']) ?>" class="btn-card" target="_blank" style="margin-top:auto;">Read / Download</a>
                            </div>
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
                        <?php foreach ($groups['notices'] as $mat): 
                            $matJson = htmlspecialchars(json_encode(['id'=>$mat['id'],'kind'=>$mat['kind'],'title'=>$mat['title'],'url'=>$mat['url'],'content_text'=>$mat['content_text'],'reference_id'=>$mat['reference_id']]), ENT_QUOTES, 'UTF-8');
                        ?>
                            <div class="colorful-card card-notice">
                                <div class="block-actions" style="display:flex; gap:8px; top:12px; right:12px; z-index:10;">
                                    <button type="button" class="icon-btn-small" style="color:var(--c-primary); background:#fff; border:none; border-radius:50%; width:32px; height:32px; display:flex; align-items:center; justify-content:center; cursor:pointer; padding:0;" onclick="openEditModal(<?= $matJson ?>)"><span class="material-icons-round" style="font-size:18px;">edit</span></button>
                                    <form method="POST" action="" onsubmit="return confirm('Delete this note?');" style="margin:0">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="delete_material"><input type="hidden" name="material_id" value="<?= $mat['id'] ?>">
                                        <button type="submit" class="icon-btn-small" style="color:#ef4444; background:#fff; border:none; border-radius:50%; width:32px; height:32px; display:flex; align-items:center; justify-content:center; cursor:pointer; padding:0;"><span class="material-icons-round" style="font-size:18px;">delete</span></button>
                                    </form>
                                </div>
                                <div class="card-icon"><span class="material-icons-round" style="font-size:20px">campaign</span></div>
                                <div style="font-size:10px; opacity:0.8; margin-bottom:-8px; z-index:2;"><?= e($mat['kind']) ?></div>
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
                    
            <!-- Right Sidebar (Discussion Section) -->
            <aside class="sidebar-right">
                <h3>Classroom Discussion</h3>
                <form method="POST" action="" class="comment-form ajax-comment-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_comment">
                    <?php $myAvatar = current_user()['avatar'] ? APP_URL.'/'.ltrim(current_user()['avatar'], '/') : 'https://ui-avatars.com/api/?name='.urlencode(current_user()['name']).'&background=5B21B6&color=fff'; ?>
                    <div style="display:flex; gap:12px;">
                        <img src="<?= e($myAvatar) ?>" class="comment-avatar" alt="">
                        <div class="comment-input-wrap" style="flex:1;">
                            <textarea name="body" rows="2" placeholder="Reply to students..." required></textarea>
                        </div>
                    </div>
                    <div style="text-align:right; margin-top:12px;">
                        <button type="submit" class="btn btn-primary" style="border-radius:24px;padding:8px 20px;font-weight:700;">Post Reply</button>
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

        </div> <!-- /content-wrapper -->

    </div>
</div>

<!-- Add/Edit Material Modal -->
<div class="modal" id="addModal">
    <div class="modal-content">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
            <h3 style="margin:0;font-size:24px;font-weight:800;color:#1a1a2e" id="modalTitle">Add Content</h3>
            <button type="button" class="btn btn-ghost" style="padding:8px;border-radius:50%;background:#f0f0f0" onclick="document.getElementById('addModal').classList.remove('show')"><span class="material-icons-round">close</span></button>
        </div>
        <form method="POST" action="" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="modalAction" value="add_material">
            <input type="hidden" name="material_id" id="modalMatId" value="">
            <input type="hidden" name="kind" id="modalKind" value="">
            
            <div class="form-group" style="margin-bottom:20px">
                <label class="form-label" style="font-weight:700">Title <span class="text-danger">*</span></label>
                <input type="text" name="title" id="modalInputTitle" class="form-control" style="border-radius:12px;padding:12px" required placeholder="e.g. Introduction">
            </div>

            <!-- Exam Selection (Only for Exams) -->
            <div class="form-group" style="margin-bottom:20px; display:none;" id="examGroup">
                <label class="form-label" style="font-weight:700">Select Existing Exam</label>
                <select name="exam_selection" class="form-control" style="border-radius:12px;padding:12px">
                    <option value="">-- Choose Exam (Optional) --</option>
                    <optgroup label="MCQ Exams">
                        <?php foreach($mcq_exams as $ex): ?>
                        <option value="mcq_<?= $ex['id'] ?>"><?= e($ex['title']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="CQ Exams">
                        <?php foreach($cq_exams as $ex): ?>
                        <option value="cq_<?= $ex['id'] ?>"><?= e($ex['title']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
                <div style="font-size:13px;color:#666;margin-top:8px">
                    Or <a href="javascript:void(0)" onclick="openExamDrawer('mcq')" style="color:var(--c-primary);font-weight:700">Create MCQ Exam</a> /
                    <a href="javascript:void(0)" onclick="openExamDrawer('cq')" style="color:var(--c-primary);font-weight:700">Create CQ Exam</a>
                    first, then add it here.
                </div>
            </div>

            <div id="editExamQuestionsGroup" style="display:none;margin-bottom:24px;padding:20px;background:#f8f9fe;border-radius:16px;border:1px solid var(--c-border);">
                <div style="display:flex;align-items:flex-start;gap:16px;margin-bottom:16px;">
                    <div style="width:40px;height:40px;border-radius:10px;background:rgba(91, 33, 182,0.1);color:var(--c-primary);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <span class="material-icons-round">quiz</span>
                    </div>
                    <div>
                        <h4 style="margin:0 0 4px;font-size:16px;font-weight:700;color:var(--c-ink);">Linked Exam Material</h4>
                        <p style="margin:0;font-size:14px;color:var(--c-ink-muted);">You can manage this exam's questions and settings directly from here.</p>
                    </div>
                </div>
                <div style="display:flex;gap:12px;">
                    <button type="button" class="btn btn-primary" style="flex:1;border-radius:12px;display:flex;align-items:center;justify-content:center;gap:8px;" id="btnEditQuestionsModal">
                        <span class="material-icons-round" style="font-size:20px;">edit_document</span> Manage Questions
                    </button>
                    <button type="button" class="btn btn-outline" style="flex:1;border-radius:12px;display:flex;align-items:center;justify-content:center;gap:8px;" id="btnEditSettingsModal">
                        <span class="material-icons-round" style="font-size:20px;">tune</span> Exam Settings
                    </button>
                </div>
            </div>
            
            <!-- File Upload (For Slide/PDF) -->
            <div class="form-group" style="margin-bottom:20px; display:none;" id="uploadGroup">
                <label class="form-label" style="font-weight:700">Upload File</label>
                <input type="file" name="upload_file" class="form-control" style="border-radius:12px;padding:10px" accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.png">
                <div style="font-size:13px;color:#666;margin-top:6px">Supported: PDF, Word, PowerPoint, Images. Or provide a link below.</div>
            </div>

            <div class="form-group" style="margin-bottom:20px" id="urlGroup">
                <label class="form-label" style="font-weight:700" id="urlLabel">Link / URL</label>
                <input type="url" name="url" id="modalInputUrl" class="form-control" style="border-radius:12px;padding:12px" placeholder="https://...">
                <div style="font-size:13px;color:#666;margin-top:6px" id="urlHelp"></div>
            </div>
            
            <div class="form-group" style="margin-bottom:32px" id="contentGroup">
                <label class="form-label" style="font-weight:700">Text Content / Instructions</label>
                <textarea name="content_text" id="modalInputContent" class="form-control" style="border-radius:12px;padding:12px" rows="4"></textarea>
            </div>
            
            <div style="display:flex;justify-content:flex-end;gap:16px">
                <button type="button" class="btn btn-outline" style="border-radius:12px;font-weight:700" onclick="document.getElementById('addModal').classList.remove('show')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="border-radius:12px;font-weight:700;padding:10px 24px" id="modalSubmitBtn">Save Content</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Classroom Settings Modal -->
<div class="modal" id="editClassroomModal">
    <div class="modal-content">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
            <h3 style="margin:0;font-size:24px;font-weight:800;color:#1a1a2e">Edit Settings</h3>
            <button type="button" class="btn btn-ghost" style="padding:8px;border-radius:50%;background:#f0f0f0" onclick="document.getElementById('editClassroomModal').classList.remove('show')"><span class="material-icons-round">close</span></button>
        </div>
        <form method="POST" action="" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_classroom_settings">
            <div class="form-group" style="margin-bottom:20px">
                <label class="form-label" style="font-weight:700;color:#333">Subject / Section</label>
                <input type="text" name="subject" list="editSubjectList" class="form-control" style="border-radius:12px;padding:12px" placeholder="e.g. Bangla, English, GK" value="<?= e($classroom['subject'] ?? '') ?>">
                <datalist id="editSubjectList">
                    <?php $editSubjects = db_query("SELECT DISTINCT subject FROM virtual_classrooms WHERE course_id=? AND subject IS NOT NULL AND subject<>''", [$course_id]); ?>
                    <?php foreach ($editSubjects as $s): ?>
                    <option value="<?= e($s['subject']) ?>">
                    <?php endforeach; ?>
                </datalist>
                <small style="color:#666;margin-top:4px;display:block">Groups this classroom under a subject for students. Leave empty to keep it in "General".</small>
            </div>
            <div class="form-group" style="margin-bottom:20px">
                <label class="form-label" style="font-weight:700;color:#333">Classroom Title <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control" style="border-radius:12px;padding:12px" required value="<?= e($classroom['title']) ?>">
            </div>
            <div class="form-group" style="margin-bottom:20px">
                <label class="form-label" style="font-weight:700;color:#333">Thumbnail Image</label>
                <input type="file" name="thumbnail" class="form-control" accept="image/*" style="border-radius:12px;padding:10px">
                <small style="color:#666;margin-top:4px;display:block">Leave empty to keep existing thumbnail. Recommended ratio is 16:9 (e.g. 800x450px)</small>
            </div>
            <div class="form-group" style="margin-bottom:32px">
                <label class="form-label" style="font-weight:700;color:#333">Description</label>
                <textarea name="description" class="form-control" style="border-radius:12px;padding:12px" rows="3"><?= e($classroom['description']) ?></textarea>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;gap:16px">
                <button type="button" class="btn btn-ghost" style="border-radius:12px;font-weight:700;color:var(--c-danger)" onclick="var el=document.getElementById('deleteClassroomForm'); el.style.display = el.style.display==='none' ? 'block' : 'none';">
                    <span class="material-icons-round" style="font-size:18px;vertical-align:-4px">delete</span> Delete Classroom
                </button>
                <div style="display:flex;gap:16px">
                    <button type="button" class="btn btn-outline" style="border-radius:12px;font-weight:700" onclick="document.getElementById('editClassroomModal').classList.remove('show')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="border-radius:12px;font-weight:700;padding:10px 24px">Update Settings</button>
                </div>
            </div>
        </form>
        <form method="POST" action="" id="deleteClassroomForm" style="display:none;margin-top:16px;padding:16px;background:#fef2f2;border:1px solid #fecaca;border-radius:12px" onsubmit="return confirm('Delete this classroom permanently? All its materials and comments will be removed too. This cannot be undone.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_classroom">
            <p style="margin:0 0 12px;font-size:13px;color:#991b1b;font-weight:600">This permanently deletes the classroom, its materials, and its comments.</p>
            <button type="submit" class="btn" style="border-radius:12px;font-weight:700;background:var(--c-danger);color:#fff;padding:10px 20px">Confirm Delete</button>
        </form>
    </div>
</div>

<!-- Exam Drawer -->
<div class="drawer-overlay" id="examDrawerOverlay" onclick="closeExamDrawer()"></div>
<div class="drawer" id="examDrawer">
    <div class="drawer-header">
        <h3 style="margin:0;font-size:20px;font-weight:700;color:#1a1a2e">Create Exam</h3>
        <button type="button" class="btn btn-ghost" style="padding:8px;border-radius:50%;background:#f0f0f0" onclick="closeExamDrawer()"><span class="material-icons-round">close</span></button>
    </div>
    <div class="drawer-body">
        <iframe src="" id="examDrawerIframe" class="drawer-iframe"></iframe>
    </div>
</div>

<script>
function setupModalFields(kind) {
    let urlLabel = 'Link / URL';
    let urlHelp = '';
    let showUrl = true;
    let showContent = true;
    let showUpload = false;
    let showExam = false;
    
    if (kind === 'live') {
        urlLabel = 'Meeting Link (Zoom, Meet, etc.)';
        urlHelp = 'Provide the link students will click to join the live class.';
    } else if (kind === 'video') {
        urlLabel = 'Video URL (YouTube/Vimeo)';
        urlHelp = 'Example: https://youtube.com/watch?v=...';
    } else if (kind === 'slide' || kind === 'pdf') {
        showUpload = true;
        urlLabel = 'Document/Form Link (if not uploading)';
        urlHelp = 'Link to the PDF or Google Drive file.';
    } else if (kind === 'exam' || kind === 'cq_exam') {
        showExam = true;
        urlLabel = 'External Exam Link (Optional)';
        urlHelp = 'If using Google Forms, paste the link here. Otherwise select from above.';
    } else if (kind === 'notice' || kind === 'note') {
        showUrl = false;
    }
    
    document.getElementById('urlGroup').style.display = showUrl ? 'block' : 'none';
    document.getElementById('uploadGroup').style.display = showUpload ? 'block' : 'none';
    document.getElementById('examGroup').style.display = showExam ? 'block' : 'none';
    document.getElementById('contentGroup').style.display = showContent ? 'block' : 'none';
    document.getElementById('urlLabel').innerText = urlLabel;
    document.getElementById('urlHelp').innerText = urlHelp;
}

function openModal(kind, label) {
    document.getElementById('modalTitle').innerText = 'Add ' + label;
    document.getElementById('modalKind').value = kind;
    document.getElementById('modalAction').value = 'add_material';
    document.getElementById('modalMatId').value = '';
    document.getElementById('modalInputTitle').value = '';
    document.getElementById('modalInputUrl').value = '';
    document.getElementById('modalInputContent').value = '';
    document.getElementById('modalSubmitBtn').innerText = 'Save Content';
    document.getElementById('editExamQuestionsGroup').style.display = 'none';
    
    setupModalFields(kind);
    document.getElementById('addModal').classList.add('show');
}

function openEditModal(mat) {
    document.getElementById('modalTitle').innerText = 'Edit Content';
    document.getElementById('modalKind').value = mat.kind;
    document.getElementById('modalAction').value = 'edit_material';
    document.getElementById('modalMatId').value = mat.id;
    document.getElementById('modalInputTitle').value = mat.title;
    document.getElementById('modalInputUrl').value = mat.url;
    document.getElementById('modalInputContent').value = mat.content_text;
    document.getElementById('modalSubmitBtn').innerText = 'Update Content';
    
    setupModalFields(mat.kind);
    
    // Hide exam selection when editing an existing exam material
    if (mat.kind === 'exam' || mat.kind === 'cq_exam') {
        document.getElementById('examGroup').style.display = 'none';
        if (mat.reference_id) {
            document.getElementById('editExamQuestionsGroup').style.display = 'block';
            document.getElementById('btnEditQuestionsModal').onclick = function() {
                openExamDrawerEdit(mat.kind, mat.reference_id);
            };
            document.getElementById('btnEditSettingsModal').onclick = function() {
                openExamDrawerSettings(mat.kind, mat.reference_id);
            };
        } else {
            document.getElementById('editExamQuestionsGroup').style.display = 'none';
        }
    } else {
        document.getElementById('editExamQuestionsGroup').style.display = 'none';
    }
    
    document.getElementById('addModal').classList.add('show');
}

// Drawer logic
function openExamDrawer(type) {
    let url = '';
    if (type === 'mcq') {
        url = '<?= APP_URL ?>/teacher/exams.php?course=<?= $course_id ?>&classroom=<?= $classroom_id ?>&action=add&layout=modal';
    } else {
        url = '<?= APP_URL ?>/teacher/cq_builder.php?course=<?= $course_id ?>&classroom=<?= $classroom_id ?>&layout=modal';
    }
    document.getElementById('examDrawerIframe').src = url;
    document.getElementById('examDrawerOverlay').classList.add('show');
    document.getElementById('examDrawer').classList.add('show');
    // Hide the add material modal temporarily
    document.getElementById('addModal').classList.remove('show');
}

function openExamDrawerEdit(kind, refId) {
    let url = '';
    if (kind === 'exam') {
        url = '<?= APP_URL ?>/teacher/exams.php?action=questions&id=' + refId + '&layout=modal';
    } else if (kind === 'cq_exam') {
        url = '<?= APP_URL ?>/teacher/cq_builder.php?id=' + refId + '&layout=modal';
    }
    document.getElementById('examDrawerIframe').src = url;
    document.getElementById('examDrawerOverlay').classList.add('show');
    document.getElementById('examDrawer').classList.add('show');
    // Hide the edit material modal temporarily
    document.getElementById('addModal').classList.remove('show');
}

function openExamDrawerSettings(kind, refId) {
    let url = '';
    if (kind === 'exam') {
        url = '<?= APP_URL ?>/teacher/exams.php?action=edit&id=' + refId + '&layout=modal';
    } else if (kind === 'cq_exam') {
        url = '<?= APP_URL ?>/teacher/cq_builder.php?action=edit&id=' + refId + '&layout=modal';
    }
    document.getElementById('examDrawerIframe').src = url;
    document.getElementById('examDrawerOverlay').classList.add('show');
    document.getElementById('examDrawer').classList.add('show');
    // Hide the edit material modal temporarily
    document.getElementById('addModal').classList.remove('show');
}

function closeExamDrawer() {
    document.getElementById('examDrawerOverlay').classList.remove('show');
    document.getElementById('examDrawer').classList.remove('show');
    document.getElementById('examDrawerIframe').src = '';
    
    // Reload the page to show newly created exams in the dropdown
    window.location.reload();
}
</script>
<script src="https://cdn.plyr.io/3.7.8/plyr.polyfilled.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
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