<?php
/**
 * Web Push subscription endpoint — stores/removes a browser's PushSubscription
 * so create_notification() can pop a native OS notification to it later.
 */
define('B_ACADEMY', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

session_start_secure();
send_security_headers();
header('Content-Type: application/json; charset=utf-8');

function pw_out(array $payload, int $code = 200): never {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

try {
    db_exec("SELECT 1 FROM push_subscriptions LIMIT 1");
} catch (PDOException $e) {
    db_exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        endpoint VARCHAR(500) NOT NULL,
        p256dh VARCHAR(255) NOT NULL,
        auth_key VARCHAR(255) NOT NULL,
        user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_endpoint (endpoint(255))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_out(['success' => false, 'message' => 'POST required.'], 405);
if (!csrf_verify()) pw_out(['success' => false, 'message' => 'Session expired.'], 403);
if (!is_logged_in()) pw_out(['success' => false, 'message' => 'Please log in first.'], 401);

$me = current_user();
$action = $_POST['action'] ?? '';

if ($action === 'subscribe') {
    $endpoint = sanitize_string($_POST['endpoint'] ?? '', 500);
    $p256dh   = sanitize_string($_POST['p256dh'] ?? '', 255);
    $auth     = sanitize_string($_POST['auth'] ?? '', 255);
    if (!$endpoint || !$p256dh || !$auth) pw_out(['success' => false, 'message' => 'Incomplete subscription.'], 400);

    db_exec(
        "INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth_key, user_agent)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), p256dh=VALUES(p256dh), auth_key=VALUES(auth_key), user_agent=VALUES(user_agent)",
        [$me['id'], $endpoint, $p256dh, $auth, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]
    );
    pw_out(['success' => true]);
}

if ($action === 'unsubscribe') {
    $endpoint = sanitize_string($_POST['endpoint'] ?? '', 500);
    if ($endpoint) db_exec("DELETE FROM push_subscriptions WHERE user_id=? AND endpoint=?", [$me['id'], $endpoint]);
    pw_out(['success' => true]);
}

pw_out(['success' => false, 'message' => 'Unknown action.'], 400);
