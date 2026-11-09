<?php
defined('B_ACADEMY') or die('Direct access forbidden.');

/**
 * Course page builder — shared definitions + frontend renderer.
 * Admin builds blocks in admin/course_sections.php; course.php renders them.
 */

/** kind => [label, icon, row-fields]. Row-based kinds repeat their fields. */
function cs_kinds(): array {
    return [
        'rich' => [
            'label'  => 'Text block',
            'icon'   => 'notes',
            'repeat' => false,
            'fields' => ['html' => ['Body text', 'textarea']],
        ],
        'features' => [
            'label'  => 'Feature cards',
            'icon'   => 'grid_view',
            'repeat' => true,
            'fields' => [
                'icon'  => ['Material icon name', 'text'],
                'title' => ['Title', 'text'],
                'text'  => ['Description', 'textarea'],
            ],
        ],
        'faq' => [
            'label'  => 'FAQ (accordion)',
            'icon'   => 'quiz',
            'repeat' => true,
            'fields' => [
                'q' => ['Question', 'text'],
                'a' => ['Answer', 'textarea'],
            ],
        ],
        'routine' => [
            'label'  => 'Class routine table',
            'icon'   => 'calendar_month',
            'repeat' => true,
            'fields' => [
                'day'     => ['Day', 'text'],
                'time'    => ['Time', 'text'],
                'subject' => ['Subject', 'text'],
                'teacher' => ['Teacher', 'text'],
            ],
        ],
        'stats' => [
            'label'  => 'Number highlights',
            'icon'   => 'insights',
            'repeat' => true,
            'fields' => [
                'value' => ['Value', 'text'],
                'label' => ['Label', 'text'],
            ],
        ],
        'gallery' => [
            'label'  => 'Image gallery',
            'icon'   => 'photo_library',
            'repeat' => true,
            'fields' => [
                'src'     => ['Image URL', 'text'],
                'caption' => ['Caption', 'text'],
            ],
        ],
        'video' => [
            'label'  => 'Video',
            'icon'   => 'smart_display',
            'repeat' => false,
            'fields' => [
                'url'     => ['YouTube / video URL', 'text'],
                'caption' => ['Caption', 'text'],
            ],
        ],
        'cta' => [
            'label'  => 'Call to action',
            'icon'   => 'campaign',
            'repeat' => false,
            'fields' => [
                'heading'   => ['Heading', 'text'],
                'text'      => ['Sub text', 'textarea'],
                'btn_label' => ['Button label', 'text'],
                'btn_url'   => ['Button URL', 'text'],
            ],
        ],
        'checklist' => [
            'label'  => 'Checklist card',
            'icon'   => 'checklist',
            'repeat' => true,
            'fields' => [
                'text' => ['Line (e.g. 120+ live classes)', 'text'],
            ],
        ],
        'teachers' => [
            'label'  => 'Teacher rail',
            'icon'   => 'groups',
            'repeat' => true,
            'fields' => [
                'photo'       => ['Photo URL', 'text'],
                'name'        => ['Name', 'text'],
                'institution' => ['Institution (BUET / DU …)', 'text'],
                'ring'        => ['Ring colour (hex, optional)', 'text'],
            ],
        ],
        'subjects' => [
            'label'  => 'Subject list',
            'icon'   => 'menu_book',
            'repeat' => true,
            'fields' => [
                'name' => ['Subject', 'text'],
            ],
        ],
        'notice_cta' => [
            'label'  => 'Note + button box',
            'icon'   => 'sticky_note_2',
            'repeat' => false,
            'fields' => [
                'note'      => ['Note text', 'textarea'],
                'btn_label' => ['Button label', 'text'],
                'btn_url'   => ['Button URL', 'text'],
                'tone'      => ['Tone: red / green / blue', 'text'],
            ],
        ],
    ];
}

function cs_load(int $courseId, bool $activeOnly = true): array {
    $sql = "SELECT * FROM course_sections WHERE course_id = ?"
         . ($activeOnly ? " AND is_active = 1" : "")
         . " ORDER BY sort_order ASC, id ASC";
    $rows = db_query($sql, [$courseId]);
    foreach ($rows as &$r) {
        $decoded = json_decode((string)$r['content'], true);
        $r['data'] = is_array($decoded) ? $decoded : [];
    }
    return $rows;
}

