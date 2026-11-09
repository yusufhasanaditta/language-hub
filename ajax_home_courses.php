<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$cat_slug = sanitize_string($_GET['category'] ?? '', 100);
$courses = [];

if ($cat_slug && $cat_slug !== 'all') {
    $cat = db_row("SELECT id FROM categories WHERE slug = ?", [$cat_slug]);
    if ($cat) {
        $courses = db_query("
            SELECT c.*, cat.name_bn as cat_name, cat.color as cat_color,
                   u.name as teacher_name, u.avatar as teacher_avatar
            FROM courses c
            JOIN categories cat ON cat.id = c.category_id
            JOIN teacher_profiles tp ON tp.id = c.teacher_id
            JOIN users u ON u.id = tp.user_id
            WHERE c.status = 'published' AND c.category_id = ?
            ORDER BY c.enrolled_count DESC
            LIMIT 8
        ", [$cat['id']]);
    }
} else {
    // All featured courses
    $courses = db_query("
        SELECT c.*, cat.name_bn as cat_name, cat.color as cat_color,
               u.name as teacher_name, u.avatar as teacher_avatar
        FROM courses c
        JOIN categories cat ON cat.id = c.category_id
        JOIN teacher_profiles tp ON tp.id = c.teacher_id
        JOIN users u ON u.id = tp.user_id
        WHERE c.status = 'published' AND c.is_featured = 1
        ORDER BY c.enrolled_count DESC
        LIMIT 8
    ");
}

if (empty($courses)) {
    echo '<p style="grid-column:1/-1;text-align:center;color:var(--c-ink-muted);padding:40px 0;">No courses found in this category.</p>';
} else {
    foreach ($courses as $course) {
        include __DIR__ . '/includes/course_card.php';
    }
}
