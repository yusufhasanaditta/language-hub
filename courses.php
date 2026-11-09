<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lh_components.php';

session_start_secure();
send_security_headers();

// Filters
$category = sanitize_string($_GET['category'] ?? '', 50);
$search   = sanitize_string($_GET['q'] ?? '', 100);
$type     = sanitize_string($_GET['type'] ?? '', 20);
$free     = (int)($_GET['free'] ?? 0);
$sort     = sanitize_string($_GET['sort'] ?? 'popular', 20);
$page     = max(1, sanitize_int($_GET['page'] ?? 1));
$perPage  = COURSES_PER_PAGE;

// Build WHERE
$where  = ["c.status = 'published'"];
$params = [];

if ($category) {
    $where[]  = "cat.slug = ?";
    $params[] = $category;
}
if ($search) {
    $where[]  = "(c.title_bn LIKE ? OR c.title_en LIKE ? OR u.name LIKE ?)";
    $s = '%' . $search . '%';
    $params = array_merge($params, [$s, $s, $s]);
}
if ($type && in_array($type, ['recorded','live','hybrid'])) {
    $where[]  = "c.type = ?";
    $params[] = $type;
}
if ($free) {
    $where[] = "c.is_free = 1";
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$orderSQL = match($sort) {
    'newest' => 'c.created_at DESC',
    'price_low' => 'COALESCE(c.sale_price, c.price) ASC',
    'price_high' => 'COALESCE(c.sale_price, c.price) DESC',
    'rating' => 'c.rating_avg DESC',
    default => 'c.enrolled_count DESC, c.is_featured DESC',
};

$total = (int)db_val("
    SELECT COUNT(*) FROM courses c
    JOIN categories cat ON cat.id = c.category_id
    JOIN teacher_profiles tp ON tp.id = c.teacher_id
    JOIN users u ON u.id = tp.user_id
    $whereSQL
", $params);

$offset  = ($page - 1) * $perPage;
$courses = db_query("
    SELECT c.*, cat.name_bn as cat_name, cat.name_en as cat_name_en, cat.color as cat_color, cat.slug as cat_slug,
           u.name as teacher_name, u.avatar as teacher_avatar
    FROM courses c
    JOIN categories cat ON cat.id = c.category_id
    JOIN teacher_profiles tp ON tp.id = c.teacher_id
    JOIN users u ON u.id = tp.user_id
    $whereSQL
    ORDER BY $orderSQL
    LIMIT $perPage OFFSET $offset
", $params);

$categories  = db_query("SELECT * FROM categories ORDER BY sort_order");
$activeCat   = $category ? db_row("SELECT * FROM categories WHERE slug=?", [$category]) : null;
$urlPattern  = APP_URL . '/courses.php?' . http_build_query(array_filter(['category'=>$category,'q'=>$search,'type'=>$type,'free'=>$free,'sort'=>$sort,'page'=>'{page}']));


// Build a link that keeps the other filters but changes some.
$qs = function (array $change) use ($category, $search, $type, $free, $sort): string {
    $q = array_merge(['category' => $category, 'q' => $search, 'type' => $type, 'free' => $free ?: '', 'sort' => $sort === 'popular' ? '' : $sort], $change);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null && $v !== 0);
    return APP_URL . '/courses.php' . ($q ? '?' . http_build_query($q) : '');
};
$L = $activeCat ? lh_lang($activeCat['slug']) : null;

$page_title = $activeCat ? ($activeCat['name_en'] . ' courses') : ($search ? 'Search: ' . $search : 'All courses');
$body_class = 'lh-list';
include __DIR__ . '/includes/header.php';
?>

<section class="lh-list-head" <?= $L ? 'style="--lang:var(' . $L['var'] . ')"' : '' ?>>
    <div class="lh-glow" aria-hidden="true"></div>
    <?php if ($L): ?><span class="lh-list-glyph" aria-hidden="true"><?= e($L['glyph']) ?></span><?php endif; ?>
    <div class="container" data-reveal>
        <p class="lh-eyebrow"><?= t_num($total) ?> <?= t('টি কোর্স', 'courses') ?></p>
        <h1 class="lh-list-title">
            <?php if ($activeCat): ?><?= t($activeCat['name_bn'] . ' শিখুন', 'Learn ' . $activeCat['name_en']) ?>
            <?php elseif ($search): ?><?= t('“' . $search . '” এর ফলাফল', 'Results for “' . $search . '”') ?>
            <?php elseif ($type === 'live'): ?><?= t('লাইভ ব্যাচ', 'Live batches') ?>
            <?php elseif ($free): ?><?= t('ফ্রি কোর্স', 'Free courses') ?>
            <?php else: ?><?= t('সব কোর্স', 'All courses') ?><?php endif; ?>
        </h1>
        <form method="GET" action="<?= APP_URL ?>/courses.php" class="lh-search" role="search">
            <?php foreach (['category' => $category, 'type' => $type, 'free' => $free ?: ''] as $k => $v): if ($v === '') continue; ?>
            <input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>">
            <?php endforeach; ?>
            <span class="material-icons-round" aria-hidden="true">search</span>
            <label for="q" class="sr-only">Search</label>
            <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="কোর্স বা শিক্ষক খুঁজুন…" autocomplete="off">
            <?php if ($search): ?><a href="<?= e($qs(['q' => ''])) ?>" class="lh-search-x" aria-label="Clear search"><span class="material-icons-round">close</span></a><?php endif; ?>
        </form>
    </div>
</section>

<!-- Sticky filter chips: swipe sideways on phones -->
<div class="lh-filters">
    <div class="lh-chips" role="list">
        <a role="listitem" href="<?= e($qs(['category' => ''])) ?>" class="lh-fchip <?= !$category ? 'is-on' : '' ?>"><?= t('সব ভাষা', 'All') ?></a>
        <?php foreach ($categories as $cat): $CL = lh_lang($cat['slug']); ?>
        <a role="listitem" href="<?= e($qs(['category' => $cat['slug'], 'page' => ''])) ?>" class="lh-fchip <?= $category === $cat['slug'] ? 'is-on' : '' ?>" style="--lang:var(<?= $CL['var'] ?>)">
            <span class="lh-fchip-g"><?= e($CL['glyph']) ?></span><?= t($cat['name_bn'], $cat['name_en']) ?>
        </a>
        <?php endforeach; ?>
        <span class="lh-chips-sep" aria-hidden="true"></span>
        <a role="listitem" href="<?= e($qs(['type' => $type === 'live' ? '' : 'live', 'free' => ''])) ?>" class="lh-fchip <?= $type === 'live' ? 'is-on' : '' ?>"><span class="lh-chip-dot"></span><?= t('লাইভ', 'Live') ?></a>
        <a role="listitem" href="<?= e($qs(['type' => $type === 'recorded' ? '' : 'recorded', 'free' => ''])) ?>" class="lh-fchip <?= $type === 'recorded' ? 'is-on' : '' ?>"><?= t('রেকর্ডেড', 'Recorded') ?></a>
        <a role="listitem" href="<?= e($qs(['free' => $free ? '' : 1, 'type' => ''])) ?>" class="lh-fchip <?= $free ? 'is-on' : '' ?>"><?= t('ফ্রি', 'Free') ?></a>
    </div>
    <label class="lh-sort">
        <span class="sr-only">Sort</span>
        <span class="material-icons-round" aria-hidden="true">swap_vert</span>
        <select onchange="location.href=this.value">
            <?php foreach (['popular' => ['জনপ্রিয়', 'Popular'], 'newest' => ['নতুন', 'Newest'], 'price_low' => ['কম দাম', 'Price ↑'], 'price_high' => ['বেশি দাম', 'Price ↓'], 'rating' => ['রেটিং', 'Rating']] as $k => [$bn, $en]): ?>
            <option value="<?= e($qs(['sort' => $k === 'popular' ? '' : $k, 'page' => ''])) ?>" <?= $sort === $k ? 'selected' : '' ?>><?= e($bn) ?> · <?= e($en) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
</div>

<section class="container lh-list-body">
    <?php if (!$courses): ?>
    <div class="lh-empty" data-reveal>
        <span class="material-icons-round" aria-hidden="true">travel_explore</span>
        <h2><?= t('কোনো কোর্স পাওয়া যায়নি', 'No courses found') ?></h2>
        <p><?= t('অন্য শব্দে খুঁজুন বা ফিল্টার বদলান।', 'Try another word or change the filters.') ?></p>
        <a href="<?= APP_URL ?>/courses.php" class="btn btn-primary"><?= t('সব কোর্স দেখুন', 'See all courses') ?></a>
    </div>
    <?php else: ?>
    <div class="lh-grid" data-reveal-group>
        <?php foreach ($courses as $c) echo lh_course_card($c); ?>
    </div>
    <?= paginate($total, $page, $perPage, $urlPattern) ?>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
