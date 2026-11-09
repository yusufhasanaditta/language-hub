<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_teacher();

$user = current_user();
$uid  = (int)$user['id'];

// Get teacher profile
$tp = db_row("SELECT * FROM teacher_profiles WHERE user_id=?", [$uid]);
if (!$tp) {
    // Auto-create teacher profile if missing
    $tpId = db_insert("INSERT INTO teacher_profiles (user_id, rating_avg, total_students) VALUES (?,0,0)", [$uid]);
    $tp = db_row("SELECT * FROM teacher_profiles WHERE id=?", [$tpId]);
}
$tpId = (int)$tp['id'];

// Stats (a course counts for every teacher assigned to it, not just the owner)
$totalCourses   = (int)db_val("SELECT COUNT(*) FROM courses WHERE (teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=courses.id AND ct.teacher_id=?)) AND status='published'", [$tpId, $tpId]);
$draftCourses   = (int)db_val("SELECT COUNT(*) FROM courses WHERE (teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=courses.id AND ct.teacher_id=?)) AND status='draft'", [$tpId, $tpId]);
$totalStudents  = (int)db_val("SELECT COUNT(DISTINCT e.user_id) FROM enrollments e JOIN courses c ON c.id=e.course_id WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)) AND e.status='active'", [$tpId, $tpId]);
$totalRevenue   = (float)db_val("SELECT COALESCE(SUM(oi.price),0) FROM order_items oi JOIN courses c ON c.id=oi.item_id AND oi.item_type='course' JOIN orders o ON o.id=oi.order_id WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)) AND o.status='paid'", [$tpId, $tpId]);
$pendingQnA     = (int)db_val("SELECT COUNT(*) FROM discussions d JOIN courses c ON c.id=d.course_id WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)) AND d.is_answer=0 AND d.parent_id IS NOT NULL AND d.is_deleted=0", [$tpId, $tpId]);
$avgRating      = (float)db_val("SELECT COALESCE(AVG(r.rating),0) FROM reviews r JOIN courses c ON c.id=r.course_id WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))", [$tpId, $tpId]);

// My courses with enrollment count
$myCourses = db_query("
    SELECT c.*,
           (SELECT COUNT(*) FROM enrollments e WHERE e.course_id=c.id AND e.status='active') as enroll_count,
           (SELECT COALESCE(AVG(rating),0) FROM reviews r WHERE r.course_id=c.id) as rating
    FROM courses c WHERE c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)
    ORDER BY c.created_at DESC LIMIT 6
", [$tpId, $tpId]);

// Upcoming live sessions
$upcomingLive = db_query("
    SELECT ls.*, c.title_bn as course_title
    FROM live_sessions ls
    JOIN courses c ON c.id=ls.course_id
    WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)) AND ls.scheduled_at > NOW() AND ls.status='scheduled'
    ORDER BY ls.scheduled_at ASC LIMIT 4
", [$tpId, $tpId]);

// Recent discussions (unanswered)
$recentQnA = db_query("
    SELECT d.*, u.name as student_name, u.avatar as student_avatar,
           c.title_bn as course_title, l.title as lesson_title
    FROM discussions d
    JOIN users u ON u.id=d.user_id
    JOIN courses c ON c.id=d.course_id
    LEFT JOIN lessons l ON l.id=d.lesson_id
    WHERE (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)) AND d.is_answer=0 AND d.parent_id IS NULL AND d.is_deleted=0
    ORDER BY d.created_at DESC LIMIT 5
", [$tpId, $tpId]);

// Recent enrollments
$recentStudents = db_query("
    SELECT u.name, u.avatar, u.phone, c.title_bn as course_title, e.enrolled_at
    FROM enrollments e
    JOIN users u ON u.id=e.user_id
    JOIN courses c ON c.id=e.course_id
    WHERE c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)
    ORDER BY e.enrolled_at DESC LIMIT 5
", [$tpId, $tpId]);

