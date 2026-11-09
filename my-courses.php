<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_login();

$user = current_user();
$uid  = (int)$user['id'];

$statusFilter = sanitize_string($_GET['status'] ?? 'all', 20);
$sortFilter   = sanitize_string($_GET['sort'] ?? 'recent', 20);
$search       = sanitize_string($_GET['q'] ?? '', 100);
$page         = max(1, sanitize_int($_GET['page'] ?? 1));
$perPage      = 12;

$where  = ["e.user_id=?", "e.status='active'"];
$params = [$uid];

if ($statusFilter === 'progress') { $where[] = 'e.progress_pct > 0 AND e.progress_pct < 100'; }
elseif ($statusFilter === 'completed') { $where[] = 'e.progress_pct = 100'; }
elseif ($statusFilter === 'not_started') { $where[] = 'e.progress_pct = 0'; }

if ($search) {
    $where[] = 'c.title_bn LIKE ?';
    $params[] = '%'.$search.'%';
}

$whereSQL = 'WHERE '.implode(' AND ', $where);
$orderSQL = match($sortFilter) {
    'progress'  => 'e.progress_pct DESC',
    'alpha'     => 'c.title_bn ASC',
    default     => 'e.enrolled_at DESC',
};

$total  = (int)db_val("SELECT COUNT(*) FROM enrollments e JOIN courses c ON c.id=e.course_id $whereSQL", $params);
$pages  = max(1, (int)ceil($total/$perPage));
$offset = ($page-1)*$perPage;

