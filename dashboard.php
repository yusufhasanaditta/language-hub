<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_login();

// Route each role to its own dashboard — students only see this page.
$__role = (int) ($_SESSION['user_role'] ?? 0);
if (in_array($__role, [ROLE_ADMIN, ROLE_OPS], true)) {
    redirect(APP_URL . '/admin/index.php');
}
if ($__role === ROLE_TEACHER) {
    redirect(APP_URL . '/teacher/dashboard.php');
}

$user = current_user();
$uid = (int) $user['id'];

// Enrolled courses with progress
$enrolledCourses = db_query("
    SELECT c.id, c.title_bn, c.thumbnail, c.total_lessons, c.type,
           cat.name_bn as cat_name, cat.color as cat_color,
           u.name as teacher_name,
           e.progress_pct, e.enrolled_at, e.status
    FROM enrollments e
    JOIN courses c ON c.id = e.course_id
    JOIN categories cat ON cat.id = c.category_id
    JOIN teacher_profiles tp ON tp.id = c.teacher_id
    JOIN users u ON u.id = tp.user_id
    WHERE e.user_id = ? AND e.status = 'active'
    ORDER BY e.enrolled_at DESC
    LIMIT 12
", [$uid]);

// Stats
$totalEnrolled = db_val("SELECT COUNT(*) FROM enrollments WHERE user_id=? AND status='active'", [$uid]);
$totalCompleted = db_val("SELECT COUNT(*) FROM enrollments WHERE user_id=? AND progress_pct=100", [$uid]);
$totalExams = db_val("SELECT COUNT(*) FROM exam_attempts WHERE user_id=? AND status='submitted'", [$uid]);
$avgScore = db_val("SELECT AVG(score) FROM exam_attempts WHERE user_id=? AND status IN ('submitted','graded')", [$uid]);

// Upcoming live sessions
$upcomingLive = db_query("
    SELECT ls.*, c.title_bn as course_title
    FROM live_sessions ls
    JOIN courses c ON c.id = ls.course_id
    JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
    WHERE ls.scheduled_at > NOW() AND ls.status = 'scheduled'
    ORDER BY ls.scheduled_at ASC
    LIMIT 3
", [$uid]);

// Recent exam attempts
$recentExams = db_query("
    SELECT ea.*, ex.title as exam_title
    FROM exam_attempts ea
    JOIN exams ex ON ex.id = ea.exam_id
    WHERE ea.user_id = ?
    ORDER BY ea.started_at DESC
    LIMIT 5
", [$uid]);

// Student Schedules across all enrolled courses
$studentSchedules = db_query("
    SELECT s.*, c.title_bn as course_title
    FROM schedules s
    JOIN enrollments e ON e.course_id = s.course_id
    JOIN courses c ON c.id = s.course_id
    WHERE e.user_id = ? AND e.status = 'active'
", [$uid]);

// Notifications
$notifications = db_query("
    SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 6
", [$uid]);

$page_title = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>

<div class="dashboard-layout">
    <!-- Main content -->
    <div class="dash-content">

        <!-- Welcome banner -->
        <div class="dash-welcome">
            <div>
                <div class="dash-welcome-title">
                    Good <?= (date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening')) ?>,
                    <?= e(explode(' ', $user['name'])[0] ?? $user['name']) ?>! 👋
                </div>
                <div class="dash-welcome-sub">
                    Learn something today. Every day is a new opportunity.
                </div>
            </div>
            <div style="position:relative;z-index:1">
                <img src="<?= e(avatar_url($user['avatar'], $user['name'])) ?>" alt=""
                    style="width:64px;height:64px;border-radius:50%;border:3px solid rgba(255,255,255,.3)">
            </div>
        </div>

        <!-- Stats cards -->
        <div class="stat-cards">
            <div class="stat-card">
                <div class="stat-card-icon primary">
                    <span class="material-icons-round">play_circle</span>
                </div>
                <div>
                    <div class="stat-card-value"><?= (int) $totalEnrolled ?></div>
                    <div class="stat-card-label">Enrolled Courses</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon success">
                    <span class="material-icons-round">check_circle</span>
                </div>
                <div>
                    <div class="stat-card-value"><?= (int) $totalCompleted ?></div>
                    <div class="stat-card-label">Completed Courses</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon accent">
                    <span class="material-icons-round">quiz</span>
                </div>
                <div>
                    <div class="stat-card-value"><?= (int) $totalExams ?></div>
                    <div class="stat-card-label">Exams Taken</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon warning">
                    <span class="material-icons-round">grade</span>
                </div>
                <div>
                    <div class="stat-card-value"><?= $avgScore ? number_format((float) $avgScore, 1) : '—' ?></div>
                    <div class="stat-card-label">Average Score</div>
                </div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 320px;gap:var(--sp-6)" class="dash-two-col">

            <!-- Left: courses + exams -->
            <div>
                <!-- My courses -->
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-5)">
                    <h3 style="font-size:var(--text-xl)">My Courses</h3>
                    <a href="<?= APP_URL ?>/my-courses.php" class="view-all-link">
                        View All <span class="material-icons-round">arrow_forward</span>
                    </a>
                </div>

                <?php if (empty($enrolledCourses)): ?>
                    <div
                        style="text-align:center;padding:var(--sp-12);background:var(--c-surface);border-radius:var(--r-lg);border:2px dashed var(--c-border)">
                        <span class="material-icons-round" style="font-size:48px;color:var(--c-ink-faint)">school</span>
                        <h4 style="margin:12px 0 8px;color:var(--c-ink-light)">You haven't enrolled in any courses yet</h4>
                        <p style="color:var(--c-ink-muted);margin-bottom:16px">Start today!</p>
                        <a href="<?= APP_URL ?>/courses.php" class="btn btn-primary">View Courses</a>
                    </div>
                <?php else: ?>
                    <div style="display:flex;flex-direction:column;gap:var(--sp-4)">
                        <?php foreach ($enrolledCourses as $ec): ?>
                            <a href="<?= APP_URL ?>/learn.php?course=<?= (int) $ec['id'] ?>" class="course-card-premium">
                                <img class="ccp-thumb"
                                    src="<?= e($ec['thumbnail'] ?: 'https://placehold.co/120x68/5B21B6/fff?text=Course') ?>"
                                    alt="">
                                <div class="ccp-body">
                                    <div
                                        style="font-size:var(--text-xs);font-weight:700;color:<?= e($ec['cat_color']) ?>;margin-bottom:4px">
                                        <?= e($ec['cat_name']) ?>
                                    </div>
                                    <h4 class="ccp-title">
                                        <?= e($ec['title_bn']) ?>
                                    </h4>
                                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-bottom:8px">
                                        <?= e($ec['teacher_name']) ?>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:8px">
                                        <div class="progress-bar" style="flex:1">
                                            <div class="progress-fill" style="width:<?= (int) $ec['progress_pct'] ?>%"></div>
                                        </div>
                                        <span
                                            style="font-family:var(--font-latin);font-size:var(--text-xs);font-weight:700;color:var(--c-primary);white-space:nowrap">
                                            <?= (int) $ec['progress_pct'] ?>%
                                        </span>
                                    </div>
                                </div>
                                <span class="material-icons-round ccp-icon">play_circle</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- My Routine / Calendar -->
                <div style="margin-top:var(--sp-8)">
                    <div
                        style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-5)">
                        <h3 style="font-size:var(--text-xl)">My Routine</h3>
                    </div>
                    <div class="card" style="padding:var(--sp-4); background:var(--c-surface);">
                        <div id="dashboardCalendar"></div>
                    </div>
                </div>

                <!-- Recent exams -->
                <?php if (!empty($recentExams)): ?>
                    <div style="margin-top:var(--sp-8)">
                        <div
                            style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-5)">
                            <h3 style="font-size:var(--text-xl)">Recent Exams</h3>
                            <a href="<?= APP_URL ?>/exams.php" class="view-all-link">View All <span
                                    class="material-icons-round">arrow_forward</span></a>
                        </div>
                        <div class="data-table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Exam Name</th>
                                        <th>Score</th>
                                        <th>Rank</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentExams as $ea): ?>
                                        <tr>
                                            <td><strong><?= e($ea['exam_title']) ?></strong></td>
                                            <td>
                                                <span
                                                    style="font-family:var(--font-latin);font-weight:700;color:var(--c-primary)">
                                                    <?= number_format((float) $ea['score'], 1) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($ea['rank']): ?>
                                                    <span
                                                        style="font-family:var(--font-latin);font-weight:700">#<?= (int) $ea['rank'] ?></span>
                                                <?php else: ?>—<?php endif; ?>
                                            </td>
                                            <td><?= date('d M', strtotime($ea['started_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right: notifications + upcoming live -->
            <div>
                <?php if (!empty($upcomingLive)): ?>
                    <div
                        style="background:linear-gradient(135deg,var(--c-accent),var(--c-accent-dark));border-radius:var(--r-lg);padding:var(--sp-5);color:var(--c-on-accent);margin-bottom:var(--sp-6)">
                        <div
                            style="font-size:var(--text-xs);font-weight:700;text-transform:uppercase;letter-spacing:.08em;opacity:.8;margin-bottom:var(--sp-3)">
                            Upcoming Live Classes</div>
                        <?php foreach ($upcomingLive as $ls): ?>
                            <div
                                style="margin-bottom:var(--sp-4);padding-bottom:var(--sp-4);border-bottom:1px solid rgba(255,255,255,.2)">
                                <div style="font-weight:700;margin-bottom:4px"><?= e($ls['title']) ?></div>
                                <div style="font-size:var(--text-xs);opacity:.8"><?= e($ls['course_title']) ?></div>
                                <div style="font-size:var(--text-sm);margin-top:6px;display:flex;align-items:center;gap:6px">
                                    <span class="material-icons-round" style="font-size:16px">schedule</span>
                                    <?= date('d M, g:i A', strtotime($ls['scheduled_at'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <a href="<?= APP_URL ?>/live.php" class="btn btn-white btn-sm btn-block" style="margin-top:4px">
                            Join Live
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Share Testimonial -->
                <div
                    style="background:var(--c-surface); border:1px solid var(--c-border); border-radius:var(--r-lg); padding:var(--sp-5); margin-bottom:var(--sp-6); text-align:center;">
                    <div style="font-size:var(--text-lg); font-weight:700; margin-bottom:4px;">Love Language Hub?</div>
                    <div style="font-size:var(--text-sm); color:var(--c-ink-muted); margin-bottom:12px;">Share your
                        experience and get featured on our homepage!</div>
                    <a href="<?= APP_URL ?>/submit_testimonial.php" class="btn btn-primary btn-sm btn-block">
                        <span class="material-icons-round" style="font-size:16px;">campaign</span> Share Testimonial
                    </a>
                </div>

                <!-- Notifications -->
                <div>
                    <div
                        style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-4)">
                        <h4>Notices</h4>
                        <a href="#" class="view-all-link" style="font-size:var(--text-xs)">View All</a>
                    </div>
                    <?php if (empty($notifications)): ?>
                        <div
                            style="text-align:center;padding:var(--sp-8);color:var(--c-ink-muted);font-size:var(--text-sm)">
                            <span class="material-icons-round"
                                style="font-size:36px;display:block;margin-bottom:8px">notifications_none</span>
                            No notices yet
                        </div>
                    <?php else: ?>
                        <div style="border:1px solid var(--c-border);border-radius:var(--r-lg);overflow:hidden">
                            <?php foreach ($notifications as $n): ?>
                                <div
                                    style="padding:var(--sp-4);border-bottom:1px solid var(--c-border);<?= !$n['read_at'] ? 'background:var(--c-primary-light)' : '' ?>">
                                    <div style="font-size:var(--text-sm);font-weight:600;color:var(--c-ink)">
                                        <?= e($n['title']) ?></div>
                                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:4px">
                                        <?= time_ago($n['created_at']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @media (max-width: 1024px) {
        .dash-two-col {
            grid-template-columns: 1fr !important;
        }
    }

    /* Premium Dashboard UI */
    .dash-welcome {
        background: linear-gradient(135deg, var(--c-primary), var(--c-primary-dark));
        border-radius: var(--r-xl);
        padding: var(--sp-8);
        color: white;
        position: relative;
        overflow: hidden;
        box-shadow: var(--shadow-primary);
        margin-bottom: var(--sp-8);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .dash-welcome::after {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 60%);
        pointer-events: none;
        transform: translate(25%, 25%);
    }

    .course-card-premium {
        background: var(--glass-bg);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid var(--glass-border);
        border-radius: var(--r-xl);
        padding: var(--sp-4);
        transition: var(--t-base);
        box-shadow: var(--shadow-card);
        display: flex;
        flex-direction: row;
        gap: var(--sp-4);
        align-items: center;
        text-decoration: none;
        max-width: 100%;
    }

    .course-card-premium:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
        border-color: rgba(91, 33, 182, 0.2);
    }

    .ccp-thumb {
        width: 120px;
        height: 68px;
        object-fit: cover;
        border-radius: var(--r-sm);
        flex-shrink: 0;
    }

    .ccp-body {
        flex: 1;
        min-width: 0;
    }

    .ccp-title {
        font-size: var(--text-sm);
        font-weight: 700;
        color: var(--c-ink);
        margin-bottom: 4px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .ccp-icon {
        color: var(--c-primary);
        align-self: center;
        flex-shrink: 0;
    }

    /* Mobile: stack thumbnail above text instead of a cramped horizontal row */
    @media (max-width: 520px) {
        .course-card-premium {
            flex-direction: column;
            align-items: stretch;
        }

        .ccp-thumb {
            width: 100%;
            height: auto;
            aspect-ratio: 16/9;
        }

        .ccp-icon {
            display: none;
        }
    }

    /* Customizing FullCalendar to match our rich aesthetic */
    .fc-theme-standard td,
    .fc-theme-standard th,
    .fc-theme-standard .fc-scrollgrid {
        border-color: var(--c-border);
    }

    .fc-col-header-cell {
        background: var(--c-surface-alt);
        padding: 8px 0 !important;
    }

    .fc-col-header-cell-cushion {
        color: var(--c-ink-muted);
        font-weight: 600;
        text-decoration: none !important;
    }

    .fc-daygrid-day-number {
        color: var(--c-ink);
        text-decoration: none !important;
        font-weight: 600;
        margin: 4px;
    }

    .fc-event {
        border-radius: 4px;
        border: none;
        padding: 2px 4px;
        font-size: 11px;
        font-weight: 600;
        cursor: pointer;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }

    .fc-event:hover {
        transform: translateY(-1px);
        box-shadow: var(--shadow-sm);
        filter: brightness(1.1);
    }

    .fc-button-primary {
        background: var(--c-primary) !important;
        border-color: var(--c-primary) !important;
        font-weight: 600 !important;
        text-transform: capitalize;
        border-radius: 6px !important;
    }

    .fc-button-primary:not(:disabled):active,
    .fc-button-primary:not(:disabled).fc-button-active {
        background: #4a2ec6 !important;
    }

    .fc-toolbar-title {
        font-size: 1.25rem !important;
        font-weight: 800 !important;
        color: var(--c-ink);
    }

    .fc-day-today {
        background-color: rgba(91, 33, 182, 0.05) !important;
    }

    /* Mobile: stack the FullCalendar toolbar (title + 2 button groups) instead of
   letting all 3 chunks fight for one row */
    @media (max-width: 640px) {
        #dashboardCalendar .fc-toolbar {
            flex-direction: column;
            align-items: stretch;
            gap: var(--sp-3);
        }

        #dashboardCalendar .fc-toolbar-chunk {
            display: flex;
            justify-content: center;
        }

        #dashboardCalendar .fc-toolbar-title {
            font-size: 1.05rem !important;
            text-align: center;
            order: -1;
        }
    }

    @media (max-width: 480px) {
        #dashboardCalendar .fc-button {
            padding: 5px 8px !important;
            font-size: 12px !important;
        }

        #dashboardCalendar .fc-toolbar-chunk:last-child .fc-button-group {
            width: 100%;
        }

        #dashboardCalendar .fc-toolbar-chunk:last-child .fc-button-group .fc-button {
            flex: 1;
        }
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const calendarEl = document.getElementById('dashboardCalendar');
        if (calendarEl) {
            const eventsData = [
                <?php foreach ($studentSchedules as $s):
                    $ts = strtotime($s['scheduled_at']);
                    $endTime = isset($s['end_time']) ? strtotime($s['end_time']) : $ts + 3600;
                    $color = match ($s['type']) {
                        'live_class' => '#5B21B6',
                        'exam' => '#F59E0B',
                        default => '#10B981'
                    };
                    ?>
                {
                        id: '<?= (int) $s['id'] ?>',
                        title: <?= json_encode($s['course_title'] . ': ' . $s['title']) ?>,
                        start: '<?= date('Y-m-d\TH:i:s', $ts) ?>',
                        end: '<?= date('Y-m-d\TH:i:s', $endTime) ?>',
                        backgroundColor: '<?= $color ?>',
                        borderColor: '<?= $color ?>',
                        extendedProps: {
                            description: <?= json_encode($s['description']) ?>,
                            type: <?= json_encode($s['type']) ?>,
                            courseTitle: <?= json_encode($s['course_title']) ?>
                        }
                    },
                <?php endforeach; ?>
            ];

            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: window.innerWidth < 768 ? 'listMonth' : 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,listWeek'
                },
                events: eventsData,
                height: 'auto',
                eventClick: function (info) {
                    const evt = info.event;
                    const props = evt.extendedProps;

                    let html = `
                    <div style="font-family:var(--font-sans);">
                        <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--c-ink-muted);margin-bottom:8px;">
                            ${props.type === 'live_class' ? 'Live Class' : (props.type === 'exam' ? 'Exam' : 'General')}
                        </div>
                        <h3 style="margin:0 0 4px 0;">${evt.title}</h3>
                        <div style="font-size:14px;color:var(--c-primary);font-weight:600;margin-bottom:12px;">${props.courseTitle}</div>
                        <div style="font-size:14px;color:var(--c-ink-light);margin-bottom:16px;">
                            <span class="material-icons-round" style="font-size:16px;vertical-align:middle;margin-right:4px">schedule</span>
                            ${evt.start.toLocaleString()} - ${evt.end ? evt.end.toLocaleTimeString() : ''}
                        </div>
                        ${props.description ? `<p style="font-size:14px;color:var(--c-ink);line-height:1.6">${props.description}</p>` : ''}
                    </div>
                `;

                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ html: html, showConfirmButton: true, confirmButtonText: 'Close' });
                    } else {
                        alert(evt.title + '\n' + evt.start.toLocaleString() + '\n\n' + (props.description || ''));
                    }
                }
            });

            calendar.render();
        }
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>