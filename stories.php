<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();

$stories = db_query("
    SELECT ss.*, u.name as student_name, u.avatar as student_avatar,
           c.title_bn as course_title, cat.name_bn as cat_name, cat.color as cat_color
    FROM success_stories ss
    JOIN users u ON u.id=ss.user_id
    LEFT JOIN courses c ON c.id=ss.course_id
    LEFT JOIN categories cat ON cat.id=c.category_id
    WHERE ss.is_approved=1
    ORDER BY ss.sort_order ASC, ss.created_at DESC
", []);

// Submit own story (logged-in enrolled students)
$submitError = $submitSuccess = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && is_logged_in()) {
    $user  = current_user();
    $uid   = (int)$user['id'];
    $quote = sanitize_string($_POST['quote'] ?? '', 600);
    $role  = sanitize_string($_POST['role_after'] ?? '', 200);
    $cid   = sanitize_int($_POST['course_id'] ?? 0);
    $rating = max(1, min(5, sanitize_int($_POST['rating'] ?? 5)));

    if (mb_strlen($quote, 'UTF-8') < 30) {
        $submitError = 'Your story must be at least 30 characters long.';
    } elseif (db_val("SELECT 1 FROM success_stories WHERE user_id=?", [$uid])) {
        $submitError = 'You have already submitted a story.';
    } else {
        db_insert("INSERT INTO success_stories (user_id, course_id, quote, role_after, rating, is_approved, created_at) VALUES (?,?,?,?,?,0,NOW())",
            [$uid, $cid ?: null, $quote, $role, $rating]);
        $submitSuccess = 'Your story has been submitted! It will be published after approval.';
    }
}

