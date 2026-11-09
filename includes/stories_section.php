<?php
// Stories section partial — include anywhere on public pages
// Requires B_ACADEMY defined and db helpers available
if (!defined('B_ACADEMY')) return;

$stories = cache_get('success_stories');
if ($stories === null) {
    $stories = db_query("
        SELECT ss.*, u.name as student_name, u.avatar as student_avatar,
               c.title_bn as course_title
        FROM success_stories ss
        JOIN users u ON u.id=ss.user_id
        LEFT JOIN courses c ON c.id=ss.course_id
        WHERE ss.is_approved=1
        ORDER BY ss.sort_order ASC, ss.created_at DESC
        LIMIT 10
    ", []);
    cache_set('success_stories', $stories, 3600);
}

if (empty($stories)) return;
?>
<section class="stories-section">
    <div class="container">
        <div class="section-heading">
            <div class="section-heading-eyebrow">
                <span class="material-icons-round" style="font-size:14px">stars</span>
                Success Stories
            </div>
            <h2>What Our Students Say</h2>
            <p>Thousands of students have changed their lives by learning with Language Hub. Here are some of their stories.</p>
        </div>

        <div class="stories-scroll stagger-children">
            <?php foreach ($stories as $st): ?>
            <div class="story-card">
                <div class="story-stars">
                    <?= str_repeat('★', (int)($st['rating'] ?? 5)) ?>
                </div>
                <p class="story-quote">"<?= e(mb_substr($st['quote'], 0, 200, 'UTF-8')) ?>"</p>
                <div style="display:flex;align-items:center;gap:var(--sp-3)">
                    <img src="<?= e(avatar_url($st['student_avatar'], $st['student_name'])) ?>"
                         alt="" class="story-avatar" style="margin-bottom:0;width:44px;height:44px">
                    <div>
                        <div class="story-name"><?= e($st['student_name']) ?></div>
                        <div class="story-role"><?= e($st['role_after'] ?? '') ?></div>
                        <?php if (!empty($st['course_title'])): ?>
                        <span class="story-course-badge"><?= e(mb_substr($st['course_title'],0,30,'UTF-8')) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="text-align:center;margin-top:var(--sp-8)">
            <a href="<?= APP_URL ?>/stories.php" class="btn btn-outline">
                <span class="material-icons-round">read_more</span>
                Read All Stories
            </a>
        </div>
    </div>
</section>
