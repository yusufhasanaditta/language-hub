<?php
// Homepage: every course, with instant filter chips. Expects $home['all'] and $home['langs'].
defined('B_ACADEMY') or die('Direct access forbidden.');
$all = $home['all'] ?? [];
if (!$all) return;

$count = fn(callable $f) => count(array_filter($all, $f));
$chips = [['all', t('সব', 'All'), count($all), null]];
foreach ($home['langs'] as $cat) {
    $n = $count(fn($c) => $c['cat_slug'] === $cat['slug']);
    if ($n) $chips[] = ['lang:' . $cat['slug'], t($cat['name_bn'], $cat['name_en']), $n, lh_lang($cat['slug'])];
}
foreach ([['type:live', t('লাইভ', 'Live'), fn($c) => $c['type'] === 'live'],
          ['type:recorded', t('রেকর্ডেড', 'Recorded'), fn($c) => $c['type'] !== 'live'],
          ['free', t('ফ্রি', 'Free'), fn($c) => (int)$c['is_free'] === 1]] as [$key, $label, $f]) {
    $n = $count($f);
    if ($n) $chips[] = [$key, $label, $n, null];
}
?>
<!-- ═══ ALL COURSES (instant filter) ══════════════════════════════════════ -->
<section class="lh-section lh-all" id="courses" data-course-filter data-page-size="12">
    <div class="container">
        <div class="lh-section-head lh-section-head-row" data-reveal>
            <div>
                <p class="lh-eyebrow"><?= t_num(count($all)) ?> <?= t('টি কোর্স', 'courses') ?></p>
                <h2><?= t('সব কোর্স এক জায়গায়', 'Every course in one place') ?></h2>
            </div>
        </div>
    </div>

    <div class="lh-all-chips" role="toolbar" aria-label="Filter courses">
        <?php foreach ($chips as $i => [$key, $label, $n, $L]): ?>
        <button type="button" class="lh-fchip <?= $i === 0 ? 'is-on' : '' ?>" data-filter="<?= e($key) ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"
                <?= $L ? 'style="--lang:var(' . $L['var'] . ')"' : '' ?>>
            <?php if ($L): ?><span class="lh-fchip-g"><?= e($L['glyph']) ?></span><?php elseif ($key === 'type:live'): ?><span class="lh-chip-dot"></span><?php endif; ?>
            <?= $label ?> <span class="lh-fchip-n"><?= t_num($n) ?></span>
        </button>
        <?php endforeach; ?>
    </div>

    <div class="container">
        <div class="lh-grid lh-all-grid" data-items>
            <?php foreach ($all as $c) echo lh_course_card($c); ?>
        </div>
        <p class="lh-all-empty" data-empty hidden><?= t('এই ফিল্টারে কোনো কোর্স নেই।', 'No courses in this filter.') ?></p>
        <div class="lh-all-more">
            <button type="button" class="btn btn-outline" data-more hidden><?= t('আরও দেখুন', 'Show more') ?> <span class="material-icons-round">expand_more</span></button>
        </div>
    </div>
</section>
