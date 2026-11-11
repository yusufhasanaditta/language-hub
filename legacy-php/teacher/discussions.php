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

$threadId    = sanitize_int($_GET['id'] ?? 0);
$courseFilter= sanitize_int($_GET['course'] ?? 0);
$filter      = sanitize_string($_GET['filter'] ?? 'unanswered', 20);
$page        = max(1, sanitize_int($_GET['page'] ?? 1));
$perPage     = 15;

// POST: post reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $postAction = sanitize_string($_POST['action'] ?? '', 20);

    if ($postAction === 'reply') {
        $parentId = sanitize_int($_POST['parent_id'] ?? 0);
        $body     = sanitize_string($_POST['body'] ?? '', 2000);

        if (!$body || !$parentId) { flash('error','Please write a reply.'); redirect(APP_URL.'/teacher/discussions.php?id='.$parentId); }

        $parent = db_row("SELECT d.*,c.teacher_id FROM discussions d JOIN courses c ON c.id=d.course_id WHERE d.id=?", [$parentId]);
        if (!$parent || !teacher_owns_course((int)$parent['course_id'], $tpId)) { flash('error','You do not have permission.'); redirect(APP_URL.'/teacher/discussions.php'); }

        db_insert("INSERT INTO discussions (course_id,lesson_id,user_id,parent_id,body,is_answer,is_deleted,created_at) VALUES (?,?,?,?,?,1,0,NOW())",
            [$parent['course_id'],$parent['lesson_id'],$uid,$parentId,$body]);
        flash('success','Reply posted.');
        redirect(APP_URL.'/teacher/discussions.php?id='.$parentId);

    } elseif ($postAction === 'delete') {
        $dId = sanitize_int($_POST['discussion_id'] ?? 0);
        $owns = db_val("SELECT 1 FROM discussions d JOIN courses c ON c.id=d.course_id WHERE d.id=? AND (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))", [$dId, $tpId, $tpId]);
        if ($owns) { db_exec("UPDATE discussions SET is_deleted=1 WHERE id=?", [$dId]); flash('success','Deleted.'); }
        redirect(APP_URL.'/teacher/discussions.php'.($threadId?'?id='.$threadId:''));
    }
}

