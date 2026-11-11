<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();

$contactEmail = get_setting('contact_email', '');

// Static list — edit here to open/close positions (no admin UI needed yet).
$openings = [
    ['title' => 'Subject Teacher (HSC Physics / Chemistry / Math)', 'type' => 'Full-time / Part-time', 'location' => 'Remote / Dhaka'],
    ['title' => 'Content & Curriculum Writer', 'type' => 'Full-time', 'location' => 'Remote'],
    ['title' => 'Video Editor', 'type' => 'Part-time', 'location' => 'Remote'],
    ['title' => 'Student Support Executive', 'type' => 'Full-time', 'location' => 'Dhaka'],
];

$page_title = 'Careers';
$page_desc  = 'Join the ' . APP_NAME . ' team — teach, build, and help students across Bangladesh succeed.';
include __DIR__ . '/includes/header.php';
?>

<section class="section-sm" style="background:linear-gradient(135deg,#1A1033,#2A0E4A);padding:var(--sp-16) 0">
    <div class="container" style="text-align:center;color:#fff">
        <div class="section-label" style="background:rgba(255,255,255,.12);color:rgba(255,255,255,.9)">Careers</div>
        <h1 style="font-size:clamp(1.8rem,4vw,3rem);color:#fff;margin:var(--sp-3) 0">Build the future of learning with us</h1>
        <p style="color:rgba(255,255,255,.75);max-width:640px;margin:0 auto;font-size:var(--text-lg)">
            We're a small team with an outsized mission — quality education for every student in Bangladesh.
            If that excites you, we'd love to hear from you.
        </p>
    </div>
</section>

<section class="section">
    <div class="container">
        <div style="text-align:center;margin-bottom:var(--sp-12)">
            <div class="section-label">Why join us</div>
            <h2 class="section-title">What you get</h2>
        </div>
        <div class="grid-3" style="gap:var(--sp-6)">
            <?php
            $perks = [
                ['icon' => 'trending_up', 'title' => 'Real Impact', 'desc' => 'Your work directly reaches thousands of students preparing for the biggest exams of their lives.'],
                ['icon' => 'schedule',    'title' => 'Flexible Setup', 'desc' => 'Remote-friendly roles with schedules built around getting good work done, not clocking hours.'],
                ['icon' => 'groups',      'title' => 'Small, Fast Team', 'desc' => 'No layers of bureaucracy — good ideas ship fast, and everyone\'s voice is heard.'],
            ];
            foreach ($perks as $p): ?>
            <div style="background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--r-lg);padding:var(--sp-6)">
                <div style="width:56px;height:56px;background:var(--c-primary-light);border-radius:var(--r-md);display:flex;align-items:center;justify-content:center;margin-bottom:var(--sp-4)">
                    <span class="material-icons-round" style="font-size:28px;color:var(--c-primary)"><?= e($p['icon']) ?></span>
                </div>
                <h4 style="margin-bottom:var(--sp-2)"><?= e($p['title']) ?></h4>
                <p style="color:var(--c-ink-muted);line-height:1.7;margin:0"><?= e($p['desc']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section" style="background:var(--c-surface)">
    <div class="container" style="max-width:800px">
        <div style="text-align:center;margin-bottom:var(--sp-10)">
            <div class="section-label">Open Positions</div>
            <h2 class="section-title">Current openings</h2>
        </div>
        <div style="display:flex;flex-direction:column;gap:var(--sp-4)">
            <?php foreach ($openings as $job):
                $subject = 'Application: ' . $job['title'];
                $mailto  = 'mailto:' . e($contactEmail) . '?subject=' . rawurlencode($subject);
            ?>
            <div style="background:#fff;border:1px solid var(--c-border);border-radius:var(--r-lg);padding:var(--sp-5);display:flex;align-items:center;justify-content:space-between;gap:var(--sp-4);flex-wrap:wrap">
                <div>
                    <h4 style="margin-bottom:6px"><?= e($job['title']) ?></h4>
                    <div style="display:flex;gap:var(--sp-3);color:var(--c-ink-muted);font-size:var(--text-sm)">
                        <span style="display:flex;align-items:center;gap:4px"><span class="material-icons-round" style="font-size:16px">work</span><?= e($job['type']) ?></span>
                        <span style="display:flex;align-items:center;gap:4px"><span class="material-icons-round" style="font-size:16px">place</span><?= e($job['location']) ?></span>
                    </div>
                </div>
                <a href="<?= $mailto ?>" class="btn btn-primary btn-sm">Apply Now</a>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="text-align:center;margin-top:var(--sp-10);color:var(--c-ink-muted)">
            Don't see a role that fits? Send us your CV anyway at
            <a href="mailto:<?= e($contactEmail) ?>" style="color:var(--c-primary);font-weight:700"><?= e($contactEmail) ?></a>.
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
