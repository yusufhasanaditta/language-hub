<?php
/**
 * Notice board AJAX endpoint — reactions, comments, deletes.
 * All actions are POST + CSRF protected.
 */
define('B_ACADEMY', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notice_lib.php';

session_start_secure();

/** Flat JSON (the shared json_success() nests everything under "data"). */
function out(array $payload, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['success' => false, 'message' => 'POST required.'], 405);
}
if (!csrf_verify()) {
    out(['success' => false, 'message' => 'Session expired. Please refresh the page.'], 403);
}
if (!is_logged_in()) {
    out(['success' => false, 'message' => 'Please log in first.'], 401);
}

$me     = current_user();
$action = $_POST['action'] ?? '';

switch ($action) {

    // ── React / un-react ──────────────────────────────────────────────────
    case 'react': {
        rate_limit('notice_react', 240);
        $noticeId = (int)($_POST['notice_id'] ?? 0);
        $reaction = trim($_POST['reaction'] ?? '');

        $notice = db_row("SELECT id FROM notices WHERE id=? AND status='published'", [$noticeId]);
        if (!$notice) out(['success' => false, 'message' => 'Notice not found.'], 404);

        if ($reaction === '') {
            db_exec("DELETE FROM notice_reactions WHERE notice_id=? AND user_id=?", [$noticeId, $me['id']]);
            $mine = '';
        } else {
            if (!isset(NOTICE_REACTIONS[$reaction])) {
                out(['success' => false, 'message' => 'Unknown reaction.'], 400);
            }
            db_exec(
                "INSERT INTO notice_reactions (notice_id, user_id, reaction) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE reaction = VALUES(reaction)",
                [$noticeId, $me['id'], $reaction]
            );
            $mine = $reaction;
        }

        $counts = notice_reaction_counts($noticeId);
        out([
            'success'     => true,
            'my_reaction' => $mine,
            'counts'      => $counts,
            'total'       => array_sum($counts),
        ]);
    }

    // ── Post a comment ────────────────────────────────────────────────────
    case 'comment': {
        rate_limit('notice_comment', 40);
        $noticeId = (int)($_POST['notice_id'] ?? 0);
        $body     = trim((string)($_POST['body'] ?? ''));

        if ($body === '')            out(['success' => false, 'message' => 'Comment is empty.'], 400);
        if (mb_strlen($body) > 1000) out(['success' => false, 'message' => 'Comment is too long (max 1000 characters).'], 400);

        $notice = db_row("SELECT * FROM notices WHERE id=? AND status='published'", [$noticeId]);
        if (!$notice)             out(['success' => false, 'message' => 'Notice not found.'], 404);
        if ($notice['is_locked']) out(['success' => false, 'message' => 'Comments are closed on this notice.'], 403);

        $id = db_insert(
            "INSERT INTO notice_comments (notice_id, user_id, body) VALUES (?,?,?)",
            [$noticeId, $me['id'], $body]
        );

        // Tell the notice author someone replied (skip self-comments)
        if ((int)$notice['author_id'] !== (int)$me['id']) {
            db_insert(
                "INSERT INTO notifications (user_id, kind, title, body, action_url) VALUES (?,?,?,?,?)",
                [
                    $notice['author_id'],
                    'notice_comment',
                    $me['name'] . ' commented on your notice',
                    mb_substr($body, 0, 120),
                    APP_URL . '/notices.php#notice-' . $noticeId,
                ]
            );
        }

        $comment = db_row(
            "SELECT nc.*, u.name, u.avatar, u.role_id
             FROM notice_comments nc JOIN users u ON u.id = nc.user_id
             WHERE nc.id = ?",
            [$id]
        );
        $count = (int)db_val("SELECT COUNT(*) FROM notice_comments WHERE notice_id=? AND is_hidden=0", [$noticeId]);

        out([
            'success' => true,
            'html'    => render_notice_comment($comment, $notice),
            'count'   => $count,
        ]);
    }

    // ── Delete a comment ──────────────────────────────────────────────────
    case 'delete_comment': {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $comment = db_row("SELECT * FROM notice_comments WHERE id=?", [$commentId]);
        if (!$comment) out(['success' => false, 'message' => 'Comment not found.'], 404);

        $notice = db_row("SELECT * FROM notices WHERE id=?", [$comment['notice_id']]);
        if (!$notice || !can_delete_comment($comment, $notice)) {
            out(['success' => false, 'message' => 'Not allowed.'], 403);
        }

        db_exec("DELETE FROM notice_comments WHERE id=?", [$commentId]);
        out(['success' => true]);
    }

    // ── Delete a notice (form POST, redirects back) ───────────────────────
    case 'delete_notice': {
        $noticeId = (int)($_POST['notice_id'] ?? 0);
        $notice = db_row("SELECT * FROM notices WHERE id=?", [$noticeId]);
        if (!$notice || !can_manage_notice($notice)) {
            if (!empty($_POST['redirect'])) { flash('error', 'Not allowed.'); redirect_back('/notices.php'); }
            out(['success' => false, 'message' => 'Not allowed.'], 403);
        }

        db_exec("DELETE FROM notices WHERE id=?", [$noticeId]);

        if (!empty($_POST['redirect'])) {
            flash('success', 'Notice deleted.');
            redirect_back('/notices.php');
        }
        out(['success' => true]);
    }

    default:
        out(['success' => false, 'message' => 'Unknown action.'], 400);
}
