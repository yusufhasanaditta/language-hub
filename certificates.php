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

$completedEnrollments = db_query("
    SELECT e.id as enrollment_id, e.progress_pct, e.completed_at, e.enrolled_at,
           c.id as course_id, c.title_bn, c.slug, c.thumbnail, c.type, c.total_lessons,
           u2.name as teacher_name, u2.avatar as teacher_avatar,
           cert.serial, cert.issued_at
    FROM enrollments e
    JOIN courses c ON c.id = e.course_id
    JOIN teacher_profiles tp ON tp.id = c.teacher_id
    JOIN users u2 ON u2.id = tp.user_id
    LEFT JOIN certificates cert ON cert.enrollment_id = e.id
    WHERE e.user_id=? AND e.progress_pct = 100
    ORDER BY COALESCE(e.completed_at, e.enrolled_at) DESC
", [$uid]);

$page_title = 'My Certificates';
$extra_css  = '<style>
.cert-card{background:#fff;border-radius:var(--r-xl);overflow:hidden;box-shadow:var(--shadow-md);transition:transform .2s,box-shadow .2s;display:flex;flex-direction:column}
.cert-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg)}
.cert-preview{background:linear-gradient(135deg,#2A0E4A 0%,#5B21B6 50%,#7C3AED 100%);padding:32px 24px;position:relative;overflow:hidden;min-height:160px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
.cert-preview::before{content:"";position:absolute;top:-30%;right:-15%;width:200px;height:200px;background:rgba(255,255,255,.06);border-radius:50%}
.cert-preview::after{content:"";position:absolute;bottom:-20%;left:-10%;width:150px;height:150px;background:rgba(255,255,255,.04);border-radius:50%}
.cert-badge{width:56px;height:56px;background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.35);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;position:relative;z-index:1}
.cert-body{padding:var(--sp-5)}
</style>';
include __DIR__ . '/includes/header.php';
?>

<div style="background:linear-gradient(135deg,#1A1033,#2A0E4A,#2D1B8A);padding:var(--sp-12) 0 var(--sp-8);color:#fff">
    <div class="container">
        <div style="display:flex;align-items:center;gap:var(--sp-4);margin-bottom:var(--sp-3)">
            <span class="material-icons-round" style="font-size:40px;color:#F59E0B">workspace_premium</span>
            <div>
                <h1 style="color:#fff;font-size:clamp(1.5rem,3vw,2rem);margin-bottom:4px">My Certificates</h1>
                <p style="color:rgba(255,255,255,.65);margin:0">Your collection of certificates from completed courses</p>
            </div>
        </div>

        <div style="display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:999px;padding:8px 20px;font-size:var(--text-sm);color:rgba(255,255,255,.8)">
            <span class="material-icons-round" style="font-size:16px;color:#F59E0B">emoji_events</span>
            You've earned <strong style="color:#fff;font-family:var(--font-latin)"><?= count($completedEnrollments) ?></strong> certificates
        </div>
    </div>
</div>

<div class="container" style="padding-top:var(--sp-10);padding-bottom:var(--sp-16)">

<?php if (empty($completedEnrollments)): ?>
<div style="text-align:center;padding:var(--sp-20);background:var(--c-surface);border-radius:var(--r-2xl);border:2px dashed var(--c-border)">
    <span class="material-icons-round" style="font-size:72px;color:var(--c-ink-faint);display:block;margin-bottom:16px">workspace_premium</span>
    <h2 style="color:var(--c-ink-light);margin-bottom:8px">No certificates yet</h2>
    <p style="color:var(--c-ink-muted);max-width:400px;margin:0 auto 24px">Complete a course to earn a certificate. Get started!</p>
    <a href="<?= APP_URL ?>/courses.php" class="btn btn-primary">
        <span class="material-icons-round">school</span> Browse Courses
    </a>
</div>

<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(320px,100%),1fr));gap:var(--sp-6)">
    <?php foreach ($completedEnrollments as $ec):
        $serial    = $ec['serial'] ?: 'CERT-' . strtoupper(substr(md5($ec['enrollment_id'] . 'b-academy'), 0, 8));
        $issueDate = $ec['issued_at'] ? date('d F Y', strtotime($ec['issued_at'])) : date('d F Y', strtotime($ec['completed_at'] ?: $ec['enrolled_at']));
    ?>
    <div class="cert-card reveal">
        <!-- Visual certificate preview -->
        <div class="cert-preview">
            <div class="cert-badge">
                <span class="material-icons-round" style="font-size:28px;color:#F59E0B">workspace_premium</span>
            </div>
            <div style="font-size:var(--text-xs);color:rgba(255,255,255,.6);font-weight:700;text-transform:uppercase;letter-spacing:.1em;position:relative;z-index:1">Certificate</div>
            <div style="font-size:var(--text-sm);color:#fff;font-weight:700;margin:8px 0 4px;position:relative;z-index:1;line-height:1.4"><?= e(mb_substr($ec['title_bn'], 0, 55, 'UTF-8')) ?><?= mb_strlen($ec['title_bn'], 'UTF-8') > 55 ? '…' : '' ?></div>
            <div style="font-size:var(--text-xs);color:rgba(255,255,255,.55);position:relative;z-index:1"><?= e($user['name']) ?></div>
            <!-- Decorative lines -->
            <div style="position:absolute;bottom:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#F59E0B,#FCD34D,#F59E0B)"></div>
        </div>

        <!-- Certificate body -->
        <div class="cert-body" style="flex:1;display:flex;flex-direction:column;gap:var(--sp-3)">
            <div style="display:flex;align-items:center;gap:8px">
                <img src="<?= e(avatar_url($ec['teacher_avatar'], $ec['teacher_name'])) ?>"
                     style="width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0">
                <div style="font-size:var(--text-sm);color:var(--c-ink-muted)">
                    Teacher: <strong style="color:var(--c-ink)"><?= e($ec['teacher_name']) ?></strong>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                <div style="background:var(--c-surface-2);border-radius:var(--r-md);padding:8px 12px">
                    <div style="font-size:10px;color:var(--c-ink-muted);font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px">Issue Date</div>
                    <div style="font-size:var(--text-xs);font-weight:700;color:var(--c-ink)"><?= $issueDate ?></div>
                </div>
                <div style="background:var(--c-surface-2);border-radius:var(--r-md);padding:8px 12px">
                    <div style="font-size:10px;color:var(--c-ink-muted);font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px">Serial</div>
                    <div style="font-size:var(--text-xs);font-weight:700;color:var(--c-primary);font-family:var(--font-latin)"><?= e($serial) ?></div>
                </div>
            </div>

            <div style="display:flex;gap:8px;margin-top:auto">
                <a href="<?= APP_URL ?>/course.php?slug=<?= e($ec['slug']) ?>" class="btn btn-ghost btn-sm" style="flex:1">
                    <span class="material-icons-round" style="font-size:15px">open_in_new</span> View Course
                </a>
                <a href="<?= APP_URL ?>/learn.php?course=<?= (int)$ec['course_id'] ?>" class="btn btn-primary btn-sm" style="flex:1">
                    <span class="material-icons-round" style="font-size:15px">play_circle</span> Watch Again
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Summary note -->
<div style="margin-top:var(--sp-10);padding:var(--sp-6);background:linear-gradient(135deg,rgba(91, 33, 182,.06),rgba(139,108,255,.04));border-radius:var(--r-xl);border:1px solid rgba(91, 33, 182,.12);text-align:center">
    <span class="material-icons-round" style="font-size:32px;color:var(--c-primary);display:block;margin-bottom:8px">verified</span>
    <h3 style="margin-bottom:8px">Verify Your Certificate</h3>
    <p style="color:var(--c-ink-muted);font-size:var(--text-sm)">You can verify a certificate anytime using its serial number.</p>
</div>
<?php endif; ?>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

