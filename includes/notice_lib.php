<?php
defined('B_ACADEMY') or die('Direct access forbidden.');

/**
 * Notice board — shared queries + renderers.
 * Used by notices.php (feed), api/notice.php (AJAX), admin/notices.php and
 * teacher/notices.php (composer).
 */

// time_ago() lives in includes/header.php, which AJAX endpoints don't load.
if (!function_exists('time_ago')) {
    function time_ago(string $datetime): string {
        $diff = time() - strtotime($datetime);
        if ($diff < 60)     return 'just now';
        if ($diff < 3600)   return intdiv($diff, 60) . ' min ago';
        if ($diff < 86400)  return intdiv($diff, 3600) . ' hr ago';
        if ($diff < 604800) return intdiv($diff, 86400) . ' days ago';
        return date('d M Y', strtotime($datetime));
    }
}

const NOTICE_REACTIONS = [
    'like'  => '👍',
    'love'  => '❤️',
    'care'  => '🤗',
    'wow'   => '😮',
    'sad'   => '😢',
    'angry' => '😡',
];

const NOTICE_REACTION_LABELS = [
    'like' => 'Like', 'love' => 'Love', 'care' => 'Care',
    'wow'  => 'Wow',  'sad'  => 'Sad',  'angry' => 'Angry',
];

/** Admins and teachers may post notices. */
function can_post_notice(): bool {
    return is_admin() || is_teacher();
}

/** Author (or an admin) may edit/delete a notice. */
function can_manage_notice(array $notice): bool {
    $u = current_user();
    if (!$u) return false;
    return is_admin() || (int)$notice['author_id'] === (int)$u['id'];
}

/** Author of a comment, the notice author, or an admin may delete a comment. */
function can_delete_comment(array $comment, array $notice): bool {
    $u = current_user();
    if (!$u) return false;
    return is_admin()
        || (int)$comment['user_id'] === (int)$u['id']
        || (int)$notice['author_id'] === (int)$u['id'];
}

/**
 * Feed for the current viewer.
 * Course-scoped notices are only visible to students enrolled in that course
 * (and to admins / the notice author).
 */
function notice_feed(int $limit = 20, int $offset = 0, ?int $courseId = null): array {
    $u   = current_user();
    $uid = $u ? (int)$u['id'] : 0;

    $where  = ["n.status = 'published'"];
    $params = [];

    if ($courseId) {
        $where[]  = 'n.course_id = ?';
        $params[] = $courseId;
    } elseif (!is_admin()) {
        // Site-wide notices, plus course notices for courses the viewer is in,
        // plus anything the viewer wrote.
        $where[] = "(n.course_id IS NULL
                     OR n.author_id = ?
                     OR EXISTS (SELECT 1 FROM enrollments en
                                WHERE en.course_id = n.course_id
                                  AND en.user_id = ?
                                  AND en.status = 'active'))";
        $params[] = $uid;
        $params[] = $uid;
    }

    // 'students' audience is for logged-in users only
    if (!$uid) {
        $where[] = "n.audience <> 'students'";
    }

    $sql = "SELECT n.*,
                   u.name AS author_name, u.avatar AS author_avatar, u.role_id AS author_role,
                   c.title_bn AS course_title, c.slug AS course_slug,
                   (SELECT COUNT(*) FROM notice_comments nc WHERE nc.notice_id = n.id AND nc.is_hidden = 0) AS comment_count,
                   (SELECT COUNT(*) FROM notice_reactions nr WHERE nr.notice_id = n.id) AS reaction_count,
                   (SELECT nr2.reaction FROM notice_reactions nr2 WHERE nr2.notice_id = n.id AND nr2.user_id = ?) AS my_reaction
            FROM notices n
            JOIN users u ON u.id = n.author_id
            LEFT JOIN courses c ON c.id = n.course_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY n.is_pinned DESC, n.created_at DESC
            LIMIT $limit OFFSET $offset";

    return db_query($sql, array_merge([$uid], $params));
}

