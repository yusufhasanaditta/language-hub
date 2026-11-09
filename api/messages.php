<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
header('Content-Type: application/json; charset=UTF-8');

if (!is_logged_in()) { echo json_encode(['error'=>'Unauthorized']); exit; }

$action = sanitize_string($_GET['action'] ?? '', 30);
$uid    = (int)current_user()['id'];

// Load enrolled students for a course (teacher feedback UI)
if ($action === 'students') {
    $courseId = sanitize_int($_GET['course'] ?? 0);
    $tp = db_row("SELECT id FROM teacher_profiles WHERE user_id=?", [$uid]);
    if (!$tp || !$courseId) { echo json_encode(['students'=>[]]); exit; }
    $tpId = (int)$tp['id'];
    $owns = db_val("SELECT 1 FROM courses WHERE id=? AND teacher_id=?", [$courseId, $tpId]);
    if (!$owns) { echo json_encode(['error'=>'forbidden']); exit; }
    $students = db_query("
        SELECT u.id, u.name, u.avatar
        FROM enrollments e JOIN users u ON u.id=e.user_id
        WHERE e.course_id=? AND e.status='active'
        ORDER BY u.name
    ", [$courseId]);
    $out = array_map(fn($s) => [
        'id'     => (int)$s['id'],
        'name'   => $s['name'],
        'avatar' => avatar_url($s['avatar'], $s['name']),
    ], $students);
    echo json_encode(['students' => $out]);
    exit;
}

// Mark messages read
if ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!csrf_check($data['csrf_token'] ?? '')) { echo json_encode(['error'=>'csrf']); exit; }
    $courseId = (int)($data['course_id'] ?? 0);
    $fromUser = (int)($data['from_user'] ?? 0);
    db_exec("UPDATE messages SET read_at=NOW() WHERE course_id=? AND receiver_id=? AND sender_id=? AND read_at IS NULL",
        [$courseId, $uid, $fromUser]);
    echo json_encode(['ok' => true]);
    exit;
}

// Get unread count
if ($action === 'unread') {
    $count = (int)db_val("SELECT COUNT(*) FROM messages WHERE receiver_id=? AND read_at IS NULL", [$uid]);
    echo json_encode(['count' => $count]);
    exit;
}

echo json_encode(['error' => 'unknown action']);
