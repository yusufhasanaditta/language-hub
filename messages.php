<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_login();

$page_title = 'Messages';
$user = current_user();
$uid  = (int)$user['id'];
$isTeacher = is_teacher() || is_admin();

// Active course context
$courseId = sanitize_int($_GET['course'] ?? 0);
$withUser = sanitize_int($_GET['with'] ?? 0);

// Send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $toCourseId  = sanitize_int($_POST['course_id'] ?? 0);
    $toReceiver  = sanitize_int($_POST['receiver_id'] ?? 0);
    $body        = sanitize_string($_POST['body'] ?? '', 2000);

    if ($body && $toCourseId && $toReceiver) {
        // Validate: student must be enrolled + enrollment active
        if ($isTeacher) {
            // Teacher can reply to enrolled students
            $allowed = db_val("SELECT 1 FROM enrollments WHERE user_id=? AND course_id=? AND status='active'", [$toReceiver, $toCourseId]);
        } else {
            // Student can only message if enrolled and enrollment active and course is continuing
            $allowed = db_val("SELECT 1 FROM enrollments e JOIN courses c ON c.id=e.course_id WHERE e.user_id=? AND e.course_id=? AND e.status='active' AND (c.batch_end IS NULL OR c.batch_end >= CURDATE())", [$uid, $toCourseId]);
        }
        if ($allowed) {
            db_insert("INSERT INTO messages (course_id,sender_id,receiver_id,body,created_at) VALUES (?,?,?,?,NOW())",
                [$toCourseId, $uid, $toReceiver, $body]);
            flash('success', 'Message sent.');
        } else {
            flash('error', 'You do not have permission to message in this course or the course has ended.');
        }
    }
    redirect(APP_URL . '/messages.php?course=' . $toCourseId . '&with=' . $toReceiver);
}

// Mark messages read
if ($courseId && $withUser) {
    db_exec("UPDATE messages SET read_at=NOW() WHERE course_id=? AND receiver_id=? AND sender_id=? AND read_at IS NULL",
        [$courseId, $uid, $withUser]);
}