/** ['like' => 4, 'love' => 2, ...] for one notice. */
function notice_reaction_counts(int $noticeId): array {
    $rows = db_query("SELECT reaction, COUNT(*) AS c FROM notice_reactions WHERE notice_id=? GROUP BY reaction", [$noticeId]);
    $out = [];
    foreach ($rows as $r) { $out[$r['reaction']] = (int)$r['c']; }
    return $out;
}

function notice_comments(int $noticeId, int $limit = 30): array {
    return db_query(
        "SELECT nc.*, u.name, u.avatar, u.role_id
         FROM notice_comments nc
         JOIN users u ON u.id = nc.user_id
         WHERE nc.notice_id = ? AND nc.is_hidden = 0
         ORDER BY nc.created_at ASC
         LIMIT $limit",
        [$noticeId]
    );
}

function notice_role_badge(int $roleId): string {
    if (in_array($roleId, [ROLE_ADMIN, ROLE_OPS], true)) return '<span class="notice-badge">Admin</span>';
    if ($roleId === ROLE_TEACHER)                        return '<span class="notice-badge teacher">Teacher</span>';
    if ($roleId === ROLE_MODERATOR)                      return '<span class="notice-badge">Moderator</span>';
    return '';
}

/** One comment row. Also returned by the AJAX endpoint after posting. */
function render_notice_comment(array $c, array $notice): string {
    $canDelete = can_delete_comment($c, $notice);
    $html  = '<div class="comment">';
    $html .= '<img src="' . e(avatar_url($c['avatar'] ?? null, $c['name'])) . '" alt="' . e($c['name']) . '" loading="lazy">';
    $html .= '<div style="min-width:0">';
    $html .= '<div class="comment-bubble">';
    $html .= '<div class="comment-author">' . e($c['name']) . ' ' . notice_role_badge((int)$c['role_id']) . '</div>';
    $html .= '<div class="comment-text">' . nl2br(e($c['body'])) . '</div>';
    $html .= '</div>';
    $html .= '<div class="comment-meta"><span>' . e(time_ago($c['created_at'])) . '</span>';
    if ($canDelete) {
        $html .= '<button type="button" data-delete-comment="' . (int)$c['id'] . '">Delete</button>';
    }
    $html .= '</div></div></div>';
    return $html;
}

/**
 * Full notice card (Facebook-style post).
 * $openComments pre-expands the comment box (used on permalink view).
 */
