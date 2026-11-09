<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();

$page_title = 'Our Teachers';
$page_desc  = 'Meet the best teachers at Language Hub. Learn from expert instructors.';

$q      = sanitize_string($_GET['q'] ?? '', 100);
$page   = max(1, sanitize_int($_GET['page'] ?? 1));
$per    = 12;
$where  = "WHERE u.is_active=1 AND u.role_id IN (2,3,4,6)";
if ($q) $where .= " AND (u.name LIKE '%".addslashes($q)."%' OR tp.headline LIKE '%".addslashes($q)."%' OR tp.expertise LIKE '%".addslashes($q)."%')";

$total   = (int)db_val("SELECT COUNT(*) FROM teacher_profiles tp JOIN users u ON u.id=tp.user_id $where");
$offset  = ($page-1)*$per;
$teachers = db_query("
    SELECT tp.*, u.name, u.avatar, u.username,
           (SELECT COUNT(*) FROM courses c WHERE c.teacher_id=tp.id AND c.status='published') as course_count,
           (SELECT COUNT(DISTINCT e.user_id) FROM enrollments e JOIN courses c ON c.id=e.course_id WHERE c.teacher_id=tp.id) as student_count,
           (SELECT COALESCE(AVG(r.rating),0) FROM reviews r JOIN courses c ON c.id=r.course_id WHERE c.teacher_id=tp.id) as rating
    FROM teacher_profiles tp
    JOIN users u ON u.id=tp.user_id
    $where
    ORDER BY tp.featured_order DESC, tp.total_students DESC
    LIMIT $per OFFSET $offset
");

include __DIR__ . '/includes/header.php';
?>

<section class="section-sm" style="background:linear-gradient(135deg,#1A1033,#2A0E4A);padding:var(--sp-16) 0">
    <div class="container" style="text-align:center;color:#fff">
        <div class="section-label" style="background:rgba(255,255,255,.12);color:rgba(255,255,255,.9)">Our Team</div>
        <h1 style="font-size:clamp(1.8rem,4vw,3rem);color:#fff;margin:var(--sp-3) 0">Our Teachers</h1>
        <p style="color:rgba(255,255,255,.65);font-size:var(--text-lg);max-width:540px;margin:0 auto var(--sp-8)">
            Learn from expert teachers. Every teacher is skilled and experienced in their subject.
        </p>
        <form method="GET" style="max-width:480px;margin:0 auto;display:flex;gap:var(--sp-3)">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by name or subject..."
                   class="form-control" style="flex:1;background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.2);color:#fff">
            <button type="submit" class="btn btn-accent">
                <span class="material-icons-round">search</span>
            </button>
        </form>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php if ($q): ?>
        <div style="margin-bottom:var(--sp-6);color:var(--c-ink-muted)">
            Found <?= $total ?> teacher(s) matching "<?= e($q) ?>" —
            <a href="<?= APP_URL ?>/teachers.php" style="color:var(--c-primary)">Reset</a>
        </div>
        <?php endif; ?>

        <?php if (empty($teachers)): ?>
        <div style="text-align:center;padding:var(--sp-16)">
            <span class="material-icons-round" style="font-size:64px;color:var(--c-ink-faint)">school</span>
            <h3 style="color:var(--c-ink-light);margin-top:var(--sp-4)">No Teachers Found</h3>
        </div>
        <?php else: ?>
        <div class="grid-4" style="--cols:4">
            <?php foreach ($teachers as $t):
                $slug = $t['username'] ?: null;
                $profileUrl = APP_URL . '/teacher-profile.php?' . ($slug ? 'slug='.urlencode($slug) : 'id='.(int)$t['id']);
            ?>
            <a href="<?= e($profileUrl) ?>" class="teacher-card reveal" style="text-decoration:none">
                <div style="position:relative;display:flex;justify-content:center">
                    <img src="<?= e(avatar_url($t['avatar'], $t['name'])) ?>"
                         alt="<?= e($t['name']) ?>"
                         class="teacher-avatar"
                         style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid var(--c-primary-light)">
                    <?php if ($t['featured_order'] > 0): ?>
                    <span style="position:absolute;top:0;right:calc(50% - 48px);background:linear-gradient(135deg,#F59E0B,#EF4444);color:#fff;border-radius:999px;padding:2px 8px;font-size:10px;font-weight:800">★ Featured</span>
                    <?php endif; ?>
                </div>
                <div class="teacher-name" style="margin-top:var(--sp-3)"><?= e($t['name']) ?></div>
                <?php if (!empty($t['headline'])): ?>
                <div class="teacher-designation"><?= e(mb_substr($t['headline'],0,60,'UTF-8')) ?></div>
                <?php endif; ?>
                <?php if (!empty($t['expertise'])): ?>
                <div style="margin-top:var(--sp-2);display:flex;flex-wrap:wrap;gap:4px;justify-content:center">
                    <?php foreach (array_slice(explode(',', $t['expertise']), 0, 3) as $skill): ?>
                    <span style="background:var(--c-primary-light);color:var(--c-primary);border-radius:999px;padding:2px 8px;font-size:10px;font-weight:600"><?= e(trim($skill)) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="teacher-stats" style="margin-top:var(--sp-3)">
                    <div class="teacher-stat">
                        <span class="material-icons-round" style="font-size:13px;color:var(--c-warning)">star</span>
                        <strong><?= number_format((float)$t['rating'], 1) ?></strong>
                    </div>
                    <div class="teacher-stat">
                        <strong><?= format_number((int)$t['student_count']) ?></strong> Students
                    </div>
                    <div class="teacher-stat">
                        <strong><?= (int)$t['course_count'] ?></strong> Courses
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?= paginate($total, $page, $per, APP_URL.'/teachers.php?'.http_build_query(array_filter(['q'=>$q,'page'=>'{page}']))) ?>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
