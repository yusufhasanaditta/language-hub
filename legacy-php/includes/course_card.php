<?php
// Partial: Course Card
// Expects $course array with fields from courses + teacher info
if (!isset($course)) return;
$isFree    = (int)($course['is_free'] ?? 0);
$hasDiscount = !$isFree && isset($course['sale_price']) && $course['sale_price'] > 0 && $course['sale_price'] < $course['price'];
$displayPrice = $hasDiscount ? $course['sale_price'] : $course['price'];
$courseType = $course['type'] ?? 'recorded';
?>
<a href="<?= e(course_url($course)) ?>" class="course-card" style="text-decoration:none">
    <div class="course-thumb">
        <?php $thumbUrl = course_thumbnail_url($course['thumbnail'] ?? '', $course['title_bn'] ?? ''); ?>
        <img
            src="<?= e($thumbUrl) ?>"
            alt="<?= e($course['title_bn']) ?>"
            width="320" height="180"
            loading="lazy" decoding="async">
        <?php if ($isFree): ?>
        <span class="course-badge free">Free</span>
        <?php elseif ($courseType === 'live'): ?>
        <span class="course-badge live">Live</span>
        <?php elseif ($hasDiscount): ?>
        <span class="course-badge sale"><?= round((1 - $displayPrice / $course['price']) * 100) ?>% OFF</span>
        <?php else: ?>
        <span class="course-badge"><?= e($course['cat_name'] ?? '') ?></span>
        <?php endif; ?>
        <div class="course-play-btn">
            <span class="material-icons-round">play_arrow</span>
        </div>
    </div>
    <div class="course-body">
        <div class="course-category" style="color:<?= e($course['cat_color'] ?? 'var(--c-primary)') ?>">
            <?= e($course['cat_name'] ?? '') ?>
        </div>
        <h3 class="course-title"><?= e($course['title_bn']) ?></h3>
        <div class="course-meta">
            <?php if (!empty($course['total_lessons'])): ?>
            <span class="course-meta-item">
                <span class="material-icons-round">play_lesson</span>
                <?= (int)$course['total_lessons'] ?> lessons
            </span>
            <?php endif; ?>
            <?php $courseCardEnrolled = public_enrolled_count($course); if ($courseCardEnrolled > 0): ?>
            <span class="course-meta-item">
                <span class="material-icons-round">group</span>
                <?= format_number($courseCardEnrolled) ?> students
            </span>
            <?php endif; ?>
            <?php if (!empty($course['total_duration'])): ?>
            <span class="course-meta-item">
                <span class="material-icons-round">schedule</span>
                <?= format_duration((int)$course['total_duration']) ?>
            </span>
            <?php endif; ?>
        </div>
        <div class="course-footer">
            <div class="course-price">
                <?php if ($isFree): ?>
                <span class="price-current price-free">Free</span>
                <?php else: ?>
                <span class="price-current"><?= format_price((float)$displayPrice) ?></span>
                <?php if ($hasDiscount): ?>
                <span class="price-old"><?= format_price((float)$course['price']) ?></span>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if (!empty($course['rating_avg']) && $course['rating_avg'] > 0): ?>
            <div class="rating-wrap">
                <?= render_stars((float)$course['rating_avg'], true) ?>
                <span class="rating-val"><?= number_format((float)$course['rating_avg'], 1) ?></span>
                <?php if (!empty($course['rating_count'])): ?>
                <span class="rating-count">(<?= (int)$course['rating_count'] ?>)</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</a>
