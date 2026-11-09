<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_login();

$uid = (int)current_user()['id'];
$cid = sanitize_int($_GET['course'] ?? 0);
if (!$cid) redirect(APP_URL.'/dashboard.php');

// Verify enrollment
$enrollment = db_row("SELECT * FROM enrollments WHERE user_id=? AND course_id=? AND status='active'", [$uid, $cid]);
if (!$enrollment) { flash('error','You are not enrolled in this course.'); redirect(APP_URL.'/dashboard.php'); }

ensure_course_fb_group_column();
ensure_classroom_subject_column();
$course = db_row("SELECT * FROM courses WHERE id=?", [$cid]);
$classrooms = db_query("SELECT * FROM virtual_classrooms WHERE course_id=? ORDER BY sort_order ASC, created_at ASC", [$cid]);
$classroomGroups = group_classrooms_by_subject($classrooms);
$latestClassroom = $classrooms ? end($classrooms) : null;
$latestMaterial = db_row("
    SELECT cm.*, vc.id AS classroom_id, vc.title AS classroom_title, vc.subject AS classroom_subject
    FROM classroom_materials cm
    JOIN virtual_classrooms vc ON vc.id = cm.classroom_id
    WHERE vc.course_id=? AND cm.kind IN ('video','live')
    ORDER BY cm.created_at DESC LIMIT 1
", [$cid]);
$schedules = db_query("SELECT * FROM schedules WHERE course_id=? AND scheduled_at >= NOW() ORDER BY scheduled_at ASC LIMIT 10", [$cid]);

// Fetch teacher details
$courseTeachers = db_query("
    SELECT u.name, u.avatar, tp.designation 
    FROM course_teachers ct
    JOIN teacher_profiles tp ON tp.id = ct.teacher_id
    JOIN users u ON u.id = tp.user_id
    WHERE ct.course_id=?
    ORDER BY ct.is_primary DESC, ct.sort_order ASC
", [$cid]);

if (!$courseTeachers && $course['teacher_id']) {
    $t = db_row("
        SELECT u.name, u.avatar, tp.designation 
        FROM users u 
        JOIN teacher_profiles tp ON u.id = tp.user_id 
        WHERE tp.id = ?
    ", [$course['teacher_id']]);
    if ($t) $courseTeachers[] = $t;
}

// Vibrant Gradients Array
$gradients = [
    'linear-gradient(135deg, #ff9a9e 0%, #fecfef 99%, #fecfef 100%)', // Pink
    'linear-gradient(120deg, #a1c4fd 0%, #c2e9fb 100%)', // Blue
    'linear-gradient(120deg, #d4fc79 0%, #96e6a1 100%)', // Green
    'linear-gradient(120deg, #fccb90 0%, #d57eeb 100%)', // Orange/Purple
    'linear-gradient(120deg, #e0c3fc 0%, #8ec5fc 100%)'  // Purple/Blue
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($course['title_en']?:$course['title_bn']) ?> — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<style>
body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8f9fe; }
.dashboard-header-wrap {
    background: linear-gradient(135deg, #f8f9fe 0%, #f1f5f9 100%);
    padding: 40px 0;
    margin-bottom: 40px;
    border-bottom: 1px solid rgba(0,0,0,0.02);
}
.dashboard-header { 
    background: #ffffff; 
    border-radius: 32px; 
    margin: 0 auto; 
    max-width: 1200px; 
    display: flex; 
    align-items: center; 
    padding: 32px;
    gap: 48px;
    box-shadow: 0 24px 48px rgba(0, 0, 0, 0.04), 0 4px 12px rgba(0,0,0,0.02); 
    border: 1px solid rgba(255,255,255,0.8);
}
.header-thumb-col { flex: 0 0 350px; }
.header-thumb-col img { width: 100%; height: 240px; object-fit: cover; border-radius: 24px; box-shadow: 0 12px 32px rgba(0,0,0,0.08); background: #f1f5f9; }
.header-content-col { flex: 1; display: flex; flex-direction: column; justify-content: center; }
.teacher-badge { display: flex; align-items: center; gap: 16px; margin-top: 24px; padding-top: 24px; border-top: 1px solid rgba(0,0,0,0.06); }
.teacher-badge img { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; box-shadow: 0 4px 12px rgba(0,0,0,0.1); border: 2px solid #fff; }

/* Study-area instructor grid — square boxes, row/column aligned */
.lc-instructor-grid {
    margin-top: 24px;
    padding-top: 24px;
    border-top: 1px solid rgba(0,0,0,0.06);
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(108px, 1fr));
    gap: 12px;
    max-width: 520px;
}
.lc-instructor-card {
    aspect-ratio: 1 / 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    text-align: center;
    text-decoration: none;
    padding: 12px 8px;
    background: #f8fafc;
    border: 1px solid rgba(0,0,0,0.06);
    border-radius: 16px;
    transition: all .25s ease;
}
.lc-instructor-card:hover { background: #f1f5f9; transform: translateY(-3px); box-shadow: 0 10px 24px rgba(0,0,0,.07); }
.lc-instructor-card img { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2px solid #fff; box-shadow: 0 2px 8px rgba(0,0,0,.12); }
.lc-instructor-label { font-size: 9px; text-transform: uppercase; letter-spacing: .4px; font-weight: 800; color: #94a3b8; }
.lc-instructor-name { font-size: 12.5px; font-weight: 800; color: #1e293b; line-height: 1.25; }
.lc-instructor-role { font-size: 10.5px; color: #64748b; font-weight: 600; }
@media (max-width: 560px) {
    .lc-instructor-grid { grid-template-columns: repeat(3, 1fr); gap: 8px; max-width: 100%; }
    .lc-instructor-card { padding: 8px 4px; border-radius: 12px; }
    .lc-instructor-card img { width: 34px; height: 34px; }
    .lc-instructor-name { font-size: 10.5px; }
    .lc-instructor-label { font-size: 7.5px; }
    .lc-instructor-role { font-size: 9px; }
}

/* Facebook group / routine CTA buttons */
.lc-cta-row { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
.lc-cta-fb { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 12px; background: #1877F2; color: #fff; font-weight: 700; font-size: 14px; text-decoration: none; transition: all .2s ease; }
.lc-cta-fb:hover { background: #145fc4; transform: translateY(-2px); }
.lc-cta-routine { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 12px; background: #f8fafc; color: #1e293b; font-weight: 700; font-size: 14px; text-decoration: none; border: 1px solid rgba(0,0,0,0.08); transition: all .2s ease; }
.lc-cta-routine:hover { background: #f1f5f9; transform: translateY(-2px); }
.course-stats { display: flex; gap: 24px; margin-top: 16px; margin-bottom: 16px; }
.stat-item { display: flex; align-items: center; gap: 8px; font-size: 15px; color: #64748b; font-weight: 700; background: #f8fafc; padding: 8px 16px; border-radius: 20px; }
.course-thumb { width: 200px; height: 120px; object-fit: cover; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.grid-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px; max-width: 1200px; margin: 0 auto 64px; padding: 0 var(--sp-6); }

.hero-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
.hero-card { display: block; text-decoration: none; border-radius: 24px; padding: 28px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 16px 32px rgba(0,0,0,0.12); transition: transform .2s ease, box-shadow .2s ease; }
.hero-card:hover { transform: translateY(-4px); box-shadow: 0 20px 40px rgba(0,0,0,0.18); }
.hero-card-video { background: linear-gradient(135deg, #5B21B6 0%, #7c4dff 100%); }
.hero-card-new { background: linear-gradient(135deg, #ff9800 0%, #ff5263 100%); }
.hero-eyebrow { display: inline-block; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; opacity: 0.85; margin-bottom: 10px; }
.hero-card h3 { margin: 0 0 6px; font-size: 22px; font-weight: 800; line-height: 1.3; }
.hero-card p { margin: 0 0 18px; font-size: 14px; opacity: 0.9; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.hero-btn { display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,0.2); padding: 8px 16px; border-radius: 20px; font-weight: 700; font-size: 13px; }
.badge-new { display: inline-block; background: #ff5263; color: #fff; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 10px; vertical-align: middle; margin-left: 4px; text-transform: uppercase; }

.classroom-card { 
    border-radius: 16px; 
    padding: 16px; 
    transition: all .3s ease; 
    display: flex; 
    flex-direction: column; 
    text-decoration: none; 
    color: #333; 
    background: #ffffff;
    border: 1px solid rgba(0,0,0,0.05);
    box-shadow: 0 4px 12px rgba(0,0,0,0.04);
}
.classroom-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(0,0,0,0.08); }
.classroom-card .thumb {
    width: 100%;
    aspect-ratio: 16/9;
    border-radius: 12px;
    object-fit: cover;
    margin-bottom: 16px;
    background: #f1f5f9;
}
.classroom-card h3 { margin: 0 0 8px; font-size: 18px; font-weight: 800; color: #1a1a2e; line-height: 1.3; }
.classroom-card p { margin: 0 0 16px; font-size: 14px; color: #64748b; font-weight: 600; line-height: 1.5; flex: 1; display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden; }
.classroom-card .card-footer { display: flex; align-items: center; justify-content: space-between; border-top: 1px solid rgba(0,0,0,0.05); padding-top: 12px; }

.schedule-list { background: #fff; border-radius: 24px; padding: 32px; box-shadow: 0 10px 40px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.05); }
.schedule-item { display: flex; align-items: center; gap: 24px; padding: 24px 0; border-bottom: 1px dashed rgba(0,0,0,0.1); }
.schedule-item:last-child { border-bottom: none; padding-bottom: 0; }
.schedule-date-box { background: linear-gradient(135deg, rgba(91, 33, 182,0.08) 0%, rgba(91, 33, 182,0.02) 100%); color: var(--c-primary); border-radius: 16px; padding: 16px; text-align: center; min-width: 100px; box-shadow: inset 0 2px 4px rgba(255,255,255,0.5); border: 1px solid rgba(91, 33, 182,0.1); }
.schedule-date-month { font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; }
.schedule-date-day { font-size: 36px; font-weight: 800; line-height: 1; margin: 4px 0; font-family: var(--font-latin); }
.schedule-date-time { font-size: 13px; font-weight: 700; color: #64748b; }
.schedule-type-badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 12px; }
.schedule-type-live_class { background: rgba(239,68,68,0.1); color: #ef4444; }
.schedule-type-exam { background: rgba(245,158,11,0.1); color: #f59e0b; }
.schedule-type-general { background: rgba(59,130,246,0.1); color: #3b82f6; }
.schedule-title { font-size: 22px; font-weight: 800; color: #1e293b; margin: 0 0 8px; letter-spacing: -0.3px; }
.schedule-desc { font-size: 15px; color: #64748b; margin: 0; line-height: 1.6; }
.remind-btn { background: #f8fafc; color: #475569; border: 2px solid #e2e8f0; border-radius: 14px; padding: 10px 20px; font-weight: 700; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; }
.remind-btn:hover { border-color: var(--c-primary); color: var(--c-primary); background: #fff; }
.remind-btn.btn-primary { background: var(--c-primary); color: #fff; border-color: var(--c-primary); }

/* Customizing FullCalendar for Student */
.fc-theme-standard td, .fc-theme-standard th, .fc-theme-standard .fc-scrollgrid { border-color: rgba(0,0,0,0.05); }
.fc-col-header-cell { background: #f8fafc; padding: 12px 0 !important; }
.fc-col-header-cell-cushion { color: #64748b; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; font-size: 12px; text-decoration: none !important; }
.fc-daygrid-day-number { color: #1e293b; text-decoration: none !important; font-weight: 700; margin: 8px; font-size: 14px; }
.fc-event { border-radius: 6px; border: none; padding: 4px 8px; font-size: 12px; font-weight: 700; cursor: pointer; transition: transform 0.15s ease, box-shadow 0.15s ease; }
.fc-event:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); filter: brightness(1.1); }
.fc-button-primary { background: var(--c-primary) !important; border-color: var(--c-primary) !important; font-weight: 700 !important; text-transform: capitalize; border-radius: 12px !important; box-shadow: 0 4px 12px rgba(91, 33, 182,0.2) !important; }
.fc-button-primary:not(:disabled):active, .fc-button-primary:not(:disabled).fc-button-active { background: #4a2ec6 !important; }
.fc-toolbar-title { font-size: 24px !important; font-weight: 800 !important; color: #1e293b; letter-spacing: -0.5px; }
.fc-day-today { background-color: rgba(91, 33, 182, 0.03) !important; }
.student-calendar-wrap { background: #fff; border-radius: 32px; padding: 32px; box-shadow: 0 10px 40px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.05); margin-bottom: 64px; }

@media (max-width: 768px) {
    .dashboard-header-wrap { padding: 20px 0; }
    .dashboard-header { flex-direction: column; padding: var(--sp-5, 20px); gap: var(--sp-4, 16px); text-align: center; border-radius: 20px; }
    .header-thumb-col { flex: 0 0 auto; width: 100%; max-width: 320px; margin: 0 auto; }
    .header-thumb-col img { height: auto; aspect-ratio: 4/3; }
    .header-content-col h1 { font-size: clamp(1.4rem, 6vw, 42px) !important; }
    .course-stats { flex-wrap: wrap; justify-content: center; margin-top: 12px; }
    .teacher-badge { justify-content: center; text-align: left; }
    .student-calendar-wrap { padding: var(--sp-4, 16px); border-radius: 20px; }
    #studentCalendar .fc-toolbar { flex-direction: column; align-items: stretch; gap: var(--sp-3, 12px); }
    #studentCalendar .fc-toolbar-chunk { display: flex; justify-content: center; }
    #studentCalendar .fc-toolbar-title { font-size: 1.1rem !important; text-align: center; order: -1; }
}
@media (max-width: 480px) {
    #studentCalendar .fc-button { padding: 5px 8px !important; font-size: 12px !important; }
    #studentCalendar .fc-toolbar-chunk:last-child { flex-wrap: wrap; }
    #studentCalendar .fc-toolbar-chunk:last-child .fc-button-group { flex: 1 1 auto; }
}
</style>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>
    
    <div class="dashboard-header-wrap">
        <div class="dashboard-header">
            <div class="header-thumb-col">
                <?php $courseThumbUrl = course_thumbnail_url($course['thumbnail'] ?? '', $course['title_bn'] ?? '', '800x600'); ?>
                <img src="<?= e($courseThumbUrl) ?>" alt="Course Thumbnail" onerror="this.onerror=null; this.src='https://placehold.co/800x600/e2e8f0/64748b?text=Course+Image';">
            </div>
            <div class="header-content-col">
                <div style="font-size:14px;text-transform:uppercase;letter-spacing:2px;color:var(--c-primary);margin-bottom:12px;font-weight:800; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                    <span><span class="material-icons-round" style="font-size:16px;vertical-align:-3px;margin-right:4px;">auto_stories</span> Study Area</span>
                    <span style="display:flex;gap:8px;">
                        <a href="<?= APP_URL ?>/course_exams.php?course=<?= $cid ?>" class="btn btn-ghost btn-sm" style="color:#64748b;"><span class="material-icons-round" style="font-size:18px">quiz</span> Course Exams</a>
                        <a href="<?= APP_URL ?>/dashboard.php" class="btn btn-ghost btn-sm" style="color:#64748b;"><span class="material-icons-round" style="font-size:18px">arrow_back</span> Dashboard</a>
                    </span>
                </div>
                <h1 style="margin:0;font-size:42px;line-height:1.2;margin-bottom:16px;font-weight:800;color:#1a1a2e;letter-spacing:-0.5px;"><?= e($course['title_en'] ?: $course['title_bn']) ?></h1>
                
                <div class="course-stats">
                    <div class="stat-item"><span class="material-icons-round" style="color:var(--c-primary);font-size:20px;">meeting_room</span> <?= count($classrooms) ?> Virtual Classrooms</div>
                    <div class="stat-item"><span class="material-icons-round" style="color:var(--c-secondary);font-size:20px;">calendar_month</span> <?= count($schedules) ?> Upcoming Events</div>
                </div>

                <?php $routineUrl = $course['routine_link'] ?: (!empty($course['routine_pdf']) ? APP_URL . '/' . ltrim($course['routine_pdf'], '/') : ''); ?>
                <?php if (!empty($course['fb_group_link']) || $routineUrl): ?>
                <div class="lc-cta-row">
                    <?php if (!empty($course['fb_group_link'])): ?>
                    <a href="<?= e($course['fb_group_link']) ?>" target="_blank" rel="noopener" class="lc-cta-fb">
                        <span class="material-icons-round" style="font-size:18px">groups</span> Join Facebook Private Group
                    </a>
                    <?php endif; ?>
                    <?php if ($routineUrl): ?>
                    <a href="<?= e($routineUrl) ?>" target="_blank" rel="noopener" class="lc-cta-routine">
                        <span class="material-icons-round" style="font-size:18px">calendar_month</span> View Routine
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($courseTeachers)): ?>
                <div class="lc-instructor-grid">
                    <?php foreach($courseTeachers as $teacher): ?>
                    <div class="lc-instructor-card">
                        <span class="lc-instructor-label">Instructor</span>
                        <img src="<?= e(avatar_url($teacher['avatar'] ?? null, $teacher['name'])) ?>" alt="">
                        <span class="lc-instructor-name"><?= e($teacher['name']) ?></span>
                        <?php if (!empty($teacher['designation'])): ?>
                        <span class="lc-instructor-role"><?= e($teacher['designation']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($latestMaterial || $latestClassroom): ?>
    <div style="max-width:1200px;margin:0 auto 40px;padding:0 var(--sp-6)">
        <div class="hero-row">
            <?php if ($latestMaterial): ?>
            <a href="<?= APP_URL ?>/student_classroom.php?id=<?= $latestMaterial['classroom_id'] ?>" class="hero-card hero-card-video">
                <span class="hero-eyebrow"><span class="material-icons-round" style="font-size:16px;vertical-align:-3px">play_circle</span> Latest Class<?= $latestMaterial['classroom_subject'] ? ' · '.e($latestMaterial['classroom_subject']) : '' ?></span>
                <h3><?= e($latestMaterial['title']) ?></h3>
                <p><?= e($latestMaterial['classroom_title']) ?></p>
                <span class="hero-btn">Watch Now <span class="material-icons-round" style="font-size:16px">arrow_forward</span></span>
            </a>
            <?php endif; ?>
            <?php if ($latestClassroom): ?>
            <a href="<?= APP_URL ?>/student_classroom.php?id=<?= $latestClassroom['id'] ?>" class="hero-card hero-card-new">
                <span class="hero-eyebrow"><span class="material-icons-round" style="font-size:16px;vertical-align:-3px">fiber_new</span> Newly Added<?= $latestClassroom['subject'] ? ' · '.e($latestClassroom['subject']) : '' ?></span>
                <h3><?= e($latestClassroom['title']) ?></h3>
                <p><?= e($latestClassroom['description']) ?: 'A new classroom section was just added.' ?></p>
                <span class="hero-btn">Explore <span class="material-icons-round" style="font-size:16px">arrow_forward</span></span>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div style="max-width:1200px;margin:0 auto 32px;padding:0 var(--sp-6)">
        <h2 style="font-weight:800;font-size:clamp(1.35rem,5vw,32px);margin:0;color:#1a1a2e">Your Virtual Classrooms</h2>
        <p style="color:#666;margin:8px 0 0;font-size:16px">Jump right into your interactive learning sessions, organized by subject.</p>
    </div>

    <?php if (!$classrooms): ?>
    <div style="max-width:1200px;margin:0 auto 64px;padding:0 var(--sp-6)">
        <div style="padding: 64px; text-align: center; color: #999; background: #fff; border-radius: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.05)">
            <span class="material-icons-round" style="font-size:64px;opacity:0.5;margin-bottom:16px">auto_awesome</span>
            <h3 style="margin:0;font-size:24px;color:#333">No classrooms yet</h3>
            <p style="margin:8px 0 0">The teacher hasn't created any virtual classrooms for this course.</p>
        </div>
    </div>
    <?php else: ?>
    <?php foreach ($classroomGroups as $subjectName => $group): ?>
    <div style="max-width:1200px;margin:0 auto 16px;padding:0 var(--sp-6)">
        <?php if (count($classroomGroups) > 1): ?>
        <h3 style="font-weight:800;font-size:20px;margin:0 0 16px;color:var(--c-primary);display:flex;align-items:center;gap:8px">
            <span class="material-icons-round" style="font-size:20px"><?= $subjectName==='General'?'folder':'menu_book' ?></span>
            <?= e($subjectName) ?>
        </h3>
        <?php endif; ?>
    </div>
    <div class="grid-cards" style="margin-bottom:40px">
        <?php foreach($group as $cr): ?>
        <a href="<?= APP_URL ?>/student_classroom.php?id=<?= $cr['id'] ?>" class="classroom-card">
            <?php
                $thumbUrl = course_thumbnail_url($cr['thumbnail'] ?? '', 'Classroom', '400x225', 'e2e8f0');
            ?>
            <img src="<?= e($thumbUrl) ?>" class="thumb" alt="Thumbnail">
            <h3><?= e($cr['title']) ?><?php if ($latestClassroom && $cr['id']==$latestClassroom['id']): ?> <span class="badge-new">New</span><?php endif; ?></h3>
            <p><?= e($cr['description']) ?: '<span style="opacity:0.5">Ready to learn?</span>' ?></p>
            <div class="card-footer">
                <span style="font-size:13px;font-weight:700;color:rgba(0,0,0,0.5)">
                    <span class="material-icons-round" style="font-size:16px;vertical-align:-4px">event</span>
                    <?= date('M j, Y', strtotime($cr['created_at'])) ?>
                </span>
                <span style="background:rgba(91, 33, 182, 0.1);color:var(--c-primary);padding:6px 16px;border-radius:20px;font-size:13px;font-weight:700">Enter</span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    </div>

    <?php if ($schedules): ?>
    <div style="max-width:1200px;margin:0 auto 32px;padding:0 var(--sp-6)">
        <h2 style="font-weight:800;font-size:clamp(1.35rem,5.5vw,36px);margin:0;color:#1a1a2e;letter-spacing:-0.5px;">Course Calendar</h2>
        <p style="color:#64748b;margin:8px 0 0;font-size:16px;font-weight:600;">View your upcoming live classes and exams.</p>
    </div>
    <div style="max-width:1200px;margin:0 auto 64px;padding:0 var(--sp-6);">
        <div class="student-calendar-wrap">
            <div id="studentCalendar"></div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const calendarEl = document.getElementById('studentCalendar');
        if (calendarEl) {
            const eventsData = [
                <?php foreach ($schedules as $s): 
                    $ts = strtotime($s['scheduled_at']);
                    $endTime = isset($s['end_time']) ? strtotime($s['end_time']) : $ts + 3600;
                    $color = match($s['type']) {
                        'live_class' => '#ef4444', // Red
                        'exam' => '#f59e0b', // Orange
                        default => '#3b82f6' // Blue
                    };
                ?>
                {
                    id: '<?= (int)$s['id'] ?>',
                    title: <?= json_encode($s['title']) ?>,
                    start: '<?= date('Y-m-d\TH:i:s', $ts) ?>',
                    end: '<?= date('Y-m-d\TH:i:s', $endTime) ?>',
                    backgroundColor: '<?= $color ?>',
                    borderColor: '<?= $color ?>',
                    extendedProps: {
                        description: <?= json_encode($s['description']) ?>,
                        type: <?= json_encode($s['type']) ?>
                    }
                },
                <?php endforeach; ?>
            ];

            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,listWeek'
                },
                events: eventsData,
                height: 'auto',
                editable: false, // Students cannot drag and drop
                eventClick: function(info) {
                    let desc = info.event.extendedProps.description ? `\n\nDetails:\n${info.event.extendedProps.description}` : '';
                    alert(`Event: ${info.event.title}\nTime: ${info.event.start.toLocaleString()}${desc}`);
                }
            });
            calendar.render();
        }
    });
    </script>
    <?php endif; ?>

    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>