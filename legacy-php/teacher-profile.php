<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();

$slug   = sanitize_string($_GET['slug'] ?? '', 100);
$tid    = sanitize_int($_GET['id'] ?? 0);
if (!$slug && !$tid) { redirect(APP_URL . '/courses.php'); }

// Load teacher by slug (username) or id
$teacher = $slug
    ? db_row("SELECT u.*, tp.* FROM teacher_profiles tp JOIN users u ON u.id=tp.user_id WHERE u.username=?", [$slug])
    : db_row("SELECT u.*, tp.* FROM teacher_profiles tp JOIN users u ON u.id=tp.user_id WHERE tp.id=?", [$tid]);

if (!$teacher || !in_array((int)$teacher['role_id'], [ROLE_TEACHER, ROLE_TA, ROLE_ADMIN])) {
    redirect(APP_URL . '/courses.php');
}

$tpId = (int)($teacher['id'] ?? 0); // teacher_profiles.id

// Stats (a course counts here whether this teacher is the primary owner or a co-teacher)
$totalCourses  = (int)db_val("SELECT COUNT(*) FROM courses WHERE (teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=courses.id AND ct.teacher_id=?)) AND status='published'", [$tpId, $tpId]);
$totalStudents = (int)db_val("SELECT COUNT(DISTINCT e.user_id) FROM enrollments e JOIN courses c ON c.id=e.course_id WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))", [$tpId, $tpId]);
$totalReviews  = (int)db_val("SELECT COUNT(*) FROM reviews r JOIN courses c ON c.id=r.course_id WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))", [$tpId, $tpId]);
$avgRating     = (float)db_val("SELECT COALESCE(AVG(r.rating),0) FROM reviews r JOIN courses c ON c.id=r.course_id WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))", [$tpId, $tpId]);

// Published courses
$courses = db_query("
    SELECT c.*, cat.name_bn as cat_name, cat.color as cat_color,
           (SELECT COUNT(*) FROM enrollments e WHERE e.course_id=c.id) as enrolled_count,
           (SELECT COALESCE(AVG(r.rating),0) FROM reviews r WHERE r.course_id=c.id) as rating_avg,
           (SELECT COUNT(*) FROM reviews r WHERE r.course_id=c.id) as review_count
    FROM courses c JOIN categories cat ON cat.id=c.category_id
    WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)) AND c.status='published'
    ORDER BY c.is_featured DESC, c.enrolled_count DESC
", [$tpId, $tpId]);

// Recent reviews about this teacher
$reviews = db_query("
    SELECT r.rating, r.body as comment, r.created_at, u.name as reviewer, u.avatar as reviewer_avatar,
           c.title_bn as course_title
    FROM reviews r
    JOIN courses c ON c.id=r.course_id
    JOIN users u ON u.id=r.user_id
    WHERE c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)
    ORDER BY r.created_at DESC LIMIT 8
", [$tpId, $tpId]);

// Rating distribution
$ratingDist = [];
for ($i=5;$i>=1;$i--) {
    $cnt = (int)db_val("SELECT COUNT(*) FROM reviews r JOIN courses c ON c.id=r.course_id WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)) AND r.rating=?", [$tpId, $tpId, $i]);
    $ratingDist[$i] = $cnt;
}

// Education background
$educations = db_query("SELECT * FROM teacher_education WHERE teacher_profile_id=? ORDER BY year_to DESC, sort_order ASC", [$tpId]);

// Check if logged-in student is enrolled in any of this teacher's courses
$enrolledCourseForMsg = null;
if (is_logged_in() && !is_teacher()) {
    $curUid = (int)current_user()['id'];
    $enrolledCourseForMsg = db_row("
        SELECT c.id, c.title_bn FROM courses c
        JOIN enrollments e ON e.course_id=c.id
        WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)) AND e.user_id=? AND e.status='active'
        LIMIT 1
    ", [$tpId, $tpId, $curUid]);
}