// Build conversation list
if ($isTeacher) {
    // Teacher: get all students who have messaged in courses teacher owns
    $tp = db_row("SELECT id FROM teacher_profiles WHERE user_id=?", [$uid]);
    $tpId = $tp ? (int)$tp['id'] : 0;

    $convs = db_query("
        SELECT DISTINCT
            m.course_id,
            IF(m.sender_id=?, m.receiver_id, m.sender_id) AS other_user_id,
            c.title_bn AS course_title,
            (SELECT body FROM messages WHERE course_id=m.course_id
             AND (sender_id=? OR receiver_id=?)
             AND (sender_id=IF(m.sender_id=?, m.receiver_id, m.sender_id) OR receiver_id=IF(m.sender_id=?, m.receiver_id, m.sender_id))
             ORDER BY created_at DESC LIMIT 1) AS last_msg,
            (SELECT COUNT(*) FROM messages WHERE course_id=m.course_id AND receiver_id=? AND sender_id=IF(m.sender_id=?, m.receiver_id, m.sender_id) AND read_at IS NULL) AS unread_count,
            (SELECT created_at FROM messages WHERE course_id=m.course_id ORDER BY created_at DESC LIMIT 1) AS last_at
        FROM messages m
        JOIN courses c ON c.id=m.course_id
        WHERE c.teacher_id=? AND (m.sender_id=? OR m.receiver_id=?)
        ORDER BY last_at DESC
    ", [$uid,$uid,$uid,$uid,$uid,$uid,$uid,$tpId,$uid,$uid]);
} else {
    // Student: courses enrolled where teacher can be messaged (including all course teachers)
    $convs = db_query("
        SELECT DISTINCT
            e.course_id,
            tp.user_id AS other_user_id,
            c.title_bn AS course_title,
            (SELECT body FROM messages WHERE course_id=e.course_id
             AND ((sender_id=? AND receiver_id=tp.user_id) OR (sender_id=tp.user_id AND receiver_id=?))
             ORDER BY created_at DESC LIMIT 1) AS last_msg,
            (SELECT COUNT(*) FROM messages WHERE course_id=e.course_id AND receiver_id=? AND sender_id=tp.user_id AND read_at IS NULL) AS unread_count,
            (SELECT created_at FROM messages WHERE course_id=e.course_id ORDER BY created_at DESC LIMIT 1) AS last_at
        FROM enrollments e
        JOIN courses c ON c.id=e.course_id
        JOIN teacher_profiles tp ON (tp.id=c.teacher_id OR tp.id IN (SELECT teacher_id FROM course_teachers WHERE course_id=c.id))
        WHERE e.user_id=? AND e.status='active'
        ORDER BY last_at DESC
    ", [$uid,$uid,$uid,$uid]);
}

// Active conversation messages
$activeMessages = [];
$otherUserInfo  = null;
$activeCourse   = null;
if ($courseId && $withUser) {
    $activeMessages = db_query("
        SELECT m.*, u.name AS sender_name, u.avatar AS sender_avatar
        FROM messages m
        JOIN users u ON u.id=m.sender_id
        WHERE m.course_id=? AND ((m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?))
        ORDER BY m.created_at ASC
        LIMIT 100
    ", [$courseId, $uid, $withUser, $withUser, $uid]);
    $otherUserInfo = db_row("SELECT id,name,avatar,role_id FROM users WHERE id=?", [$withUser]);
    $activeCourse  = db_row("SELECT title_bn FROM courses WHERE id=?", [$courseId]);
}

// For student: get teacher_id for new conversation
$receiverId = 0;
if (!$isTeacher && $courseId) {
    $receiverId = (int)db_val("SELECT tp.user_id FROM courses c JOIN teacher_profiles tp ON tp.id=c.teacher_id WHERE c.id=?", [$courseId]);
}
if ($isTeacher && $courseId && $withUser) {
    $receiverId = $withUser;
}

include __DIR__ . '/includes/header.php';
?>

<section class="section-sm" style="background:var(--c-surface);padding:var(--sp-6) 0">
    <div class="container">
        <h1 style="font-size:var(--text-2xl);font-family:var(--font-display)">Messages</h1>
        <p style="color:var(--c-ink-muted);margin-top:var(--sp-1)">Ask your teacher questions about the course</p>
    </div>
</section>

<section class="section-sm">
    <div class="container">
        <div style="display:grid;grid-template-columns:320px 1fr;gap:var(--sp-5);min-height:70vh" class="msg-layout <?= ($courseId && $otherUserInfo && $activeCourse) ? 'has-active' : '' ?>">

            <!-- Conversation list -->
            <div class="card msg-list" style="padding:0;overflow:hidden;display:flex;flex-direction:column">
                <div style="padding:var(--sp-4);border-bottom:1px solid var(--c-border);font-weight:700;font-family:var(--font-display)">
                    Conversations (<?= count($convs) ?>)
                </div>
                <?php if (empty($convs)): ?>
                <div style="padding:var(--sp-8);text-align:center;color:var(--c-ink-muted)">
                    <span class="material-icons-round" style="font-size:40px;display:block;margin-bottom:8px">chat_bubble_outline</span>
                    <?= $isTeacher ? 'No messages yet.' : 'Enroll in a course to message your teacher.' ?>
                </div>
                <?php else: foreach ($convs as $cv):
                    $otherUser = db_row("SELECT name,avatar FROM users WHERE id=?", [$cv['other_user_id']]);
                    $isActive  = ($courseId == $cv['course_id'] && $withUser == $cv['other_user_id']);
                ?>
                <a href="<?= APP_URL ?>/messages.php?course=<?= (int)$cv['course_id'] ?>&with=<?= (int)$cv['other_user_id'] ?>"
                   style="display:flex;align-items:center;gap:var(--sp-3);padding:var(--sp-4);border-bottom:1px solid var(--c-border);text-decoration:none;background:<?= $isActive ? 'var(--c-primary-light)' : 'transparent' ?>;transition:background .15s">
                    <img src="<?= e(avatar_url($otherUser['avatar'] ?? null, $otherUser['name'] ?? '?')) ?>"
                         style="width:44px;height:44px;border-radius:50%;object-fit:cover;flex-shrink:0" alt="">
                    <div style="flex:1;overflow:hidden">
                        <div style="display:flex;justify-content:space-between;align-items:center">
                            <span style="font-weight:700;font-size:var(--text-sm);color:var(--c-ink)"><?= e($otherUser['name'] ?? '') ?></span>
                            <?php if ((int)$cv['unread_count'] > 0): ?>
                            <span style="background:var(--c-primary);color:#fff;border-radius:999px;padding:1px 7px;font-size:10px;font-weight:800"><?= (int)$cv['unread_count'] ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:11px;color:var(--c-primary);margin:1px 0"><?= e(mb_substr($cv['course_title']??'',0,30,'UTF-8')) ?></div>
                        <div style="font-size:var(--text-xs);color:var(--c-ink-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                            <?= e(mb_substr($cv['last_msg'] ?? '—', 0, 50, 'UTF-8')) ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; endif; ?>
            </div>

            <!-- Chat window -->
            <div class="card msg-chat" style="padding:0;display:flex;flex-direction:column;overflow:hidden">
                <?php if ($courseId && $otherUserInfo && $activeCourse): ?>
                <!-- Header -->
                <div style="padding:var(--sp-4);border-bottom:1px solid var(--c-border);display:flex;align-items:center;gap:var(--sp-3)">
                    <a href="<?= APP_URL ?>/messages.php" class="icon-btn msg-back-btn" style="display:none;flex-shrink:0" aria-label="Back to conversations">
                        <span class="material-icons-round">arrow_back</span>
                    </a>
                    <img src="<?= e(avatar_url($otherUserInfo['avatar'], $otherUserInfo['name'])) ?>"
                         style="width:40px;height:40px;border-radius:50%;object-fit:cover" alt="">
                    <div>
                        <div style="font-weight:700"><?= e($otherUserInfo['name']) ?></div>
                        <div style="font-size:11px;color:var(--c-ink-muted)"><?= e($activeCourse['title_bn']) ?></div>
                    </div>
                </div>
                <!-- Messages -->
                <div id="chatScroll" style="flex:1;overflow-y:auto;padding:var(--sp-5);display:flex;flex-direction:column;gap:var(--sp-3);max-height:55vh">
                    <?php foreach ($activeMessages as $msg): $isMine = ($msg['sender_id'] == $uid); ?>
                    <div style="display:flex;justify-content:<?= $isMine ? 'flex-end' : 'flex-start' ?>;gap:var(--sp-2);align-items:flex-end">
                        <?php if (!$isMine): ?>
                        <img src="<?= e(avatar_url($msg['sender_avatar'], $msg['sender_name'])) ?>"
                             style="width:28px;height:28px;border-radius:50%;object-fit:cover;flex-shrink:0" alt="">
                        <?php endif; ?>
                        <div>
                            <div style="max-width:420px;padding:var(--sp-3) var(--sp-4);border-radius:<?= $isMine ? '18px 18px 4px 18px' : '18px 18px 18px 4px' ?>;
                                background:<?= $isMine ? 'var(--c-primary)' : 'var(--c-surface)' ?>;
                                color:<?= $isMine ? '#fff' : 'var(--c-ink)' ?>;
                                font-size:var(--text-sm);line-height:1.6;word-break:break-word">
                                <?= nl2br(e($msg['body'])) ?>
                            </div>
                            <div style="font-size:10px;color:var(--c-ink-faint);margin-top:3px;text-align:<?= $isMine ? 'right' : 'left' ?>">
                                <?= time_ago($msg['created_at']) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($activeMessages)): ?>
                    <div style="text-align:center;color:var(--c-ink-muted);padding:var(--sp-8)">
                        Start the conversation. Ask a question!
                    </div>
                    <?php endif; ?>
                </div>
                <!-- Send form -->
                <form method="POST" style="padding:var(--sp-4);border-top:1px solid var(--c-border);display:flex;gap:var(--sp-3)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="course_id" value="<?= (int)$courseId ?>">
                    <input type="hidden" name="receiver_id" value="<?= (int)($withUser ?: $receiverId) ?>">
                    <textarea name="body" class="form-control" placeholder="Type a message..." rows="2"
                              style="flex:1;resize:none" required maxlength="2000"
                              onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();this.form.submit()}"></textarea>
                    <button type="submit" class="btn btn-primary" style="align-self:flex-end">
                        <span class="material-icons-round">send</span>
                    </button>
                </form>
                <?php else: ?>
                <div style="flex:1;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:var(--sp-4);color:var(--c-ink-muted)">
                    <span class="material-icons-round" style="font-size:64px;color:var(--c-ink-faint)">forum</span>
                    <div style="text-align:center">
                        <h4>Select a conversation</h4>
                        <p style="font-size:var(--text-sm)">Choose a conversation from the left, or message a teacher from a course page.</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<style>
@media(max-width:768px){
    .msg-layout{grid-template-columns:1fr !important;min-height:0 !important}
    .msg-layout .msg-chat{min-height:calc(100vh - var(--nav-h, 64px) - var(--bottom-nav-h, 56px) - 140px)}
    .msg-layout:not(.has-active) .msg-chat{display:none !important}
    .msg-layout.has-active .msg-list{display:none !important}
    .msg-back-btn{display:inline-flex !important}
}
</style>
<script>
const s = document.getElementById('chatScroll');
if (s) s.scrollTop = s.scrollHeight;
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