// Thread view
$thread = null;
$replies = [];
if ($threadId) {
    $thread = db_row("
        SELECT d.*, u.name as student_name, u.avatar as student_avatar,
               c.title_bn as course_title, l.title as lesson_title
        FROM discussions d
        JOIN users u ON u.id=d.user_id
        JOIN courses c ON c.id=d.course_id
        LEFT JOIN lessons l ON l.id=d.lesson_id
        WHERE d.id=? AND (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)) AND d.parent_id IS NULL
    ", [$threadId, $tpId, $tpId]);
    if ($thread) {
        $replies = db_query("
            SELECT d.*, u.name as replier_name, u.avatar as replier_avatar, u.role
            FROM discussions d
            JOIN users u ON u.id=d.user_id
            WHERE d.parent_id=? AND d.is_deleted=0
            ORDER BY d.created_at ASC
        ", [$threadId]);
    }
}

// List
$myCourses = db_query("SELECT id,title_bn FROM courses WHERE teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=courses.id AND ct.teacher_id=?) ORDER BY title_bn", [$tpId, $tpId]);
$where  = ['(c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))','d.parent_id IS NULL','d.is_deleted=0'];
$params = [$tpId, $tpId];
if ($courseFilter) { $where[] = 'c.id=?'; $params[] = $courseFilter; }
if ($filter === 'unanswered') { $where[] = 'NOT EXISTS(SELECT 1 FROM discussions r WHERE r.parent_id=d.id AND r.is_deleted=0)'; }
$whereSQL = 'WHERE '.implode(' AND ',$where);
$total    = (int)db_val("SELECT COUNT(*) FROM discussions d JOIN courses c ON c.id=d.course_id $whereSQL", $params);
$offset   = ($page-1)*$perPage;
$threads  = db_query("
    SELECT d.*, u.name as student_name, u.avatar as student_avatar,
           c.title_bn as course_title, l.title as lesson_title,
           (SELECT COUNT(*) FROM discussions r WHERE r.parent_id=d.id AND r.is_deleted=0) as reply_count
    FROM discussions d
    JOIN users u ON u.id=d.user_id
    JOIN courses c ON c.id=d.course_id
    LEFT JOIN lessons l ON l.id=d.lesson_id
    $whereSQL
    ORDER BY d.created_at DESC
    LIMIT $perPage OFFSET $offset
", $params);

function time_ago(string $dt): string { $d=time()-strtotime($dt); if($d<60)return 'just now'; if($d<3600)return intdiv($d,60).'m ago'; if($d<86400)return intdiv($d,3600).'h ago'; if($d<604800)return intdiv($d,86400).'d ago'; return date('d M Y',strtotime($dt)); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Q&amp;A — <?= APP_NAME ?></title>
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

        <?php if ($thread): ?>
        <!-- ── Thread View ── -->
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:var(--sp-6)">
            <a href="<?= APP_URL ?>/teacher/discussions.php" class="btn btn-ghost btn-sm"><span class="material-icons-round">arrow_back</span></a>
            <h1 style="font-size:var(--text-xl)">Q&amp;A</h1>
        </div>
        <div style="max-width:760px">
            <!-- Original question -->
            <div style="background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--r-lg);padding:var(--sp-5);margin-bottom:var(--sp-4)">
                <div style="display:flex;gap:var(--sp-3);margin-bottom:var(--sp-3)">
                    <img src="<?= e(avatar_url($thread['student_avatar'],$thread['student_name'])) ?>"
                         alt="" style="width:40px;height:40px;border-radius:50%;flex-shrink:0">
                    <div>
                        <div style="font-weight:700;font-size:var(--text-sm)"><?= e($thread['student_name']) ?></div>
                        <div style="font-size:var(--text-xs);color:var(--c-ink-muted)">
                            <?= e($thread['course_title']) ?>
                            <?php if ($thread['lesson_title']): ?> · <?= e($thread['lesson_title']) ?><?php endif; ?>
                            · <?= time_ago($thread['created_at']) ?>
                        </div>
                    </div>
                </div>
                <div style="font-size:var(--text-base);color:var(--c-ink);line-height:1.7"><?= nl2br(e($thread['body'])) ?></div>
            </div>

            <!-- Replies -->
            <?php foreach ($replies as $r): ?>
            <div style="margin-left:40px;background:<?= $r['role']>=ROLE_TEACHER ? 'var(--c-primary-light)' : 'var(--c-surface)' ?>;border:1px solid <?= $r['role']>=ROLE_TEACHER ? 'var(--c-primary)' : 'var(--c-border)' ?>;border-radius:var(--r-lg);padding:var(--sp-4);margin-bottom:var(--sp-3)">
                <div style="display:flex;gap:var(--sp-3);margin-bottom:var(--sp-3)">
                    <img src="<?= e(avatar_url($r['replier_avatar'],$r['replier_name'])) ?>"
                         alt="" style="width:34px;height:34px;border-radius:50%;flex-shrink:0">
                    <div style="flex:1">
                        <div style="display:flex;align-items:center;gap:6px">
                            <span style="font-weight:700;font-size:var(--text-sm)"><?= e($r['replier_name']) ?></span>
                            <?php if ($r['role'] >= ROLE_TEACHER): ?>
                            <span style="background:var(--c-primary);color:#fff;font-size:10px;font-weight:800;padding:1px 7px;border-radius:999px">Teacher</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= time_ago($r['created_at']) ?></div>
                    </div>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="discussion_id" value="<?= (int)$r['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--c-error)">
                            <span class="material-icons-round" style="font-size:14px">delete</span>
                        </button>
                    </form>
                </div>
                <div style="font-size:var(--text-sm);color:var(--c-ink);line-height:1.7"><?= nl2br(e($r['body'])) ?></div>
            </div>
            <?php endforeach; ?>

            <!-- Reply box -->
            <div style="background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--r-lg);padding:var(--sp-5);margin-top:var(--sp-4)">
                <h4 style="margin-bottom:var(--sp-3)">Reply</h4>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="parent_id" value="<?= (int)$thread['id'] ?>">
                    <div class="form-group">
                        <textarea name="body" class="form-control" rows="4" required
                                  placeholder="Write your reply..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <span class="material-icons-round">send</span> Post Reply
                    </button>
                </form>
            </div>
        </div>

        <?php else: ?>
        <!-- ── Thread List ── -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
            <div>
                <h1 style="font-size:var(--text-2xl)">Q&amp;A</h1>
                <p style="color:var(--c-ink-muted);margin:4px 0 0">Total <?= number_format($total) ?> questions</p>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" style="display:flex;gap:var(--sp-3);margin-bottom:var(--sp-5);flex-wrap:wrap">
            <select name="filter" class="form-control" style="max-width:180px">
                <option value="unanswered" <?= $filter==='unanswered'?'selected':'' ?>>Not answered</option>
                <option value="all" <?= $filter==='all'?'selected':'' ?>>All questions</option>
            </select>
            <select name="course" class="form-control" style="max-width:260px">
                <option value="">All courses</option>
                <?php foreach ($myCourses as $mc): ?>
                <option value="<?= (int)$mc['id'] ?>" <?= $courseFilter===$mc['id']?'selected':'' ?>><?= e($mc['title_bn']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm"><span class="material-icons-round">filter_list</span> Filter</button>
            <?php if ($courseFilter || $filter !== 'unanswered'): ?>
            <a href="<?= APP_URL ?>/teacher/discussions.php" class="btn btn-ghost btn-sm">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (empty($threads)): ?>
        <div style="text-align:center;padding:var(--sp-16);background:var(--c-surface);border-radius:var(--r-lg);border:2px dashed var(--c-border)">
            <span class="material-icons-round" style="font-size:56px;color:var(--c-ink-faint)">forum</span>
            <h3 style="margin:12px 0 8px;color:var(--c-ink-light)">No questions</h3>
        </div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:var(--sp-3)">
            <?php foreach ($threads as $d): ?>
            <a href="<?= APP_URL ?>/teacher/discussions.php?id=<?= (int)$d['id'] ?>"
               style="display:block;background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--r-lg);padding:var(--sp-4);text-decoration:none;transition:all .2s"
               onmouseover="this.style.borderColor='var(--c-primary)'" onmouseout="this.style.borderColor='var(--c-border)'">
                <div style="display:flex;gap:var(--sp-3);align-items:flex-start">
                    <img src="<?= e(avatar_url($d['student_avatar'],$d['student_name'])) ?>"
                         alt="" style="width:38px;height:38px;border-radius:50%;flex-shrink:0">
                    <div style="flex:1;min-width:0">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap">
                            <span style="font-weight:700;font-size:var(--text-sm);color:var(--c-ink)"><?= e($d['student_name']) ?></span>
                            <span style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= e($d['course_title']) ?></span>
                            <?php if ($d['lesson_title']): ?>
                            <span style="font-size:var(--text-xs);color:var(--c-ink-muted)">· <?= e($d['lesson_title']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:var(--text-sm);color:var(--c-ink-light);line-height:1.6;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">
                            <?= e($d['body']) ?>
                        </div>
                        <div style="margin-top:8px;display:flex;align-items:center;gap:12px">
                            <span style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= time_ago($d['created_at']) ?></span>
                            <?php if ((int)$d['reply_count'] === 0): ?>
                            <span style="background:var(--c-accent);color:#fff;font-size:10px;font-weight:800;padding:2px 8px;border-radius:999px">Not answered</span>
                            <?php else: ?>
                            <span style="font-size:var(--text-xs);color:var(--c-ink-muted)">
                                <span class="material-icons-round" style="font-size:13px;vertical-align:middle">forum</span>
                                <?= (int)$d['reply_count'] ?> replies
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="material-icons-round" style="color:var(--c-primary);align-self:center;flex-shrink:0">chevron_right</span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?= paginate($total,$page,$perPage,APP_URL.'/teacher/discussions.php?'.http_build_query(array_filter(['filter'=>$filter,'course'=>$courseFilter?:'','page'=>'{page}']))) ?>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
