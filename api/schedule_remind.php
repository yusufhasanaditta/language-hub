<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if (!str_starts_with($ref, APP_URL)) {
        json_error('Invalid CSRF token', 403);
    }
}

if (!isset($_SESSION['user_id'])) json_error('Unauthorized', 401);
$uid = (int)$_SESSION['user_id'];

$raw = file_get_contents('php://input');
$body = $raw ? (json_decode($raw, true) ?? []) : $_POST;
$scheduleId = sanitize_int($body['schedule_id'] ?? 0);
$action     = sanitize_string($body['action'] ?? 'set', 10);

if (!$scheduleId) json_error('schedule_id required');

// Confirm the student is enrolled in the course this schedule belongs to
$schedule = db_row("SELECT s.*, e.status FROM schedules s
    JOIN enrollments e ON e.course_id = s.course_id AND e.user_id = ?
    WHERE s.id = ?", [$uid, $scheduleId]);
if (!$schedule || $schedule['status'] !== 'active') json_error('Not enrolled in this course', 403);

if ($action === 'unset') {
    db_exec("DELETE FROM schedule_reminders WHERE schedule_id=? AND user_id=?", [$scheduleId, $uid]);
    json_success(['reminder' => false]);
}

if (strtotime($schedule['scheduled_at']) <= time()) {
    json_error('This event has already started.');
}

db_exec(
    "INSERT INTO schedule_reminders (schedule_id, user_id) VALUES (?,?) ON DUPLICATE KEY UPDATE notified_at=NULL",
    [$scheduleId, $uid]
);

json_success(['reminder' => true]);
