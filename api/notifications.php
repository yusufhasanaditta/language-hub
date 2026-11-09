<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();

if (!isset($_SESSION['user_id'])) json_error('Unauthorized', 401);
$uid = (int) $_SESSION['user_id'];

$method = $_SERVER['REQUEST_METHOD'];

// Reads (unread count / list for polling) don't need a CSRF token.
if ($method === 'GET') {
    $action = sanitize_string($_GET['action'] ?? 'unread_count', 20);

    if ($action === 'unread_count') {
        $count = (int) db_val("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL", [$uid]);
        json_success(['unread' => $count]);
    }

    if ($action === 'list') {
        $rows = db_query(
            "SELECT id, kind, title, body, action_url, read_at, created_at
             FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 8",
            [$uid]
        );
        $unread = (int) db_val("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL", [$uid]);
        json_success(['notifications' => $rows, 'unread' => $unread]);
    }

    json_error('Unknown action');
}

// Writes (mark read) need the CSRF token, same relaxed check used by the
// other small fetch()-based endpoints (header or same-origin referer).
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if (!str_starts_with($ref, APP_URL)) {
        json_error('Invalid CSRF token', 403);
    }
}

$raw = file_get_contents('php://input');
$body = $raw ? (json_decode($raw, true) ?? []) : $_POST;
$action = sanitize_string($body['action'] ?? '', 20);

if ($action === 'mark_read') {
    $id = sanitize_int($body['id'] ?? 0);
    if (!$id) json_error('id required');
    db_exec("UPDATE notifications SET read_at=NOW() WHERE id=? AND user_id=? AND read_at IS NULL", [$id, $uid]);
    $count = (int) db_val("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL", [$uid]);
    json_success(['unread' => $count]);
}

if ($action === 'mark_all_read') {
    db_exec("UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL", [$uid]);
    json_success(['unread' => 0]);
}

json_error('Unknown action');