$courses = db_query("
    SELECT c.id, c.title_bn, c.slug, c.thumbnail, c.total_lessons, c.type,
           cat.name_bn as cat_name, cat.color as cat_color,
           u2.name as teacher_name, u2.avatar as teacher_avatar,
           e.progress_pct, e.enrolled_at, e.status as enroll_status,
           (SELECT COUNT(*) FROM lessons l JOIN modules m ON m.id=l.module_id WHERE m.course_id=c.id AND l.is_free=0) as paid_lessons,
           (SELECT COUNT(*) FROM exams ex WHERE ex.course_id=c.id AND ex.status='published') as exam_count
    FROM enrollments e
    JOIN courses c ON c.id=e.course_id
    JOIN categories cat ON cat.id=c.category_id
    JOIN teacher_profiles tp ON tp.id=c.teacher_id
    JOIN users u2 ON u2.id=tp.user_id
    $whereSQL
    ORDER BY $orderSQL
    LIMIT $perPage OFFSET $offset
", $params);

// Quick stats
$stats = db_row("
    SELECT
        COUNT(*) as total,
        SUM(progress_pct = 100) as completed,
        SUM(progress_pct > 0 AND progress_pct < 100) as in_progress,
        SUM(progress_pct = 0) as not_started,
        COALESCE(AVG(progress_pct),0) as avg_progress
    FROM enrollments WHERE user_id=? AND status='active'
", [$uid]);

// Enrollment success banner for the case where flash() couldn't be used —
// see payment_callback.php for why (SameSite=Strict blocks the session
// cookie on SSLCommerz's cross-site callback). Re-verified against the DB
// rather than trusting the query param.
$justEnrolledCourse = null;
$justEnrolledId = sanitize_int($_GET['just_enrolled'] ?? 0);
if ($justEnrolledId) {
    $justEnrolledCourse = db_row(
        "SELECT c.title_bn FROM enrollments e JOIN courses c ON c.id=e.course_id WHERE e.user_id=? AND e.course_id=? AND e.status='active'",
        [$uid, $justEnrolledId]
    );
}

$page_title = 'My Courses';
$extra_css  = '<style>
@media(max-width:480px){
    .mycourses-search-form{width:100%;margin-left:0 !important}
    .mycourses-search-form input[name="q"]{width:auto;flex:1;min-width:0}
}
</style>';
include __DIR__ . '/includes/header.php';
?>

<?php if ($justEnrolledCourse): ?>
<div class="container" style="padding-top:var(--sp-6)">
    <div class="alert alert-success" role="alert">
        <span class="material-icons-round">check_circle</span>
        <span>🎉 You're successfully enrolled in <strong><?= e($justEnrolledCourse['title_bn']) ?></strong>! Start learning below.</span>
        <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Close">✕</button>
    </div>
</div>
<?php endif; ?>

<!-- Hero Banner -->
<div style="background:linear-gradient(135deg,#0F1A3E 0%,#1C0B52 100%);padding:var(--sp-10) 0">
    <div class="container">
        <div style="display:flex;align-items:center;gap:var(--sp-5);flex-wrap:wrap">
            <div style="flex:1;min-width:240px">
                <h1 style="color:#fff;font-size:clamp(1.5rem,3vw,2rem);margin-bottom:4px">My Courses</h1>
                <p style="color:rgba(255,255,255,.6);font-size:var(--text-sm)">Track your learning journey</p>
            </div>
            <div style="display:flex;gap:var(--sp-3);flex-wrap:wrap">
                <?php foreach ([
                    [(int)$stats['total'], 'Total Courses', 'school'],
                    [(int)$stats['in_progress'], 'In Progress', 'play_circle'],
                    [(int)$stats['completed'], 'Completed', 'verified'],
                    [round((float)$stats['avg_progress']).'%', 'Avg. Progress', 'trending_up'],
                ] as [$val, $lab, $icon]): ?>
                <div style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:var(--r-lg);padding:var(--sp-3) var(--sp-4);text-align:center;min-width:80px">
                    <span class="material-icons-round" style="font-size:18px;color:rgba(255,255,255,.5)"><?= $icon ?></span>
                    <div style="font-family:var(--font-latin);font-size:var(--text-xl);font-weight:800;color:#fff;margin-top:2px"><?= $val ?></div>
                    <div style="font-size:var(--text-xs);color:rgba(255,255,255,.5)"><?= $lab ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Content -->
<div class="container" style="padding-top:var(--sp-8);padding-bottom:var(--sp-16)">

    <!-- Filters row -->
    <div style="display:flex;align-items:center;gap:var(--sp-4);flex-wrap:wrap;margin-bottom:var(--sp-6)">
        <!-- Status pills -->
        <div style="display:flex;gap:var(--sp-1);flex-wrap:wrap">
            <?php foreach (['all'=>'All', 'progress'=>'In Progress', 'not_started'=>'Not Started', 'completed'=>'Completed'] as $k=>$l): ?>
            <a href="?status=<?= $k ?>&sort=<?= e($sortFilter) ?><?= $search?"&q=".urlencode($search):'' ?>"
               style="padding:6px 14px;border-radius:999px;font-size:var(--text-sm);font-weight:700;text-decoration:none;transition:all .2s;<?= $statusFilter===$k?'background:var(--c-primary);color:#fff':'background:var(--c-surface-2);color:var(--c-ink-muted)' ?>">
                <?= $l ?>
            </a>
            <?php endforeach; ?>
        </div>
        <!-- Search -->
        <form method="GET" class="mycourses-search-form" style="display:flex;gap:var(--sp-2);margin-left:auto;flex-shrink:0">
            <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
            <input type="text" name="q" class="form-control" style="width:220px;max-width:100%;padding:8px 14px" placeholder="Search courses..." value="<?= e($search) ?>">
            <!-- Sort -->
            <select name="sort" class="form-control" style="padding:8px 12px" onchange="this.form.submit()">
                <option value="recent" <?= $sortFilter==='recent'?'selected':'' ?>>Recent</option>
                <option value="progress" <?= $sortFilter==='progress'?'selected':'' ?>>Progress</option>
                <option value="alpha" <?= $sortFilter==='alpha'?'selected':'' ?>>Alphabetical</option>
            </select>
        </form>
    </div>

    <!-- Course grid -->
    <?php if (empty($courses)): ?>
    <div style="text-align:center;padding:var(--sp-20);background:var(--c-surface);border-radius:var(--r-2xl);border:2px dashed var(--c-border)">
        <span class="material-icons-round" style="font-size:64px;color:var(--c-ink-faint);display:block;margin-bottom:16px">school</span>
        <h3 style="color:var(--c-ink-light);margin-bottom:8px">
            <?= $search ? '"'.e($search).'" — No results found' : 'No courses here yet' ?>
        </h3>
        <p style="color:var(--c-ink-muted)">Enroll in a new course and start learning.</p>
        <a href="<?= APP_URL ?>/courses.php" class="btn btn-primary" style="margin-top:16px">Explore Courses</a>
    </div>
    <?php else: ?>
    <div class="grid-3">
        <?php foreach ($courses as $ec):
            $pct   = (int)$ec['progress_pct'];
            $isDone = $pct === 100;
        ?>
        <div class="course-card" style="<?= $isDone ? 'border-color:var(--c-success)' : '' ?>">
            <a href="<?= APP_URL ?>/learn.php?course=<?= (int)$ec['id'] ?>" style="text-decoration:none">
                <div class="course-thumb">
                    <img src="<?= e(course_thumbnail_url($ec['thumbnail'], $ec['title_bn'] ?? 'Course')) ?>"
                         alt="" loading="lazy">
                    <?php if ($isDone): ?>
                    <div style="position:absolute;inset:0;background:rgba(21,190,139,.18);display:flex;align-items:center;justify-content:center">
                        <span class="material-icons-round" style="font-size:48px;color:var(--c-success)">check_circle</span>
                    </div>
                    <?php endif; ?>
                    <span class="course-badge" style="background:<?= e($ec['cat_color']) ?>"><?= e($ec['cat_name']) ?></span>
                    <?php if ($ec['exam_count'] > 0): ?>
                    <span class="course-badge" style="top:auto;bottom:10px;background:rgba(91, 33, 182,.85)">
                        <span class="material-icons-round" style="font-size:11px;vertical-align:middle">quiz</span>
                        <?= (int)$ec['exam_count'] ?> exams
                    </span>
                    <?php endif; ?>
                </div>
            </a>
            <div class="course-body">
                <a href="<?= APP_URL ?>/learn.php?course=<?= (int)$ec['id'] ?>" style="text-decoration:none">
                    <h3 class="course-title"><?= e($ec['title_bn']) ?></h3>
                </a>

                <!-- Progress bar -->
                <div style="margin-bottom:var(--sp-3)">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
                        <span style="font-size:var(--text-xs);color:var(--c-ink-muted)">Progress</span>
                        <span style="font-family:var(--font-latin);font-size:var(--text-xs);font-weight:800;color:<?= $isDone?'var(--c-success)':'var(--c-primary)' ?>"><?= $pct ?>%</span>
                    </div>
                    <div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $isDone?'var(--c-success)':'var(--c-primary)' ?>"></div></div>
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:4px">Enrolled: <?= date('d M Y', strtotime($ec['enrolled_at'])) ?></div>
                </div>
                <!-- Actions -->
                <div style="display:flex;gap:var(--sp-2);margin-top:auto">
                    <a href="<?= APP_URL ?>/learn.php?course=<?= (int)$ec['id'] ?>" class="btn btn-<?= $isDone?'success':'primary' ?> btn-sm" style="flex:1;justify-content:center">
                        <span class="material-icons-round"><?= $isDone?'replay':'play_arrow' ?></span>
                        <?= $isDone ? 'Watch Again' : ($pct > 0 ? 'Continue' : 'Start') ?>
                    </a>
                    <?php if ($isDone): ?>
                    <a href="<?= APP_URL ?>/certificates.php?course=<?= (int)$ec['id'] ?>" class="btn btn-ghost btn-sm" title="Certificate">
                        <span class="material-icons-round" style="font-size:16px">workspace_premium</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($ec['exam_count'] > 0): ?>
                    <a href="<?= APP_URL ?>/exams.php?course=<?= (int)$ec['id'] ?>" class="btn btn-ghost btn-sm" title="Exams">
                        <span class="material-icons-round" style="font-size:16px">quiz</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($pages > 1): ?>
    <div style="display:flex;justify-content:center;gap:var(--sp-2);margin-top:var(--sp-10);flex-wrap:wrap">
        <?php for ($i=1;$i<=$pages;$i++): ?>
        <a href="?status=<?= e($statusFilter) ?>&sort=<?= e($sortFilter) ?><?= $search?"&q=".urlencode($search):'' ?>&page=<?= $i ?>"
           style="width:40px;height:40px;display:flex;align-items:center;justify-content:center;border-radius:var(--r-md);font-weight:700;text-decoration:none;font-family:var(--font-latin);<?= $i===$page?'background:var(--c-primary);color:#fff':'background:var(--c-surface-2);color:var(--c-ink-muted)' ?>">
            <?= $i ?>
        </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