/** Turn any YouTube/Vimeo URL into an embeddable one. */
function cs_embed_url(string $url): string {
    if (preg_match('~youtu\.be/([A-Za-z0-9_-]{6,})~', $url, $m)
        || preg_match('~youtube\.com/watch\?v=([A-Za-z0-9_-]{6,})~', $url, $m)
        || preg_match('~youtube\.com/embed/([A-Za-z0-9_-]{6,})~', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
    }
    if (preg_match('~vimeo\.com/(\d+)~', $url, $m)) {
        return 'https://player.vimeo.com/video/' . $m[1];
    }
    return $url;
}

/** All active blocks of one kind (e.g. course.php pulls 'faq'/'routine' into their own tabs). */
function cs_sections_by_kind(int $courseId, string $kind): array {
    return array_values(array_filter(cs_load($courseId), fn($s) => $s['kind'] === $kind));
}

/**
 * First checklist block of a course, as a plain array of strings.
 * course.php mirrors it inside the enroll card so phone users see
 * "what's included" without scrolling.
 */
function cs_checklist_items(int $courseId, int $max = 8): array {
    foreach (cs_load($courseId) as $s) {
        if ($s['kind'] !== 'checklist') continue;
        $items = [];
        foreach ($s['data'] as $row) {
            $text = trim((string)($row['text'] ?? ''));
            if ($text !== '') $items[] = $text;
        }
        if ($items) {
            return ['title' => $s['title'] ?: 'What this course includes',
                    'items' => array_slice($items, 0, $max)];
        }
    }
    return [];
}

/**
 * Render every published block for a course, skipping any kinds already
 * shown elsewhere on the page (e.g. course.php gives 'faq'/'routine' their
 * own tabs instead of floating them here).
 */
function cs_render_all(int $courseId, array $skipKinds = []): string {
    $sections = cs_load($courseId);
    if (!$sections) return '';
    $out = '';
    foreach ($sections as $s) {
        if (in_array($s['kind'], $skipKinds, true)) continue;
        $out .= cs_render_one($s);
    }
    return $out;
}

function cs_render_only(int $courseId, array $includeKinds): string {
    $sections = cs_load($courseId);
    if (!$sections) return '';
    $out = '';
    foreach ($sections as $s) {
        if (!in_array($s['kind'], $includeKinds, true)) continue;
        $out .= cs_render_one($s);
    }
    return $out;
}

/**
 * All active blocks assigned to a given tab ('overview' or 'curriculum'),
 * optionally skipping kinds that are already rendered elsewhere on that tab.
 */
function cs_render_by_placement(int $courseId, string $placement, array $skipKinds = []): string {
    $sections = cs_load($courseId);
    if (!$sections) return '';
    $out = '';
    foreach ($sections as $s) {
        if (($s['placement'] ?? 'overview') !== $placement) continue;
        if (in_array($s['kind'], $skipKinds, true)) continue;
        $out .= cs_render_one($s);
    }
    return $out;
}

function cs_render_one(array $s): string
{
    $bg = in_array($s['bg_style'], ['plain', 'tint', 'dark'], true) ? $s['bg_style'] : 'plain';

    ob_start(); ?>
<section class="cs-block <?= $bg ?>">
    <div class="container">
        <?= cs_render_inner($s) ?>
    </div>
</section>
    <?php
    return ob_get_clean();
}

/** Block content only (head + body), with no <section>/container wrapper — for embedding inside a tab panel. */
function cs_render_inner(array $s): string
{
    $data = $s['data'] ?? [];

    ob_start(); ?>
        <?php if (!empty($s['title']) || !empty($s['subtitle'])): ?>
        <div class="cs-head">
            <?php if (!empty($s['title'])): ?><h2 class="cs-title"><?= e($s['title']) ?></h2><?php endif; ?>
            <?php if (!empty($s['subtitle'])): ?><p class="cs-sub"><?= e($s['subtitle']) ?></p><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php switch ($s['kind']):

            case 'rich': ?>
                <div class="cs-rich"><?= nl2p((string)($data['html'] ?? '')) ?></div>
                <?php break;

            case 'features': ?>
                <div class="cs-features">
                    <?php foreach ($data as $f): ?>
                    <div class="cs-feature">
                        <div class="cs-feature-icon">
                            <span class="material-icons-round"><?= e($f['icon'] ?: 'check_circle') ?></span>
                        </div>
                        <div class="cs-feature-title"><?= e($f['title'] ?? '') ?></div>
                        <p class="cs-feature-text"><?= nl2br(e($f['text'] ?? '')) ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php break;

            case 'faq': ?>
                <div class="cs-faq" data-faq-list>
                    <?php foreach ($data as $i => $f): ?>
                    <div class="cs-faq-item <?= $i === 0 ? 'open' : '' ?>">
                        <button type="button" class="cs-faq-q" aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>">
                            <span><?= e($f['q'] ?? '') ?></span>
                            <span class="material-icons-round">expand_more</span>
                        </button>
                        <div class="cs-faq-a"><?= nl2br(e($f['a'] ?? '')) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php break;

            case 'routine': ?>
                <div class="table-wrap">
                    <table class="cs-routine">
                        <thead>
                            <tr><th>Day</th><th>Time</th><th>Subject</th><th>Teacher</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data as $r): ?>
                            <tr>
                                <td><strong><?= e($r['day'] ?? '') ?></strong></td>
                                <td><?= e($r['time'] ?? '') ?></td>
                                <td><?= e($r['subject'] ?? '') ?></td>
                                <td><?= e($r['teacher'] ?? '') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php break;

            case 'stats': ?>
                <div class="cs-stats">
                    <?php foreach ($data as $st): ?>
                    <div class="cs-stat">
                        <span class="cs-stat-val"><?= e($st['value'] ?? '') ?></span>
                        <span class="cs-stat-label"><?= e($st['label'] ?? '') ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php break;

            case 'gallery': ?>
                <div class="cs-gallery">
                    <?php foreach ($data as $g): if (empty($g['src'])) continue; ?>
                    <figure>
                        <img src="<?= e($g['src']) ?>" alt="<?= e($g['caption'] ?? '') ?>" loading="lazy">
                        <?php if (!empty($g['caption'])): ?>
                        <figcaption><?= e($g['caption']) ?></figcaption>
                        <?php endif; ?>
                    </figure>
                    <?php endforeach; ?>
                </div>
                <?php break;

            case 'video':
                $url = trim((string)($data['url'] ?? ''));
                if ($url !== ''): ?>
                <div class="cs-video">
                    <div class="cs-video-frame">
                        <iframe src="<?= e(cs_embed_url($url)) ?>" title="<?= e($data['caption'] ?? 'Course video') ?>"
                                loading="lazy" allowfullscreen
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe>
                    </div>
                    <?php if (!empty($data['caption'])): ?>
                    <p class="cs-sub" style="text-align:center;margin-top:var(--sp-3)"><?= e($data['caption']) ?></p>
                    <?php endif; ?>
                </div>
                <?php endif;
                break;

            case 'checklist': ?>
                <div class="cs-checklist">
                    <?php foreach ($data as $c): if (trim((string)($c['text'] ?? '')) === '') continue; ?>
                    <div class="cs-check-row">
                        <span class="cs-check-mark"><span class="material-icons-round">check</span></span>
                        <span><?= e($c['text']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php break;

            case 'teachers': ?>
                <div class="cs-teacher-rail">
                    <?php foreach ($data as $t): if (trim((string)($t['name'] ?? '')) === '') continue;
                        $ring = preg_match('/^#[0-9A-Fa-f]{3,8}$/', (string)($t['ring'] ?? '')) ? $t['ring'] : '';
                        $photo = trim((string)($t['photo'] ?? ''));
                        if ($photo !== '' && !preg_match('~^https?://~i', $photo)) {
                            $photo = APP_URL . '/' . ltrim($photo, '/');
                        }
                    ?>
                    <figure class="cs-teacher">
                        <span class="cs-teacher-ring" <?= $ring ? 'style="background:' . e($ring) . '"' : '' ?>>
                            <?php if ($photo !== ''): ?>
                            <img src="<?= e($photo) ?>" alt="<?= e($t['name']) ?>" loading="lazy">
                            <?php else: ?>
                            <span class="cs-teacher-initial"><?= e(mb_substr($t['name'], 0, 1)) ?></span>
                            <?php endif; ?>
                        </span>
                        <figcaption>
                            <span class="cs-teacher-name"><?= e($t['name']) ?></span>
                            <?php if (!empty($t['institution'])): ?>
                            <span class="cs-teacher-inst"><?= e($t['institution']) ?></span>
                            <?php endif; ?>
                        </figcaption>
                    </figure>
                    <?php endforeach; ?>
                </div>
                <?php break;

            case 'subjects': ?>
                <div class="cs-subjects">
                    <?php foreach ($data as $s2): if (trim((string)($s2['name'] ?? '')) === '') continue; ?>
                    <div class="cs-subject"><span class="cs-subject-dot">◆</span><?= e($s2['name']) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php break;

            case 'notice_cta':
                $tone = in_array($data['tone'] ?? 'red', ['red', 'green', 'blue'], true) ? $data['tone'] : 'red'; ?>
                <div class="cs-notebox <?= $tone ?>">
                    <?php if (!empty($data['note'])): ?>
                    <p class="cs-notebox-text"><?= nl2br(e($data['note'])) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($data['btn_url'])):
                        $btnUrl = trim((string)$data['btn_url']);
                        $embed  = cs_embed_url($btnUrl);
                        // YouTube/Vimeo links open in the on-site lightbox;
                        // anything else is a normal link.
                        $isVideo = $embed !== $btnUrl;
                    ?>
                    <a href="<?= e($btnUrl) ?>" class="cs-notebox-btn"
                       <?= $isVideo ? 'data-video="' . e($embed) . '"' : 'target="_blank" rel="noopener"' ?>>
                        <span class="material-icons-round">play_circle</span>
                        <?= e($data['btn_label'] ?: 'Watch video') ?>
                    </a>
                    <?php endif; ?>
                </div>
                <?php break;

            case 'cta': ?>
                <div class="cs-cta">
                    <div>
                        <h3><?= e($data['heading'] ?? '') ?></h3>
                        <?php if (!empty($data['text'])): ?><p><?= e($data['text']) ?></p><?php endif; ?>
                    </div>
                    <?php if (!empty($data['btn_url'])): ?>
                    <a href="<?= e($data['btn_url']) ?>" class="btn btn-white btn-lg">
                        <?= e($data['btn_label'] ?: 'Enroll now') ?>
                    </a>
                    <?php endif; ?>
                </div>
                <?php break;

        endswitch; ?>
    </div>
</section>
    <?php
    return ob_get_clean();
}
