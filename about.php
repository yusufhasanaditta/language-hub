<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();

$totalStudents = (int) get_setting('total_students', db_val("SELECT COUNT(*) FROM users WHERE role_id=1"));
$totalCourses  = (int) get_setting('total_courses', db_val("SELECT COUNT(*) FROM courses WHERE status='published'"));
$totalTeachers = (int) get_setting('total_teachers', db_val("SELECT COUNT(*) FROM teacher_profiles"));
$successRate   = (int) get_setting('success_rate', 98);

$page_title = 'About Us';
$page_desc  = 'Learn about ' . APP_NAME . '\'s mission to make quality education accessible for every student in Bangladesh.';
include __DIR__ . '/includes/header.php';
?>

<section class="section-sm" style="background:linear-gradient(135deg,#1A1033,#2A0E4A);padding:var(--sp-16) 0">
    <div class="container" style="text-align:center;color:#fff">
        <div class="section-label" style="background:rgba(255,255,255,.12);color:rgba(255,255,255,.9)">About Us</div>
        <h1 style="font-size:clamp(1.8rem,4vw,3rem);color:#fff;margin:var(--sp-3) 0">Learn with joy, move forward</h1>
        <p style="color:rgba(255,255,255,.75);max-width:640px;margin:0 auto;font-size:var(--text-lg)">
            <?= e(APP_NAME) ?> is Bangladesh's platform for HSC, admission tests, and skill development —
            built by educators who believe every student deserves a teacher who makes it click.
        </p>
    </div>
</section>

<section class="section">
    <div class="container" style="max-width:900px">
        <div class="section-label">Our Story</div>
        <h2 class="section-title">Why we started <?= e(APP_NAME) ?></h2>
        <p style="color:var(--c-ink-muted);font-size:var(--text-lg);line-height:1.8;margin-top:var(--sp-4)">
            Too many bright students in Bangladesh are held back not by ability, but by access — to good
            teachers, to structured practice, and to honest feedback on where they stand. We built
            <?= e(APP_NAME) ?> to close that gap: live classes with real teachers, model tests that mirror
            the real exam, and a rank board that tells you exactly where you need to improve, all in one
            place and at a price every family can afford.
        </p>
    </div>
</section>

<section class="section" style="background:var(--c-surface)">
    <div class="container">
        <div class="grid-3" style="gap:var(--sp-6);text-align:center">
            <div>
                <div style="font-family:var(--font-latin);font-size:2.5rem;font-weight:800;color:var(--c-primary)"><?= format_number($totalStudents ?: 12500) ?>+</div>
                <div style="color:var(--c-ink-muted);font-weight:600">Students</div>
            </div>
            <div>
                <div style="font-family:var(--font-latin);font-size:2.5rem;font-weight:800;color:var(--c-primary)"><?= $totalCourses ?: 5 ?>+</div>
                <div style="color:var(--c-ink-muted);font-weight:600">Courses</div>
            </div>
            <div>
                <div style="font-family:var(--font-latin);font-size:2.5rem;font-weight:800;color:var(--c-primary)"><?= $totalTeachers ?: 15 ?>+</div>
                <div style="color:var(--c-ink-muted);font-weight:600">Teachers</div>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div style="text-align:center;margin-bottom:var(--sp-12)">
            <div class="section-label">What we stand for</div>
            <h2 class="section-title">Our values</h2>
        </div>
        <div class="grid-3" style="gap:var(--sp-6)">
            <?php
            $values = [
                ['icon' => 'diversity_3', 'title' => 'Accessible', 'desc' => 'Quality education at a price every family in Bangladesh can afford, with free courses always available.'],
                ['icon' => 'verified',    'title' => 'Honest',     'desc' => 'Real progress tracking and real rankings — no inflated scores, no false promises.'],
                ['icon' => 'bolt',        'title' => 'Practical',  'desc' => 'Every lesson is built around what actually shows up on the exam, not filler content.'],
            ];
            foreach ($values as $v): ?>
            <div style="background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--r-lg);padding:var(--sp-6)">
                <div style="width:56px;height:56px;background:var(--c-primary-light);border-radius:var(--r-md);display:flex;align-items:center;justify-content:center;margin-bottom:var(--sp-4)">
                    <span class="material-icons-round" style="font-size:28px;color:var(--c-primary)"><?= e($v['icon']) ?></span>
                </div>
                <h4 style="margin-bottom:var(--sp-2)"><?= e($v['title']) ?></h4>
                <p style="color:var(--c-ink-muted);line-height:1.7;margin:0"><?= e($v['desc']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section" style="background:linear-gradient(135deg,var(--c-primary),var(--c-primary-dark));color:#fff">
    <div class="container" style="text-align:center">
        <h2 class="section-title" style="color:#fff">Ready to start learning?</h2>
        <p style="color:rgba(255,255,255,.8);margin-bottom:var(--sp-6)">Join <?= format_number($totalStudents ?: 12500) ?>+ students already learning with <?= e(APP_NAME) ?>.</p>
        <a href="<?= APP_URL ?>/courses.php" class="btn btn-white btn-lg">
            <span class="material-icons-round">play_circle</span> Browse Courses
        </a>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
