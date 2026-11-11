<?php
// Language Hub UI components shared by the homepage and course listings.
defined('B_ACADEMY') or die('Direct access forbidden.');

/** Per-language presentation data, keyed by category slug. */
function lh_langs(): array {
    return [
        'chinese'  => ['glyph' => '中', 'hello' => '你好',       'native' => '中文',   'exam' => 'HSK',   'var' => '--lang-zh'],
        'japanese' => ['glyph' => 'あ', 'hello' => 'こんにちは', 'native' => '日本語', 'exam' => 'JLPT',  'var' => '--lang-ja'],
        'korean'   => ['glyph' => '한', 'hello' => '안녕하세요', 'native' => '한국어', 'exam' => 'TOPIK', 'var' => '--lang-ko'],
        'english'  => ['glyph' => 'A',  'hello' => 'Hello',      'native' => 'English','exam' => 'IELTS', 'var' => '--lang-en'],
    ];
}

function lh_lang(?string $slug): array {
    return lh_langs()[$slug ?? ''] ?? ['glyph' => 'L', 'hello' => 'Hi', 'native' => '', 'exam' => '', 'var' => '--c-primary'];
}

/** 0-9 → ০-৯ */
function bn_num(string|int $s): string {
    return strtr((string)$s, ['0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪','5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯']);
}

/** Number shown in both languages (Bangla digits / Latin digits). */
function t_num(string|int $n): string {
    return '<span class="i18n" lang="bn">' . e(bn_num($n)) . '</span><span class="i18n" lang="en">' . e($n) . '</span>';
}

function lh_date(?string $date): string {
    if (!$date) return '';
    $ts = strtotime($date);
    $bnMonths = ['জানুয়ারি','ফেব্রুয়ারি','মার্চ','এপ্রিল','মে','জুন','জুলাই','আগস্ট','সেপ্টেম্বর','অক্টোবর','নভেম্বর','ডিসেম্বর'];
    return t(bn_num(date('j', $ts)) . ' ' . $bnMonths[(int)date('n', $ts) - 1], date('j M', $ts));
}

/** Effective price for display: [current, original|null]. */
function lh_price(array $c): array {
    $price = (float)$c['price'];
    $sale  = $c['sale_price'] !== null ? (float)$c['sale_price'] : null;
    return ($sale !== null && $sale < $price) ? [$sale, $price] : [$price, null];
}

/** Ticket-style course card. Expects cat_slug in the row. */
function lh_course_card(array $c): string {
    $L = lh_lang($c['cat_slug'] ?? null);
    [$now, $was] = lh_price($c);
    $isFree = (int)$c['is_free'] === 1 || $now <= 0;
    $type = $c['type'] === 'live'
        ? '<span class="lh-chip lh-chip-live"><i></i>' . t('লাইভ', 'Live') . '</span>'
        : '<span class="lh-chip">' . t('রেকর্ডেড', 'Recorded') . '</span>';
    $thumb = !empty($c['thumbnail']) && is_file(APP_ROOT . '/' . ltrim($c['thumbnail'], '/'))
        ? '<img src="' . e(APP_URL . '/' . ltrim($c['thumbnail'], '/')) . '" alt="" loading="lazy">'
        : '<span class="lh-ticket-glyph" aria-hidden="true">' . e($L['glyph']) . '</span><span class="lh-ticket-hello" aria-hidden="true">' . e($L['hello']) . '</span>';

    ob_start(); ?>
    <a class="lh-ticket" href="<?= e(course_url($c)) ?>" style="--lang:var(<?= $L['var'] ?>)"
       data-lang="<?= e($c['cat_slug'] ?? '') ?>" data-type="<?= $c['type'] === 'live' ? 'live' : 'recorded' ?>" data-free="<?= $isFree ? 1 : 0 ?>">
        <div class="lh-ticket-art"><?= $thumb ?><?= $type ?></div>
        <div class="lh-ticket-body">
            <p class="lh-ticket-meta"><?= t($c['cat_name'] ?? '', $c['cat_name_en'] ?? '') ?> · <?= t_num((int)$c['total_lessons']) ?> <?= t('ক্লাস', 'lessons') ?></p>
            <h3><?= t($c['title_bn'], $c['title_en'] ?: $c['title_bn']) ?></h3>
            <p class="lh-ticket-teacher"><?= e($c['teacher_name'] ?? '') ?></p>
        </div>
        <div class="lh-ticket-stub">
            <?php if ($isFree): ?>
                <span class="lh-price lh-price-free"><?= t('ফ্রি', 'Free') ?></span>
            <?php else: ?>
                <span class="lh-price">৳<?= t_num(format_price($now)) ?></span>
                <?php if ($was): ?><s>৳<?= t_num(format_price($was)) ?></s><?php endif; ?>
            <?php endif; ?>
            <span class="lh-ticket-go material-icons-round" aria-hidden="true">arrow_forward</span>
        </div>
    </a>
    <?php return ob_get_clean();
}