$enrolledCourses = [];
if (is_logged_in()) {
    $uid2 = (int)current_user()['id'];
    $enrolledCourses = db_query("
        SELECT c.id, c.title_bn FROM enrollments e JOIN courses c ON c.id=e.course_id
        WHERE e.user_id=? AND e.status='active' ORDER BY c.title_bn
    ", [$uid2]);
}

$page_title = 'Success Stories';
include __DIR__ . '/includes/header.php';
?>

<!-- Hero -->
<div style="background:linear-gradient(135deg,#080B1E 0%,#1A0842 50%,#0D1E40 100%);padding:var(--sp-16) 0 var(--sp-12);text-align:center;position:relative;overflow:hidden">
    <div style="position:absolute;top:-20%;left:50%;transform:translateX(-50%);width:700px;height:500px;background:radial-gradient(ellipse,rgba(91, 33, 182,.4) 0%,transparent 65%);pointer-events:none"></div>
    <div class="container" style="position:relative;z-index:1">
        <div style="display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:999px;padding:8px 20px;font-size:var(--text-xs);font-weight:700;color:rgba(255,255,255,.7);margin-bottom:var(--sp-5)">
            <span class="material-icons-round" style="font-size:16px">stars</span>
            Success Stories
        </div>
        <h1 style="color:#fff;font-size:clamp(2rem,5vw,3rem);margin-bottom:var(--sp-4)">
            They dreamed,<br>
            <span style="background:linear-gradient(90deg,#A78BFA,#F472B6);-webkit-background-clip:text;-webkit-text-fill-color:transparent">we showed the way</span>
        </h1>
        <p style="color:rgba(255,255,255,.65);font-size:var(--text-lg);max-width:560px;margin:0 auto">
            Thousands of students have built their dream careers by learning at Language Hub.
        </p>
    </div>
</div>

<div class="container" style="padding-top:var(--sp-12);padding-bottom:var(--sp-16)">

    <!-- Stats bar -->
    <div style="display:flex;gap:var(--sp-4);justify-content:center;flex-wrap:wrap;margin-bottom:var(--sp-12)">
        <?php $statItems = [
            ['50,000+', 'Successful Students'],
            ['95%', 'Students Satisfied'],
            ['4.8/5', 'Average Rating'],
            ['200+', 'Success Stories'],
        ]; foreach ($statItems as [$val, $lab]): ?>
        <div style="text-align:center;padding:var(--sp-5) var(--sp-8);background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--r-xl)">
            <div style="font-family:var(--font-latin);font-size:var(--text-2xl);font-weight:800;background:linear-gradient(135deg,var(--c-primary),#7C3AED);-webkit-background-clip:text;-webkit-text-fill-color:transparent"><?= $val ?></div>
            <div style="font-size:var(--text-sm);color:var(--c-ink-muted);margin-top:2px"><?= $lab ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Stories grid -->
    <?php if (empty($stories)): ?>
    <div style="text-align:center;padding:var(--sp-20);background:var(--c-surface);border-radius:var(--r-2xl);border:2px dashed var(--c-border);margin-bottom:var(--sp-12)">
        <span class="material-icons-round" style="font-size:64px;color:var(--c-ink-faint);display:block;margin-bottom:16px">auto_stories</span>
        <h3 style="color:var(--c-ink-light)">No Stories Yet</h3>
        <p style="color:var(--c-ink-muted)">Be the first! Share your success story.</p>
    </div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:var(--sp-5);margin-bottom:var(--sp-12)" class="stagger-children">
        <?php foreach ($stories as $st): ?>
        <div class="story-card" style="height:100%;display:flex;flex-direction:column">
            <div class="story-stars"><?= str_repeat('★', (int)($st['rating'] ?? 5)) ?></div>
            <p class="story-quote" style="flex:1">"<?= e($st['quote']) ?>"</p>
            <?php if (!empty($st['course_title'])): ?>
            <span class="story-course-badge" style="margin-bottom:var(--sp-3)"><?= e(mb_substr($st['course_title'],0,35,'UTF-8')) ?></span>
            <?php endif; ?>
            <div style="display:flex;align-items:center;gap:var(--sp-3);padding-top:var(--sp-4);border-top:1px solid var(--c-border)">
                <img src="<?= e(avatar_url($st['student_avatar'],$st['student_name'])) ?>"
                     style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid var(--c-primary-light);flex-shrink:0" alt="">
                <div>
                    <div class="story-name"><?= e($st['student_name']) ?></div>
                    <?php if (!empty($st['role_after'])): ?>
                    <div class="story-role"><?= e($st['role_after']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Submit story form -->
    <div style="max-width:640px;margin:0 auto">
        <div class="card" style="padding:var(--sp-8)">
            <div style="text-align:center;margin-bottom:var(--sp-6)">
                <div style="width:56px;height:56px;border-radius:50%;background:var(--c-primary-light);display:flex;align-items:center;justify-content:center;margin:0 auto var(--sp-3)">
                    <span class="material-icons-round" style="color:var(--c-primary);font-size:28px">edit_note</span>
                </div>
                <h3 style="margin-bottom:8px">Share Your Story</h3>
                <p style="color:var(--c-ink-muted);font-size:var(--text-sm)">Your success can inspire other students.</p>
            </div>

            <?php if ($submitSuccess): ?>
            <div class="alert alert-success" style="margin-bottom:var(--sp-5)">
                <span class="material-icons-round">check_circle</span>
                <span><?= e($submitSuccess) ?></span>
            </div>
            <?php elseif ($submitError): ?>
            <div class="alert alert-error" style="margin-bottom:var(--sp-5)">
                <span class="material-icons-round">error</span>
                <span><?= e($submitError) ?></span>
            </div>
            <?php endif; ?>

            <?php if (!is_logged_in()): ?>
            <div style="text-align:center;padding:var(--sp-6);background:var(--c-surface);border-radius:var(--r-lg)">
                <p style="color:var(--c-ink-muted);margin-bottom:var(--sp-4)">Log in to share your story.</p>
                <a href="<?= APP_URL ?>/login.php" class="btn btn-primary">Log In</a>
            </div>
            <?php else: ?>
            <form method="POST">
                <?= csrf_field() ?>
                <?php if ($enrolledCourses): ?>
                <div class="form-group">
                    <label class="form-label">Course (Optional)</label>
                    <select name="course_id" class="form-control">
                        <option value="">Choose a Course</option>
                        <?php foreach ($enrolledCourses as $ec): ?>
                        <option value="<?= (int)$ec['id'] ?>"><?= e($ec['title_bn']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label class="form-label">Rating</label>
                    <div style="display:flex;gap:var(--sp-2)" id="starPicker">
                        <?php for ($i=1;$i<=5;$i++): ?>
                        <label style="cursor:pointer;font-size:1.5rem;color:var(--c-border);transition:color .15s" for="star<?= $i ?>">★
                            <input type="radio" name="rating" id="star<?= $i ?>" value="<?= $i ?>" style="display:none" <?= $i===5?'checked':'' ?>>
                        </label>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Your Story *</label>
                    <textarea name="quote" class="form-control" rows="5" required minlength="30" maxlength="600"
                              placeholder="How Language Hub changed my life..."></textarea>
                    <div class="form-hint">At least 30 characters. Maximum 600 characters.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Your Current Position / Career</label>
                    <input type="text" name="role_after" class="form-control" maxlength="200"
                           placeholder="e.g. Admitted to Dhaka University">
                </div>
                <button type="submit" class="btn btn-primary btn-block">
                    <span class="material-icons-round">send</span>
                    Submit Story
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Star picker highlight
const starLabels = document.querySelectorAll('#starPicker label');
const starInputs = document.querySelectorAll('#starPicker input[type=radio]');
function highlightStars(n) {
    starLabels.forEach((l, i) => { l.style.color = i < n ? '#F59E0B' : 'var(--c-border)'; });
}
// Init
highlightStars(5);
starInputs.forEach((inp, i) => {
    inp.addEventListener('change', () => highlightStars(i + 1));
});
starLabels.forEach((l, i) => {
    l.addEventListener('mouseenter', () => highlightStars(i + 1));
    l.addEventListener('mouseleave', () => {
        const checked = document.querySelector('#starPicker input:checked');
        if (checked) highlightStars(parseInt(checked.value));
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
