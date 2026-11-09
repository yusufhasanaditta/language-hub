<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();

// Accept GET or POST, JSON or form
$method = $_SERVER['REQUEST_METHOD'];

// CSRF: check header or post param
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    // Also allow beacon (no custom headers) by checking referer same-origin
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if (!str_starts_with($ref, APP_URL)) {
        json_error('Invalid CSRF token', 403);
    }
}

if (!isset($_SESSION['user_id'])) json_error('Unauthorized', 401);

// Parse body
$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $body = json_decode($raw, true) ?? [];
    } else {
        $body = $_POST;
    }
}
$input = array_merge($_GET, $body);

$lesson_id = sanitize_int($input['lesson_id'] ?? 0);
$position  = max(0, (int)($input['position'] ?? 0));
$completed = !empty($input['completed']) && $input['completed'] !== 'false' ? 1 : 0;

if (!$lesson_id) json_error('lesson_id required');

$userId = (int)$_SESSION['user_id'];

// Get lesson → course → enrollment
$lesson = db_row("SELECT l.*, m.course_id FROM lessons l JOIN modules m ON m.id=l.module_id WHERE l.id=?", [$lesson_id]);
if (!$lesson) json_error('Lesson not found', 404);

$enrollment = db_row(
    "SELECT * FROM enrollments WHERE user_id=? AND course_id=? AND status='active'",
    [$userId, (int)$lesson['course_id']]
);
if (!$enrollment) json_error('Not enrolled', 403);

$enrollId = (int)$enrollment['id'];

// Upsert lesson_progress
$existing = db_row("SELECT * FROM lesson_progress WHERE enrollment_id=? AND lesson_id=?", [$enrollId, $lesson_id]);

if ($existing) {
    // Don't un-complete a completed lesson
    $newCompleted = $existing['completed'] ? 1 : $completed;
    db_exec(
        "UPDATE lesson_progress SET last_position=?, completed=?, updated_at=NOW() WHERE id=?",
        [$position, $newCompleted, (int)$existing['id']]
    );
} else {
    db_exec(
        "INSERT INTO lesson_progress (enrollment_id, lesson_id, last_position, completed, created_at, updated_at)
         VALUES (?,?,?,?,NOW(),NOW())",
        [$enrollId, $lesson_id, $position, $completed]
    );
}

// Recalculate enrollment progress
$totalLessons = (int)db_val(
    "SELECT COUNT(*) FROM lessons l JOIN modules m ON m.id=l.module_id WHERE m.course_id=? AND l.is_published=1",
    [(int)$lesson['course_id']]
);
if ($totalLessons > 0) {
    $doneLessons = (int)db_val(
        "SELECT COUNT(*) FROM lesson_progress lp
         JOIN lessons l ON l.id=lp.lesson_id
         JOIN modules m ON m.id=l.module_id
         WHERE lp.enrollment_id=? AND lp.completed=1 AND m.course_id=?",
        [$enrollId, (int)$lesson['course_id']]
    );
    $pct = min(100, round($doneLessons / $totalLessons * 100));
    $completedAt = ($pct >= 100) ? 'NOW()' : 'NULL';
    db_exec(
        "UPDATE enrollments SET progress_pct=?, completed_at=IF(?>=100,NOW(),completed_at), updated_at=NOW() WHERE id=?",
        [$pct, $pct, $enrollId]
    );
}

json_success(['saved' => true, 'completed' => (bool)$completed]);
