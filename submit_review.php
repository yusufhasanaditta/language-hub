<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Invalid method.');
}
verify_csrf_token();

$course_id = (int)($_POST['course_id'] ?? 0);
$rating = (int)($_POST['rating'] ?? 5);
$body = sanitize_string($_POST['body'] ?? '', 1000);
$user_id = auth_user('id');

if ($rating < 1 || $rating > 5) $rating = 5;

// Must be enrolled
if (!is_enrolled($course_id)) {
    die('You must be enrolled to submit a review.');
}

// Insert or update
db_execute("
    INSERT INTO reviews (course_id, user_id, rating, body, is_approved) 
    VALUES (?, ?, ?, ?, 1)
    ON DUPLICATE KEY UPDATE rating=VALUES(rating), body=VALUES(body), created_at=CURRENT_TIMESTAMP
", [$course_id, $user_id, $rating, $body]);

// Update course rating count and average
$stats = db_row("SELECT COUNT(*) as cnt, AVG(rating) as avg_rating FROM reviews WHERE course_id=? AND is_approved=1", [$course_id]);
if ($stats) {
    db_execute("UPDATE courses SET rating_count=?, rating_avg=? WHERE id=?", [$stats['cnt'], $stats['avg_rating'], $course_id]);
}

set_flash('success', 'Your review has been submitted successfully.');
$course = db_row("SELECT slug FROM courses WHERE id=?", [$course_id]);
if ($course) {
    redirect('/course.php?slug=' . urlencode($course['slug']) . '#reviews');
}
redirect('/');
