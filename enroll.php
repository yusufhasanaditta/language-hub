<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    flash('error', 'Invalid request.');
    redirect(APP_URL . '/courses.php');
}

$cid    = sanitize_int($_POST['course_id'] ?? 0);
$userId = (int)$_SESSION['user_id'];

if (!$cid) {
    flash('error', 'Course not found.');
    redirect(APP_URL . '/courses.php');
}

$course = db_row("SELECT * FROM courses WHERE id=? AND status='published'", [$cid]);
if (!$course) {
    flash('error', 'This course is not available.');
    redirect(APP_URL . '/courses.php');
}

// Check already enrolled
if (is_enrolled($cid)) {
    flash('info', 'You are already enrolled in this course.');
    redirect(APP_URL . '/learn.php?course=' . $cid);
}

// Paid courses → checkout
$price = $course['is_free'] ? 0 : (float)($course['sale_price'] ?: $course['price']);
if ($price > 0) {
    redirect(APP_URL . '/checkout.php?course=' . $cid);
}

// Free enrollment
rate_limit('enroll', 20);

db_exec(
    "INSERT IGNORE INTO enrollments (user_id, course_id, status, enrolled_at)
     VALUES (?,?,'active',NOW())",
    [$userId, $cid]
);

// Increment counter
db_exec("UPDATE courses SET enrolled_count=enrolled_count+1 WHERE id=?", [$cid]);

create_notification(
    $userId, 'enrollment', 'Enrolled in ' . $course['title_bn'],
    'You now have full access to this course. Happy learning!',
    APP_URL . '/learn.php?course=' . $cid
);

flash('success', 'You have successfully enrolled! Start your course.');
redirect(APP_URL . '/my-courses.php');