$teacherName = e($teacher['name']);
$page_title  = $teacherName . ' — Teacher Profile';
$page_desc   = mb_substr($teacher['bio'] ?? '', 0, 160, 'UTF-8');
$teacherAvatarUrl = avatar_url($teacher['avatar'] ?? null, $teacher['name'] ?? 'T');
if (is_own_asset_url($teacherAvatarUrl)) { $page_image = $teacherAvatarUrl; }

$extra_css = '<style>
.teacher-hero{background:linear-gradient(135deg,#080B1E 0%,#1A1040 60%,#1D0B50 100%);padding:var(--sp-16) 0 var(--sp-12);position:relative;overflow:hidden}
.teacher-hero::before{content:"";position:absolute;top:-30%;right:-10%;width:600px;height:600px;background:radial-gradient(circle,rgba(91, 33, 182,.4) 0%,transparent 65%);pointer-events:none}
.teacher-hero::after{content:"";position:absolute;bottom:-20%;left:-5%;width:400px;height:400px;background:radial-gradient(circle,rgba(244,114,182,.2) 0%,transparent 65%);pointer-events:none}
.teacher-avatar-hero{width:120px;height:120px;border-radius:50%;border:5px solid rgba(255,255,255,.25);object-fit:cover;box-shadow:0 0 0 4px rgba(91, 33, 182,.4),0 12px 40px rgba(0,0,0,.4)}
.expertise-chip{display:inline-block;padding:4px 14px;border-radius:999px;background:rgba(255,255,255,.1);color:rgba(255,255,255,.8);font-size:var(--text-xs);font-weight:700;border:1px solid rgba(255,255,255,.15);margin:3px}
.stat-hero-card{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:var(--r-xl);padding:var(--sp-5) var(--sp-6);text-align:center;backdrop-filter:blur(10px)}
.rating-bar-row{display:flex;align-items:center;gap:var(--sp-3);margin-bottom:6px}
.rating-bar-track{flex:1;height:8px;background:var(--c-surface-2);border-radius:999px;overflow:hidden}
.rating-bar-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,#F59E0B,#FBBF24)}
.review-card{background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--r-xl);padding:var(--sp-5);transition:box-shadow .2s}
.review-card:hover{box-shadow:var(--shadow-md)}
</style>';
include __DIR__ . '/includes/header.php';
?>

<!-- Hero -->
<div class="teacher-hero">
    <div class="container" style="position:relative;z-index:1">
        <div style="display:flex;gap:var(--sp-8);align-items:flex-start;flex-wrap:wrap">
            <img src="<?= e(avatar_url($teacher['avatar'],$teacher['name'])) ?>"
                 alt="<?= $teacherName ?>" class="teacher-avatar-hero">
            <div style="flex:1;min-width:240px">
                <div style="font-size:var(--text-xs);font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.45);margin-bottom:var(--sp-2)">Teacher</div>
                <h1 style="color:#fff;font-size:clamp(1.75rem,4vw,2.75rem);margin-bottom:8px"><?= $teacherName ?></h1>
                <?php if (!empty($teacher['headline'])): ?>
                <div style="color:rgba(255,255,255,.7);font-size:var(--text-base);margin-bottom:var(--sp-4)"><?= e($teacher['headline']) ?></div>
                <?php endif; ?>
                <?php if (!empty($teacher['expertise'])): ?>
                <div style="margin-bottom:var(--sp-5)">
                    <?php foreach (explode(',', $teacher['expertise']) as $exp): ?>
                    <?php if (trim($exp)): ?><span class="expertise-chip"><?= e(trim($exp)) ?></span><?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <!-- Social links -->
                <div style="display:flex;gap:var(--sp-3);flex-wrap:wrap">
                    <?php if (!empty($teacher['fb_url'])): ?>
                    <a href="<?= e($teacher['fb_url']) ?>" target="_blank" rel="noopener"
                       style="display:inline-flex;align-items:center;gap:6px;background:rgba(24,119,242,.2);border:1px solid rgba(24,119,242,.4);color:#74A7FF;padding:8px 16px;border-radius:var(--r-md);font-size:var(--text-sm);font-weight:700;text-decoration:none">
                        Facebook
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($teacher['yt_url'])): ?>
                    <a href="<?= e($teacher['yt_url']) ?>" target="_blank" rel="noopener"
                       style="display:inline-flex;align-items:center;gap:6px;background:rgba(255,0,0,.15);border:1px solid rgba(255,0,0,.3);color:#FF8A8A;padding:8px 16px;border-radius:var(--r-md);font-size:var(--text-sm);font-weight:700;text-decoration:none">
                        YouTube
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($teacher['website'])): ?>
                    <a href="<?= e($teacher['website']) ?>" target="_blank" rel="noopener"
                       style="display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);color:rgba(255,255,255,.8);padding:8px 16px;border-radius:var(--r-md);font-size:var(--text-sm);font-weight:700;text-decoration:none">
                        <span class="material-icons-round" style="font-size:16px">language</span> Website
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Stat cards -->
        <div style="display:flex;gap:var(--sp-4);flex-wrap:wrap;margin-top:var(--sp-8)">
            <?php foreach ([
                ['school', number_format($totalCourses), 'Courses'],
                ['people', number_format($totalStudents), 'Students'],
                ['star', number_format($avgRating,1), 'Rating'],
                ['rate_review', number_format($totalReviews), 'Reviews'],
            ] as [$icon, $val, $lab]): ?>
            <div class="stat-hero-card">
                <span class="material-icons-round" style="font-size:24px;color:rgba(255,255,255,.4);display:block;margin-bottom:4px"><?= $icon ?></span>
                <div style="font-family:var(--font-latin);font-size:var(--text-2xl);font-weight:800;color:#fff"><?= $val ?></div>
                <div style="font-size:var(--text-xs);color:rgba(255,255,255,.5)"><?= $lab ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Main content -->
