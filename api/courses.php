<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = sanitize_string($_GET['action'] ?? '', 30);

// ── Helpers ──────────────────────────────────────────────────────────────────

function json_ok(mixed $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function json_fail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg]);
    exit;
}

function require_auth_api(): array {
    $u = current_user();
    if (!$u) json_fail('Unauthorized', 401);
    return $u;
}

// ── Rate limit ────────────────────────────────────────────────────────────────
rate_limit('api_courses', 120);

// ── Route dispatcher ──────────────────────────────────────────────────────────

match(true) {
    $action === 'search'   => handle_search(),
    $action === 'detail'   => handle_detail(),
    $action === 'enroll'   => handle_enroll(),
    $action === 'enrolled' => handle_enrolled(),
    $action === 'reviews'  => handle_reviews(),
    default                => json_fail('Unknown action', 404),
};

// ── Handlers ──────────────────────────────────────────────────────────────────

function handle_search(): never {
    $q        = sanitize_string($_GET['q'] ?? '', 100);
    $category = sanitize_string($_GET['category'] ?? '', 80);
    $type     = sanitize_string($_GET['type'] ?? '', 20);
    $level    = sanitize_string($_GET['level'] ?? '', 20);
    $sort     = sanitize_string($_GET['sort'] ?? 'popular', 20);
    $page     = max(1, sanitize_int($_GET['page'] ?? 1));
    $per      = min(24, max(1, sanitize_int($_GET['per'] ?? 12)));
    $offset   = ($page - 1) * $per;

    $where  = ["c.status='published'"];
    $params = [];

    if ($q) { $where[] = '(c.title_bn LIKE ? OR c.description_bn LIKE ?)'; $params[] = '%'.$q.'%'; $params[] = '%'.$q.'%'; }
    if ($category) { $where[] = 'cat.slug=?'; $params[] = $category; }
    if (in_array($type, ['recorded','live','hybrid'])) { $where[] = 'c.type=?'; $params[] = $type; }
    if (in_array($level, ['beginner','intermediate','advanced'])) { $where[] = 'c.level=?'; $params[] = $level; }

    $orderMap = [
        'popular'  => 'enroll_count DESC',
        'newest'   => 'c.created_at DESC',
        'price_asc'=> '(CASE WHEN c.is_free THEN 0 ELSE COALESCE(NULLIF(c.sale_price,0),c.price) END) ASC',
        'rating'   => 'rating DESC',
    ];
    $orderSQL = $orderMap[$sort] ?? $orderMap['popular'];

    $whereSQL = 'WHERE '.implode(' AND ', $where);
    $total = (int)db_val("
        SELECT COUNT(*) FROM courses c
        JOIN categories cat ON cat.id=c.category_id
        $whereSQL
    ", $params);

    $courses = db_query("
        SELECT c.id, c.title_bn, c.slug, c.thumbnail, c.type, c.level,
               c.price, c.sale_price, c.is_free, c.total_lessons,
               cat.name_bn as category, cat.color as cat_color, cat.slug as cat_slug,
               u.name as teacher_name, tp.headline as teacher_headline,
               (SELECT COUNT(*) FROM enrollments e WHERE e.course_id=c.id AND e.status='active') as enroll_count,
               (SELECT COALESCE(AVG(r.rating),0) FROM reviews r WHERE r.course_id=c.id) as rating,
               (SELECT COUNT(*) FROM reviews r WHERE r.course_id=c.id) as review_count
        FROM courses c
        JOIN categories cat ON cat.id=c.category_id
        JOIN teacher_profiles tp ON tp.id=c.teacher_id
        JOIN users u ON u.id=tp.user_id
        $whereSQL
        ORDER BY $orderSQL
        LIMIT $per OFFSET $offset
    ", $params);

    json_ok([
        'courses'     => $courses,
        'total'       => $total,
        'page'        => $page,
        'per'         => $per,
        'total_pages' => (int)ceil($total / $per),
    ]);
}

function handle_detail(): never {
    $slug = sanitize_string($_GET['slug'] ?? '', 200);
    $id   = sanitize_int($_GET['id'] ?? 0);
    if (!$slug && !$id) json_fail('slug or id required');

    $where = $slug ? "c.slug=? AND c.status='published'" : "c.id=? AND c.status='published'";
    $param = $slug ?: $id;

    $course = db_row("
        SELECT c.*, cat.name_bn as category, cat.color as cat_color, cat.slug as cat_slug,
               u.name as teacher_name, u.avatar as teacher_avatar,
               tp.headline as teacher_headline, tp.bio as teacher_bio,
               tp.rating_avg as teacher_rating,
               (SELECT COUNT(*) FROM enrollments e WHERE e.course_id=c.id AND e.status='active') as enroll_count,
               (SELECT COALESCE(AVG(r.rating),0) FROM reviews r WHERE r.course_id=c.id) as rating,
               (SELECT COUNT(*) FROM reviews r WHERE r.course_id=c.id) as review_count
        FROM courses c
        JOIN categories cat ON cat.id=c.category_id
        JOIN teacher_profiles tp ON tp.id=c.teacher_id
        JOIN users u ON u.id=tp.user_id
        WHERE $where
    ", [$param]);

    if (!$course) json_fail('Course not found', 404);

    // Lessons (public-safe: hide video_url for paid lessons if not enrolled)
    $user     = current_user();
    $enrolled = $user ? (bool)db_val("SELECT 1 FROM enrollments WHERE user_id=? AND course_id=? AND status='active'", [$user['id'], $course['id']]) : false;

    $lessons = db_query("
        SELECT id, title, kind as type, duration, sort_order, is_free,
               " . ($enrolled ? 'video_url,' : '') . "
               (SELECT COUNT(*) FROM lesson_progress lp JOIN enrollments e ON e.id=lp.enrollment_id WHERE lp.lesson_id=lessons.id AND e.user_id=" . ($user ? (int)$user['id'] : 0) . " AND lp.completed=1) as completed
        FROM lessons WHERE module_id IN (SELECT id FROM modules WHERE course_id=?) ORDER BY sort_order ASC
    ", [(int)$course['id']]);

    $reviews = db_query("
        SELECT r.rating, r.body as comment, r.created_at, u.name as reviewer_name, u.avatar as reviewer_avatar
        FROM reviews r JOIN users u ON u.id=r.user_id
        WHERE r.course_id=? ORDER BY r.created_at DESC LIMIT 5
    ", [(int)$course['id']]);

    json_ok([
        'course'   => $course,
        'lessons'  => $lessons,
        'reviews'  => $reviews,
        'enrolled' => $enrolled,
    ]);
}

function handle_enroll(): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_fail('POST required', 405);
    csrf_check();

    $user     = require_auth_api();
    $uid      = (int)$user['id'];
    $courseId = sanitize_int($_POST['course_id'] ?? 0);
    if (!$courseId) json_fail('course_id required');

    $course = db_row("SELECT * FROM courses WHERE id=? AND status='published'", [$courseId]);
    if (!$course) json_fail('Course not found', 404);

    // Already enrolled?
    if (db_val("SELECT 1 FROM enrollments WHERE user_id=? AND course_id=?", [$uid, $courseId])) {
        json_fail('Already enrolled', 409);
    }

    // Free course: direct enroll
    if ($course['is_free'] || (float)$course['price'] == 0) {
        db_insert("INSERT INTO enrollments (user_id, course_id, status, progress_pct, enrolled_at) VALUES (?,?,'active',0,NOW())",
            [$uid, $courseId]);
        create_notification(
            $uid, 'enrollment', 'Enrolled in ' . $course['title_bn'],
            'You now have full access to this course. Happy learning!',
            APP_URL . '/learn.php?course=' . $courseId
        );
        json_ok(['enrolled' => true, 'redirect' => APP_URL . '/my-courses.php']);
    }

    // Paid: redirect to checkout
    json_ok(['enrolled' => false, 'redirect' => APP_URL . '/checkout.php?course=' . $courseId]);
}

function handle_enrolled(): never {
    $user = require_auth_api();
    $uid  = (int)$user['id'];
    $page = max(1, sanitize_int($_GET['page'] ?? 1));
    $per  = min(24, max(1, sanitize_int($_GET['per'] ?? 12)));
    $offset = ($page-1)*$per;

    $total = (int)db_val("SELECT COUNT(*) FROM enrollments WHERE user_id=? AND status='active'", [$uid]);
    $courses = db_query("
        SELECT c.id, c.title_bn, c.slug, c.thumbnail, c.type, c.total_lessons,
               cat.name_bn as category, cat.color as cat_color,
               u.name as teacher_name,
               e.progress_pct, e.enrolled_at
        FROM enrollments e
        JOIN courses c ON c.id=e.course_id
        JOIN categories cat ON cat.id=c.category_id
        JOIN teacher_profiles tp ON tp.id=c.teacher_id
        JOIN users u ON u.id=tp.user_id
        WHERE e.user_id=? AND e.status='active'
        ORDER BY e.enrolled_at DESC
        LIMIT $per OFFSET $offset
    ", [$uid]);

    json_ok(['courses' => $courses, 'total' => $total, 'page' => $page, 'per' => $per]);
}

function handle_reviews(): never {
    $courseId = sanitize_int($_GET['course_id'] ?? 0);
    if (!$courseId) json_fail('course_id required');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $user   = require_auth_api();
        $uid    = (int)$user['id'];
        $rating = max(1, min(5, sanitize_int($_POST['rating'] ?? 0)));
        $comment= sanitize_string($_POST['comment'] ?? '', 1000);

        // Must be enrolled
        if (!db_val("SELECT 1 FROM enrollments WHERE user_id=? AND course_id=? AND status='active'", [$uid, $courseId])) {
            json_fail('Must be enrolled to review', 403);
        }
        // Already reviewed?
        if (db_val("SELECT 1 FROM reviews WHERE user_id=? AND course_id=?", [$uid, $courseId])) {
            json_fail('Already reviewed', 409);
        }

        db_insert("INSERT INTO reviews (user_id, course_id, rating, body, created_at) VALUES (?,?,?,?,NOW())",
            [$uid, $courseId, $rating, $comment]);

        // Update avg
        db_exec("UPDATE teacher_profiles tp JOIN courses c ON c.teacher_id=tp.id SET tp.rating_avg=(SELECT AVG(r.rating) FROM reviews r JOIN courses cc ON cc.id=r.course_id WHERE cc.teacher_id=tp.id) WHERE c.id=?", [$courseId]);

        json_ok(['saved' => true]);
    }

    // GET: paginated reviews
    $page   = max(1, sanitize_int($_GET['page'] ?? 1));
    $per    = min(20, max(1, sanitize_int($_GET['per'] ?? 10)));
    $offset = ($page-1)*$per;
    $total  = (int)db_val("SELECT COUNT(*) FROM reviews WHERE course_id=?", [$courseId]);
    $reviews= db_query("
        SELECT r.id, r.rating, r.body as comment, r.created_at, u.name, u.avatar
        FROM reviews r JOIN users u ON u.id=r.user_id
        WHERE r.course_id=? ORDER BY r.created_at DESC LIMIT $per OFFSET $offset
    ", [$courseId]);

    $ratingDist = db_query("SELECT rating, COUNT(*) as cnt FROM reviews WHERE course_id=? GROUP BY rating ORDER BY rating DESC", [$courseId]);

    json_ok(['reviews' => $reviews, 'total' => $total, 'page' => $page, 'distribution' => $ratingDist]);
}

