<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_login();

$user = current_user();
$uid = (int)$user['id'];

// Get all schedules for courses the student is enrolled in
$schedules = db_query("
    SELECT s.*, c.title_en, c.title_bn, c.slug, c.thumbnail
    FROM schedules s
    JOIN enrollments e ON e.course_id = s.course_id
    JOIN courses c ON c.id = s.course_id
    WHERE e.user_id = ?
    ORDER BY s.scheduled_at ASC
", [$uid]);

$_active = 'schedule.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>My Schedule — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@700;800&family=Hind+Siliguri:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<script>const APP_URL='<?= APP_URL ?>';const CSRF_TOKEN='<?= csrf_token() ?>';</script>
<style>
.schedule-card {
    background: var(--c-surface);
    border: 1px solid var(--c-border);
    border-radius: var(--r-xl);
    padding: var(--sp-5);
    display: flex;
    gap: var(--sp-5);
    align-items: center;
    transition: all .2s;
}
.schedule-card:hover {
    border-color: var(--c-primary);
    box-shadow: 0 8px 24px rgba(0,0,0,.15);
    transform: translateY(-2px);
}
.schedule-date {
    background: rgba(91, 33, 182,0.1);
    color: var(--c-primary);
    border-radius: var(--r-lg);
    padding: var(--sp-3);
    text-align: center;
    min-width: 80px;
}
.schedule-date .month { font-size: var(--text-xs); font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
.schedule-date .day { font-size: var(--text-2xl); font-weight: 800; line-height: 1; margin: 4px 0; font-family: var(--font-latin); }
.schedule-date .time { font-size: var(--text-xs); font-weight: 600; font-family: var(--font-latin); }
@media (max-width: 640px) {
    .schedule-card { flex-wrap: wrap; gap: var(--sp-3); padding: var(--sp-4); }
    .schedule-date { min-width: 64px; padding: var(--sp-2); }
    .schedule-info { width: 100%; order: 2; }
    .schedule-actions { flex-direction: row !important; align-items: stretch !important; width: 100%; order: 3; }
    .schedule-actions > a, .schedule-actions > button { flex: 1; justify-content: center; }
}
</style>
</head>
<body>
<?php include dirname(__DIR__) . '/includes/header.php'; ?>

<main class="container" style="padding-top:var(--sp-8);padding-bottom:var(--sp-16)">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-8)">
        <h1 style="font-size:var(--text-3xl)">My Schedule</h1>
    </div>

    <?php if (empty($schedules)): ?>
    <div style="text-align:center;padding:var(--sp-16);background:var(--c-surface);border-radius:var(--r-xl);border:2px dashed var(--c-border)">
        <span class="material-icons-round" style="font-size:64px;color:var(--c-ink-faint)">event_busy</span>
        <h2 style="margin:16px 0 8px">No Upcoming Events</h2>
        <p style="color:var(--c-ink-muted)">You don't have any scheduled classes or exams right now.</p>
    </div>
    <?php else: ?>
    
    <div style="display:grid;gap:var(--sp-4)">
        <?php foreach ($schedules as $s): 
            $ts = strtotime($s['scheduled_at']);
            $isPast = $ts < time();
            $typeColors = [
                'live_class' => '#15BE8B',
                'exam' => '#F4B400',
                'general' => '#5B21B6'
            ];
            $typeColor = $typeColors[$s['type']] ?? '#5B21B6';
        ?>
        <div class="schedule-card" style="<?= $isPast ? 'opacity:0.6' : '' ?>">
            <div class="schedule-date">
                <div class="month"><?= date('M', $ts) ?></div>
                <div class="day"><?= date('d', $ts) ?></div>
                <div class="time"><?= date('h:i A', $ts) ?></div>
            </div>
            
            <img src="<?= e(course_thumbnail_url($s['thumbnail'], $s['title_bn'] ?? 'Course', '120x68')) ?>" 
                 style="width:120px;height:68px;object-fit:cover;border-radius:var(--r-md);display:none" 
                 class="sm-block">
                 
            <div class="schedule-info" style="flex:1;min-width:0">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                    <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:<?= $typeColor ?>;background:<?= $typeColor ?>22;padding:2px 8px;border-radius:12px">
                        <?= ['live_class'=>'Live Class','exam'=>'Exam','general'=>'General'][$s['type']] ?>
                    </span>
                    <span style="font-size:var(--text-sm);color:var(--c-ink-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        <?= e($s['title_en'] ?: $s['title_bn']) ?>
                    </span>
                </div>
                <h3 style="font-size:var(--text-lg);margin-bottom:4px"><?= e($s['title']) ?></h3>
                <?php if ($s['description']): ?>
                <p style="font-size:var(--text-sm);color:var(--c-ink-muted);margin:0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                    <?= nl2br(e($s['description'])) ?>
                </p>
                <?php endif; ?>
            </div>
            
            <div class="schedule-actions" style="display:flex;flex-direction:column;gap:8px;align-items:flex-end">
                <a href="<?= APP_URL ?>/course.php?slug=<?= e($s['slug']) ?>" class="btn btn-primary btn-sm">
                    Go to Course
                </a>
                <?php if (!$isPast): ?>
                <button type="button" class="btn btn-ghost btn-sm remind-btn" data-schedule-id="<?= (int)$s['id'] ?>" style="font-size:12px">
                    <span class="material-icons-round" style="font-size:16px">notifications_none</span> Remind Me
                </button>
                <?php endif; ?>
                <a href="https://www.google.com/calendar/render?action=TEMPLATE&text=<?= urlencode($s['title'].' ('.$s['title_bn'].')') ?>&dates=<?= date('Ymd\THis\Z', $ts) ?>/<?= date('Ymd\THis\Z', $ts + 3600) ?>&details=<?= urlencode($s['description']) ?>&sf=true&output=xml" target="_blank" class="btn btn-ghost btn-sm" style="font-size:12px">
                    <span class="material-icons-round" style="font-size:16px">event</span> Add to Calendar
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <script>
    document.querySelectorAll('.remind-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            btn.disabled = true;
            fetch(APP_URL + '/api/schedule_remind.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                body: JSON.stringify({ schedule_id: btn.dataset.scheduleId, action: 'set' })
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    btn.innerHTML = '<span class="material-icons-round" style="font-size:16px">notifications_active</span> Reminder Set';
                    btn.classList.add('btn-primary');
                } else { btn.disabled = false; }
            }).catch(() => { btn.disabled = false; });
        });
    });
    </script>
    <?php endif; ?>
</main>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
</body>
</html>