<div class="container" style="padding-top:var(--sp-10);padding-bottom:var(--sp-16)">
    <div style="display:grid;grid-template-columns:1fr 320px;gap:var(--sp-8)" class="teacher-profile-grid">
        <!-- Left -->
        <div>
            <!-- Bio -->
            <?php if (!empty($teacher['bio'])): ?>
            <div class="card" style="padding:var(--sp-6);margin-bottom:var(--sp-6)">
                <h3 style="margin-bottom:var(--sp-4)">About</h3>
                <div style="color:var(--c-ink-light);line-height:1.9;font-size:var(--text-base)"><?= nl2br(e($teacher['bio'])) ?></div>
            </div>
            <?php endif; ?>

            <!-- Education -->
            <?php if (!empty($educations)): ?>
            <div class="card" style="padding:var(--sp-6);margin-bottom:var(--sp-6)">
                <h3 style="margin-bottom:var(--sp-5)">Education</h3>
                <div style="display:flex;flex-direction:column;gap:0">
                    <?php foreach ($educations as $edu): ?>
                    <div style="display:flex;gap:var(--sp-4);padding:var(--sp-4) 0;border-bottom:1px solid var(--c-border)">
                        <div style="width:44px;height:44px;background:var(--c-primary-light);border-radius:var(--r-md);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                            <span class="material-icons-round" style="font-size:20px;color:var(--c-primary)">school</span>
                        </div>
                        <div style="flex:1">
                            <div style="font-weight:700"><?= e($edu['degree']) ?></div>
                            <div style="color:var(--c-ink-muted);font-size:var(--text-sm)"><?= e($edu['institution']) ?></div>
                            <?php if ($edu['year_from'] || $edu['year_to']): ?>
                            <div style="font-size:11px;color:var(--c-ink-faint);font-family:var(--font-latin);margin-top:2px">
                                <?= $edu['year_from'] ?: '' ?><?= ($edu['year_from'] && $edu['year_to']) ? ' — ' : '' ?><?= $edu['year_to'] ?: '' ?>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($edu['description'])): ?>
                            <div style="font-size:var(--text-sm);color:var(--c-ink-muted);margin-top:4px"><?= e($edu['description']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Courses -->
            <div class="card" style="padding:var(--sp-6);margin-bottom:var(--sp-6)">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-5)">
                    <h3>Courses <span style="color:var(--c-ink-muted);font-family:var(--font-latin)">(<?= $totalCourses ?>)</span></h3>
                </div>
                <?php if (empty($courses)): ?>
                <p style="color:var(--c-ink-muted);text-align:center;padding:var(--sp-8)">No courses published yet.</p>
                <?php else: ?>
                <div class="grid-3">
                    <?php foreach ($courses as $course):
                        $isFree = (int)($course['is_free'] ?? 0);
                        $hasDiscount = !$isFree && isset($course['sale_price']) && $course['sale_price'] > 0 && $course['sale_price'] < $course['price'];
                        $displayPrice = $hasDiscount ? $course['sale_price'] : $course['price'];
                        $course['teacher_name'] = $teacher['name'];
                        $course['teacher_avatar'] = $teacher['avatar'];
                    ?>
                    <?php include __DIR__ . '/includes/course_card.php'; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Reviews -->
            <?php if (!empty($reviews)): ?>
            <div class="card" style="padding:var(--sp-6)">
                <h3 style="margin-bottom:var(--sp-6)">Student Reviews</h3>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)" class="reviews-grid">
                    <?php foreach ($reviews as $rv):
                        $stars = (int)$rv['rating'];
                    ?>
                    <div class="review-card">
                        <div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:var(--sp-3)">
                            <img src="<?= e(avatar_url($rv['reviewer_avatar'],$rv['reviewer'])) ?>"
                                 style="width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0">
                            <div style="min-width:0">
                                <div style="font-weight:700;font-size:var(--text-sm);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($rv['reviewer']) ?></div>
                                <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= e(mb_substr($rv['course_title'],0,30,'UTF-8')) ?></div>
                            </div>
                        </div>
                        <div style="color:#F59E0B;font-size:var(--text-sm);margin-bottom:var(--sp-2)">
                            <?= str_repeat('★',$stars).str_repeat('☆',5-$stars) ?>
                        </div>
                        <?php if (!empty($rv['comment'])): ?>
                        <p style="font-size:var(--text-sm);color:var(--c-ink-light);line-height:1.7;margin:0"><?= e(mb_substr($rv['comment'],0,200,'UTF-8')) ?></p>
                        <?php endif; ?>
                        <div style="font-size:var(--text-xs);color:var(--c-ink-faint);margin-top:var(--sp-2)"><?= date('d M Y', strtotime($rv['created_at'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right sidebar -->
        <div>
            <!-- Rating breakdown -->
            <?php if ($totalReviews > 0): ?>
            <div class="card" style="padding:var(--sp-5);margin-bottom:var(--sp-5)">
                <h4 style="margin-bottom:var(--sp-4)">Rating Breakdown</h4>
                <div style="display:flex;align-items:center;gap:var(--sp-4);margin-bottom:var(--sp-4)">
                    <div style="text-align:center">
                        <div style="font-family:var(--font-latin);font-size:3rem;font-weight:800;color:var(--c-ink);line-height:1"><?= number_format($avgRating,1) ?></div>
                        <div style="color:#F59E0B;font-size:var(--text-base)">
                            <?= str_repeat('★',round($avgRating)).str_repeat('☆',5-round($avgRating)) ?>
                        </div>
                        <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:2px"><?= number_format($totalReviews) ?> Reviews</div>
                    </div>
                    <div style="flex:1">
                        <?php for ($i=5;$i>=1;$i--):
                            $cnt = $ratingDist[$i];
                            $pct = $totalReviews > 0 ? round($cnt/$totalReviews*100) : 0;
                        ?>
                        <div class="rating-bar-row">
                            <span style="font-family:var(--font-latin);font-size:var(--text-xs);color:var(--c-ink-muted);width:8px"><?= $i ?></span>
                            <span style="color:#F59E0B;font-size:10px">★</span>
                            <div class="rating-bar-track"><div class="rating-bar-fill" style="width:<?= $pct ?>%"></div></div>
                            <span style="font-size:var(--text-xs);color:var(--c-ink-muted);width:28px;text-align:right;font-family:var(--font-latin)"><?= $cnt ?></span>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Quick stats -->
            <div class="card" style="padding:var(--sp-5);margin-bottom:var(--sp-5)">
                <h4 style="margin-bottom:var(--sp-4)">Teacher Statistics</h4>
                <?php $qStats = [
                    ['school','Total Courses', $totalCourses],
                    ['people','Total Students', number_format($totalStudents)],
                    ['star','Average Rating', number_format($avgRating,1).' / 5.0'],
                    ['rate_review','Total Reviews', number_format($totalReviews)],
                ]; foreach ($qStats as [$icon,$lab,$val]): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;padding:var(--sp-2) 0;border-bottom:1px solid var(--c-border)">
                    <div style="display:flex;align-items:center;gap:var(--sp-2);font-size:var(--text-sm);color:var(--c-ink-muted)">
                        <span class="material-icons-round" style="font-size:16px;color:var(--c-primary)"><?= $icon ?></span>
                        <?= $lab ?>
                    </div>
                    <span style="font-family:var(--font-latin);font-weight:700;font-size:var(--text-sm)"><?= $val ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- CTA -->
            <div class="card" style="padding:var(--sp-5);background:linear-gradient(135deg,var(--c-primary),#7C3AED);border:none;color:#fff">
                <h4 style="color:#fff;margin-bottom:8px">Take a Course with <?= $teacherName ?></h4>
                <p style="font-size:var(--text-sm);color:rgba(255,255,255,.8);margin-bottom:var(--sp-4)">
                    <?= number_format($totalStudents) ?> students are already learning.
                </p>
                <a href="<?= APP_URL ?>/courses.php?teacher=<?= (int)$tpId ?>" class="btn btn-white btn-sm btn-block">View All Courses</a>
            </div>

            <?php if ($enrolledCourseForMsg): ?>
            <!-- Message teacher (enrolled students only) -->
            <div class="card" style="padding:var(--sp-5);margin-top:var(--sp-4)">
                <h4 style="margin-bottom:var(--sp-2)">Ask the Teacher</h4>
                <p style="font-size:var(--text-sm);color:var(--c-ink-muted);margin-bottom:var(--sp-4)">
                    You are enrolled in <?= e($enrolledCourseForMsg['title_bn']) ?>.
                </p>
                <a href="<?= APP_URL ?>/messages.php?course=<?= (int)$enrolledCourseForMsg['id'] ?>&with=<?= (int)$teacher['user_id'] ?>"
                   class="btn btn-primary btn-sm btn-block">
                    <span class="material-icons-round">chat</span> Send Message
                </a>
            </div>
            <?php elseif (!is_logged_in()): ?>
            <div class="card" style="padding:var(--sp-5);margin-top:var(--sp-4);text-align:center">
                <p style="font-size:var(--text-sm);color:var(--c-ink-muted);margin-bottom:var(--sp-3)">Enroll in a course to message this teacher.</p>
                <a href="<?= APP_URL ?>/login.php" class="btn btn-outline btn-sm btn-block">Log In</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
@media(max-width:1024px){.teacher-profile-grid{grid-template-columns:1fr !important}}
@media(max-width:768px){.reviews-grid{grid-template-columns:1fr !important}}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
