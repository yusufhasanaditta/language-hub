<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
require_login();

// Route to correct dashboard or redirect
$__role = (int)($_SESSION['user_role'] ?? 0);
if (in_array($__role, [ROLE_ADMIN, ROLE_OPS], true)) {
    redirect(APP_URL . '/admin/index.php');
}
if ($__role === ROLE_TEACHER) {
    redirect(APP_URL . '/teacher/dashboard.php');
}

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $text = sanitize_string($_POST['text'] ?? '', 500);
    $rating = sanitize_int($_POST['rating'] ?? 5);
    $batch = sanitize_string($_POST['batch'] ?? '', 255);

    if (empty($text) || empty($batch)) {
        flash('error', 'Please fill out all fields.');
        redirect(APP_URL . '/submit_testimonial.php');
    }

    $name = $user['name'];
    $avatar = mb_substr($name, 0, 1, 'UTF-8');
    
    // Pick a random color
    $colors = ['#5B21B6', '#F4B400', '#15BE8B', '#F59E0B', '#3CB5F2', '#E11D48'];
    $color = $colors[array_rand($colors)];

    db_exec("INSERT INTO testimonials (name, batch, avatar, color, text, rating, is_approved) VALUES (?, ?, ?, ?, ?, ?, 0)", 
        [$name, $batch, $avatar, $color, $text, $rating]);
    
    flash('success', 'Thank you! Your testimonial has been submitted and is pending approval.');
    redirect(APP_URL . '/dashboard.php');
}

$page_title = 'Submit Testimonial';
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding: 40px 15px; max-width: 600px;">
    <?php foreach(get_flash() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:16px">
        <span class="material-icons-round"><?= $f['type']==='success'?'check_circle':'error' ?></span>
        <span><?= e($f['message']) ?></span>
    </div>
    <?php endforeach; ?>

    <div class="card" style="padding: 32px;">
        <h2 style="margin-bottom: 24px; text-align: center;">Share Your Experience</h2>
        <p style="text-align: center; color: var(--c-ink-muted); margin-bottom: 32px;">We'd love to hear how <?= APP_NAME ?> has helped you in your journey. Your review could be featured on our homepage!</p>

        <form method="POST">
            <?= csrf_field() ?>
            <div class="form-group">
                <label class="form-label">Name</label>
                <input type="text" class="form-control" value="<?= e($user['name']) ?>" disabled>
            </div>
            <div class="form-group">
                <label class="form-label">Batch / Goal (e.g. HSC 2025, Science)</label>
                <input type="text" name="batch" class="form-control" required placeholder="What are you preparing for?">
            </div>
            <div class="form-group">
                <label class="form-label">Your Testimonial</label>
                <textarea name="text" class="form-control" rows="5" required placeholder="Tell us how the courses, teachers, or exams have helped you..."></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Rating</label>
                <select name="rating" class="form-control" required>
                    <option value="5">★★★★★ (5/5)</option>
                    <option value="4">★★★★☆ (4/5)</option>
                    <option value="3">★★★☆☆ (3/5)</option>
                    <option value="2">★★☆☆☆ (2/5)</option>
                    <option value="1">★☆☆☆☆ (1/5)</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top: 16px;">Submit Testimonial</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