function time_ago(string $dt): string {
    $d = time() - strtotime($dt);
    if ($d < 60) return 'just now';
    if ($d < 3600) return intdiv($d,60).'m ago';
    if ($d < 86400) return intdiv($d,3600).'h ago';
    return intdiv($d,86400).'d ago';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teacher Dashboard — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@700;800&family=Hind+Siliguri:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<script>const APP_URL='<?= APP_URL ?>';const CSRF_TOKEN='<?= csrf_token() ?>';</script>
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="admin-content">
        <!-- Flash -->
        <?php foreach (get_flash() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:16px">
            <span class="material-icons-round"><?= $f['type']==='success'?'check_circle':'error' ?></span>
            <span><?= e($f['message']) ?></span>
        </div>
        <?php endforeach; ?>

        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-8)">
            <div>
                <h1 style="font-size:var(--text-2xl)">Good <?= date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening') ?>, <?= e(explode(' ', $user['name'])[0]) ?>! 👋</h1>
                <p style="color:var(--c-ink-muted);margin:0">Today is <?= date('d F Y') ?> — your classroom is ready.</p>
            </div>
            <div style="display:flex;gap:var(--sp-3)">
                <a href="<?= APP_URL ?>/teacher/courses.php?action=add" class="btn btn-primary btn-sm">
                    <span class="material-icons-round">add</span> New Course
                </a>
                <a href="<?= APP_URL ?>/teacher/live.php?action=schedule" class="btn btn-ghost btn-sm">
                    <span class="material-icons-round">live_tv</span> Schedule Live
                </a>
            </div>
        </div>

        <!-- Stat cards -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:var(--sp-4);margin-bottom:var(--sp-8)" class="teacher-stats">
            <?php $cards = [
                ['icon'=>'play_circle','color'=>'primary','value'=>$totalCourses,'label'=>'Published Courses','sub'=>$draftCourses.' drafts'],
                ['icon'=>'people','color'=>'success','value'=>number_format($totalStudents),'label'=>'Total Students','sub'=>'Active enrollments'],
                ['icon'=>'payments','color'=>'accent','value'=>number_format($totalRevenue,0),'label'=>'Total Earnings','sub'=>'From successful orders'],
                ['icon'=>'star','color'=>'warning','value'=>number_format($avgRating,1),'label'=>'Average Rating','sub'=>'Course reviews'],
            ]; foreach ($cards as $c): ?>
            <div class="stat-card">
                <div class="stat-card-icon <?= e($c['color']) ?>">
                    <span class="material-icons-round"><?= e($c['icon']) ?></span>
                </div>
                <div>
                    <div class="stat-card-value"><?= e($c['value']) ?></div>
                    <div class="stat-card-label"><?= e($c['label']) ?></div>
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:4px"><?= e($c['sub']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Two-column layout -->
        <div style="display:grid;grid-template-columns:1fr 340px;gap:var(--sp-6)" class="teacher-two-col">

            <!-- Left column -->
            <div style="display:flex;flex-direction:column;gap:var(--sp-6)">

                <!-- My Courses -->
                <div>
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-4)">
                        <h3>My Courses</h3>
                        <a href="<?= APP_URL ?>/teacher/courses.php" class="view-all-link">See all →</a>
                    </div>
                    <?php if (empty($myCourses)): ?>
                    <div style="text-align:center;padding:var(--sp-12);background:var(--c-surface);border-radius:var(--r-lg);border:2px dashed var(--c-border)">
                        <span class="material-icons-round" style="font-size:48px;color:var(--c-ink-faint)">add_circle</span>
                        <h4 style="margin:12px 0 8px;color:var(--c-ink-light)">No courses yet</h4>
                        <a href="<?= APP_URL ?>/teacher/courses.php?action=add" class="btn btn-primary" style="margin-top:8px">Create Your First Course</a>
                    </div>
                    <?php else: ?>
                    <div style="display:flex;flex-direction:column;gap:var(--sp-3)">
                        <?php foreach ($myCourses as $c): ?>
                        <div style="display:flex;gap:var(--sp-4);background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--r-lg);padding:var(--sp-4);align-items:center">
                            <img src="<?= e(course_thumbnail_url($c['thumbnail'], $c['title_bn'] ?? 'Course', '100x56')) ?>"
                                 alt="" style="width:100px;height:56px;object-fit:cover;border-radius:var(--r-sm);flex-shrink:0">
                            <div style="flex:1;min-width:0">
                                <div style="font-weight:700;font-size:var(--text-sm);margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($c['title_bn']) ?></div>
                                <div style="font-size:var(--text-xs);color:var(--c-ink-muted)">
                                    <span style="font-family:var(--font-latin);font-weight:700;color:var(--c-primary)"><?= (int)$c['enroll_count'] ?></span> students ·
                                    <span style="color:var(--c-warning)">★</span> <?= number_format((float)$c['rating'],1) ?>
                                </div>
                            </div>
                            <div style="display:flex;gap:4px;flex-shrink:0">
                                <span class="status-badge <?= $c['status']==='published'?'status-active':($c['status']==='draft'?'status-draft':'status-archived') ?>">
                                    <?= ['published'=>'Published','draft'=>'Draft','archived'=>'Archived'][$c['status']] ?>
                                </span>
                                <a href="<?= APP_URL ?>/teacher/courses.php?action=edit&id=<?= (int)$c['id'] ?>" class="btn btn-ghost btn-sm" title="Edit">
                                    <span class="material-icons-round" style="font-size:14px">edit</span>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Q&A -->
                <div>
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-4)">
                        <h3>Recent Questions
                            <?php if ($pendingQnA > 0): ?>
                            <span style="background:var(--c-accent);color:#fff;border-radius:999px;font-size:11px;padding:2px 8px;margin-left:6px;font-family:var(--font-latin)"><?= $pendingQnA ?></span>
                            <?php endif; ?>
                        </h3>
                        <a href="<?= APP_URL ?>/teacher/discussions.php" class="view-all-link">See all →</a>
                    </div>
                    <?php if (empty($recentQnA)): ?>
                    <div style="text-align:center;padding:var(--sp-8);color:var(--c-ink-muted);background:var(--c-surface);border-radius:var(--r-lg)">
                        <span class="material-icons-round" style="font-size:36px;display:block;margin-bottom:8px">forum</span>
                        No questions yet
                    </div>
                    <?php else: ?>
                    <div style="border:1px solid var(--c-border);border-radius:var(--r-lg);overflow:hidden">
                        <?php foreach ($recentQnA as $i => $d): ?>
                        <div style="padding:var(--sp-4);<?= $i > 0 ? 'border-top:1px solid var(--c-border)' : '' ?>">
                            <div style="display:flex;gap:var(--sp-3);align-items:flex-start">
                                <img src="<?= e(avatar_url($d['student_avatar'], $d['student_name'])) ?>"
                                     alt="" style="width:32px;height:32px;border-radius:50%;flex-shrink:0">
                                <div style="flex:1;min-width:0">
                                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-bottom:4px">
                                        <strong><?= e($d['student_name']) ?></strong> ·
                                        <?= e($d['course_title']) ?>
                                        <?php if ($d['lesson_title']): ?> · <?= e($d['lesson_title']) ?><?php endif; ?>
                                    </div>
                                    <div style="font-size:var(--text-sm);color:var(--c-ink);line-height:1.6">
                                        <?= e(mb_substr($d['body'], 0, 120)) ?><?= mb_strlen($d['body']) > 120 ? '...' : '' ?>
                                    </div>
                                    <div style="margin-top:8px;display:flex;gap:8px;align-items:center">
                                        <span style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= time_ago($d['created_at']) ?></span>
                                        <a href="<?= APP_URL ?>/teacher/discussions.php?id=<?= (int)$d['id'] ?>"
                                           class="btn btn-primary btn-sm" style="font-size:11px;padding:4px 10px">
                                            <span class="material-icons-round" style="font-size:13px">reply</span> Reply
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div><!-- /left -->

            <!-- Right column -->
            <div style="display:flex;flex-direction:column;gap:var(--sp-6)">

                <!-- Upcoming Live -->
                <div>
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-4)">
                        <h4>Upcoming Live Classes</h4>
                        <a href="<?= APP_URL ?>/teacher/live.php" class="view-all-link" style="font-size:var(--text-xs)">All →</a>
                    </div>
                    <?php if (empty($upcomingLive)): ?>
                    <div style="background:var(--c-surface);border-radius:var(--r-lg);padding:var(--sp-6);text-align:center;color:var(--c-ink-muted)">
                        <span class="material-icons-round" style="font-size:36px;display:block;margin-bottom:8px">live_tv</span>
                        <p style="font-size:var(--text-sm);margin-bottom:12px">No live classes yet</p>
                        <a href="<?= APP_URL ?>/teacher/live.php?action=schedule" class="btn btn-primary btn-sm">Schedule a Live Class</a>
                    </div>
                    <?php else: ?>
                    <div style="display:flex;flex-direction:column;gap:var(--sp-3)">
                        <?php foreach ($upcomingLive as $ls): ?>
                        <div style="background:linear-gradient(135deg,#5B21B6,#7C3AED);border-radius:var(--r-lg);padding:var(--sp-4);color:#fff">
                            <div style="font-weight:700;margin-bottom:4px"><?= e($ls['title']) ?></div>
                            <div style="font-size:var(--text-xs);opacity:.8;margin-bottom:8px"><?= e($ls['course_title']) ?></div>
                            <div style="font-size:var(--text-sm);display:flex;align-items:center;gap:6px">
                                <span class="material-icons-round" style="font-size:16px">schedule</span>
                                <?= date('d M, g:i A', strtotime($ls['scheduled_at'])) ?>
                            </div>
                            <a href="<?= APP_URL ?>/teacher/live.php?session=<?= (int)$ls['id'] ?>"
                               class="btn btn-white btn-sm btn-block" style="margin-top:12px">
                                <span class="material-icons-round" style="font-size:14px">live_tv</span> Start Class
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Students -->
                <div>
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-4)">
                        <h4>New Students</h4>
                        <a href="<?= APP_URL ?>/teacher/students.php" class="view-all-link" style="font-size:var(--text-xs)">All →</a>
                    </div>
                    <?php if (empty($recentStudents)): ?>
                    <div style="text-align:center;padding:var(--sp-6);color:var(--c-ink-muted);font-size:var(--text-sm)">
                        No students yet
                    </div>
                    <?php else: ?>
                    <div style="display:flex;flex-direction:column;gap:var(--sp-3)">
                        <?php foreach ($recentStudents as $s): ?>
                        <div style="display:flex;gap:var(--sp-3);align-items:center">
                            <img src="<?= e(avatar_url($s['avatar'], $s['name'])) ?>"
                                 alt="" style="width:36px;height:36px;border-radius:50%;flex-shrink:0">
                            <div style="flex:1;min-width:0">
                                <div style="font-weight:700;font-size:var(--text-sm)"><?= e($s['name']) ?></div>
                                <div style="font-size:var(--text-xs);color:var(--c-ink-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($s['course_title']) ?></div>
                            </div>
                            <span style="font-size:var(--text-xs);color:var(--c-ink-muted);white-space:nowrap"><?= time_ago($s['enrolled_at']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions -->
                <div style="background:var(--c-surface);border-radius:var(--r-lg);padding:var(--sp-5)">
                    <h4 style="margin-bottom:var(--sp-4)">Quick Actions</h4>
                    <div style="display:flex;flex-direction:column;gap:var(--sp-2)">
                        <?php $quickLinks = [
                            [APP_URL.'/teacher/courses.php?action=add','add','Create New Course'],
                            [APP_URL.'/teacher/live.php?action=schedule','live_tv','Schedule Live Class'],
                            [APP_URL.'/teacher/exams.php?action=add','quiz','Create New Exam'],
                            [APP_URL.'/teacher/profile.php','person','Update Profile'],
                        ];
                        if (!empty($_SESSION['original_admin']) || (int)($_SESSION['user_role'] ?? 0) === ROLE_ADMIN) {
                            $quickLinks[] = [APP_URL.'/teacher/analytics.php','bar_chart','View Performance'];
                        }
                        foreach ($quickLinks as $ql): ?>
                        <a href="<?= $ql[0] ?>" style="display:flex;align-items:center;gap:var(--sp-3);padding:var(--sp-3);border-radius:var(--r-md);color:var(--c-ink-light);font-size:var(--text-sm);font-weight:600;transition:all .15s;text-decoration:none"
                           onmouseover="this.style.background='var(--c-surface-2)'" onmouseout="this.style.background=''">
                            <span class="material-icons-round" style="color:var(--c-primary);font-size:18px"><?= $ql[1] ?></span>
                            <?= $ql[2] ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div><!-- /right -->

        </div>
    </div><!-- /admin-content -->
</div><!-- /admin-layout -->

<style>
@media (max-width: 1200px) { .teacher-stats { grid-template-columns: repeat(2,1fr) !important; } }
@media (max-width: 900px) { .teacher-two-col { grid-template-columns: 1fr !important; } }
@media (max-width: 600px) { .teacher-stats { grid-template-columns: 1fr !important; } }
</style>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>