function render_notice_card(array $n, bool $openComments = false): string
{
    $counts   = notice_reaction_counts((int)$n['id']);
    $total    = array_sum($counts);
    $mine     = $n['my_reaction'] ?? '';
    $comments = notice_comments((int)$n['id']);
    $canManage = can_manage_notice($n);
    $me = current_user();

    arsort($counts);
    $topEmoji = '';
    foreach (array_slice(array_keys($counts), 0, 3) as $k) {
        $topEmoji .= '<span title="' . e(NOTICE_REACTION_LABELS[$k] ?? $k) . '">' . NOTICE_REACTIONS[$k] . '</span>';
    }

    ob_start(); ?>
<article class="notice-card" data-notice-id="<?= (int)$n['id'] ?>" id="notice-<?= (int)$n['id'] ?>">
    <header class="notice-head">
        <img class="notice-avatar" src="<?= e(avatar_url($n['author_avatar'] ?? null, $n['author_name'])) ?>"
             alt="<?= e($n['author_name']) ?>" loading="lazy">
        <div class="notice-who">
            <div class="notice-author">
                <?= e($n['author_name']) ?>
                <?= notice_role_badge((int)$n['author_role']) ?>
            </div>
            <div class="notice-time">
                <?php if (!empty($n['is_pinned'])): ?>
                    <span class="material-icons-round notice-pin" title="Pinned">push_pin</span>
                <?php endif; ?>
                <span><?= e(time_ago($n['created_at'])) ?></span>
                <?php if (!empty($n['course_title'])): ?>
                    <span>·</span>
                    <a href="<?= APP_URL ?>/course.php?slug=<?= e($n['course_slug']) ?>"><?= e($n['course_title']) ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($canManage): ?>
        <form method="POST" action="<?= APP_URL ?>/api/notice.php" class="no-print"
              onsubmit="return confirm('Delete this notice permanently?')">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete_notice">
            <input type="hidden" name="notice_id" value="<?= (int)$n['id'] ?>">
            <input type="hidden" name="redirect" value="1">
            <button class="icon-btn" type="submit" aria-label="Delete notice">
                <span class="material-icons-round" style="font-size:19px">delete_outline</span>
            </button>
        </form>
        <?php endif; ?>
    </header>

    <?php if (!empty($n['title'])): ?>
    <h3 class="notice-title"><?= e($n['title']) ?></h3>
    <?php endif; ?>

    <div class="notice-body"><?= nl2br(e($n['body'])) ?></div>

    <?php if (!empty($n['image'])):
        // handle_image_upload() stores a path relative to the app root
        $imgSrc = preg_match('~^https?://~i', $n['image']) ? $n['image'] : APP_URL . '/' . ltrim($n['image'], '/');
    ?>
    <img class="notice-image skeleton" src="<?= e($imgSrc) ?>" alt="" loading="lazy">
    <?php endif; ?>

    <?php if (!empty($n['link_url'])): ?>
    <a class="notice-link" href="<?= e($n['link_url']) ?>" target="_blank" rel="noopener">
        <span class="material-icons-round" style="font-size:18px">link</span>
        <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($n['link_url']) ?></span>
    </a>
    <?php endif; ?>

    <div class="notice-stats">
        <div class="reaction-bubbles"><?= $topEmoji ?></div>
        <span class="reaction-total" style="margin-right:auto;margin-left:6px"><?= $total > 0 ? $total : '' ?></span>
        <span class="comment-count"><?= (int)$n['comment_count'] ?> <?= (int)$n['comment_count'] === 1 ? 'comment' : 'comments' ?></span>
    </div>

    <div class="notice-actions">
        <div class="react-wrap">
            <button type="button" class="notice-action <?= $mine ? 'reacted' : '' ?>"
                    data-react-toggle data-current="<?= e($mine) ?>">
                <span class="react-icon"><?= $mine ? NOTICE_REACTIONS[$mine] : '👍' ?></span>
                <span class="react-label"><?= $mine ? e(NOTICE_REACTION_LABELS[$mine]) : 'React' ?></span>
            </button>
            <div class="react-picker">
                <?php foreach (NOTICE_REACTIONS as $key => $emoji): ?>
                <button type="button" class="react-opt" data-reaction="<?= $key ?>"
                        title="<?= e(NOTICE_REACTION_LABELS[$key]) ?>"><?= $emoji ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <button type="button" class="notice-action" data-comment-toggle>
            <span class="material-icons-round">mode_comment</span>
            <span>Comment</span>
        </button>
    </div>

    <div class="notice-comments" <?= ($openComments || $comments) ? '' : 'hidden' ?>>
        <?php foreach ($comments as $c): ?>
            <?= render_notice_comment($c, $n) ?>
        <?php endforeach; ?>

        <?php if (empty($n['is_locked'])): ?>
            <?php if ($me): ?>
            <form class="comment-form">
                <img src="<?= e(avatar_url($me['avatar'] ?? null, $me['name'])) ?>" alt="">
                <textarea class="comment-input" rows="1" placeholder="Write a comment…" maxlength="1000"></textarea>
                <button type="submit" class="comment-send" disabled aria-label="Send">
                    <span class="material-icons-round" style="font-size:19px">send</span>
                </button>
            </form>
            <?php else: ?>
            <p style="font-size:var(--text-sm);color:var(--c-ink-muted);margin-top:var(--sp-2)">
                <a href="<?= APP_URL ?>/login.php">Log in</a> to comment.
            </p>
            <?php endif; ?>
        <?php else: ?>
        <p style="font-size:var(--text-sm);color:var(--c-ink-muted);margin-top:var(--sp-2)">
            <span class="material-icons-round" style="font-size:15px;vertical-align:-3px">lock</span>
            Comments are closed on this notice.
        </p>
        <?php endif; ?>
    </div>
</article>
    <?php
    return ob_get_clean();
}
