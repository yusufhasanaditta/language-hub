<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();
ensure_universities_table();

$q      = sanitize_string($_GET['q'] ?? '', 100);
$filter = sanitize_string($_GET['filter'] ?? '', 20);
$sort   = sanitize_string($_GET['sort'] ?? 'urgent', 10);
if (!in_array($sort, ['urgent', 'name'], true)) { $sort = 'urgent'; }

$where  = ["status='active'"];
$params = [];

if ($q) { $where[] = 'university_name LIKE ?'; $params[] = '%' . $q . '%'; }

if ($filter === 'form_open') {
    $where[] = "(form_last_date IS NOT NULL AND form_last_date >= NOW())";
} elseif ($filter === 'exam_upcoming') {
    $where[] = "(exam_date IS NOT NULL AND exam_date >= NOW())";
} elseif ($filter === 'result_awaited') {
    $where[] = "(result_date IS NOT NULL AND result_date >= NOW() AND (exam_date IS NULL OR exam_date < NOW()))";
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);
$universities = db_query("SELECT * FROM universities $whereSQL ORDER BY sort_order ASC, university_name ASC", $params);

// university_next_milestone() / university_status_label() live in includes/functions.php
// so the homepage teaser section can reuse them too.

// Default view: whichever university has a deadline coming up soonest floats
// to the top, since that's what a guest scanning this page actually needs.
// Universities with no upcoming milestone sort to the end either way.
if ($sort === 'urgent') {
    usort($universities, function ($a, $b) {
        $ma = university_next_milestone($a);
        $mb = university_next_milestone($b);
        $ta = $ma ? strtotime($ma['value']) : PHP_INT_MAX;
        $tb = $mb ? strtotime($mb['value']) : PHP_INT_MAX;
        return $ta <=> $tb;
    });
}

$page_title = 'University Admission & Exam Tracker';
$page_desc  = 'Track admission form dates, exam dates and results for all major university admission circulars in one place.';
include __DIR__ . '/includes/header.php';
?>

<section class="section-sm" style="background:linear-gradient(135deg,#1A1033,#2A0E4A);padding:var(--sp-16) 0">
    <div class="container" style="text-align:center;color:#fff">
        <div class="section-label" style="background:rgba(255,255,255,.12);color:rgba(255,255,255,.9)">Admission Tracker</div>
        <h1 style="font-size:clamp(1.8rem,4vw,3rem);color:#fff;margin:var(--sp-3) 0">University Admission &amp; Exam Tracker</h1>
        <p style="color:rgba(255,255,255,.65);font-size:var(--text-lg);max-width:600px;margin:0 auto var(--sp-8)">
            Never miss a form deadline, exam date, or result again — all in one place.
        </p>
        <form method="GET" style="max-width:520px;margin:0 auto;display:flex;gap:var(--sp-3)">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search university name..."
                   class="form-control" style="flex:1;background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.2);color:#fff">
            <?php if ($filter): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
            <button type="submit" class="btn btn-accent">
                <span class="material-icons-round">search</span>
            </button>
        </form>

        <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:var(--sp-6)">
            <?php
            $filters = ['' => 'All', 'form_open' => 'Form Open', 'exam_upcoming' => 'Exam Upcoming', 'result_awaited' => 'Result Awaited'];
            foreach ($filters as $fVal => $fLabel):
                $active = $filter === $fVal;
                $url = APP_URL . '/admission-tracker.php?' . http_build_query(array_filter(['q' => $q, 'filter' => $fVal]));
            ?>
            <a href="<?= e($url) ?>" class="chip" style="text-decoration:none;<?= $active ? 'background:#fff;color:#1A1033;border-color:#fff;font-weight:800;' : 'color:rgba(255,255,255,.8);border-color:rgba(255,255,255,.25)' ?>"><?= e($fLabel) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--sp-3);margin-bottom:var(--sp-6)">
            <?php if ($q || $filter): ?>
            <div style="color:var(--c-ink-muted)">
                <?= count($universities) ?> result(s)<?= $q ? ' for "' . e($q) . '"' : '' ?> —
                <a href="<?= APP_URL ?>/admission-tracker.php" style="color:var(--c-primary)">Reset</a>
            </div>
            <?php else: ?>
            <div></div>
            <?php endif; ?>

            <div class="uni-sort-toggle">
                <?php foreach (['urgent' => 'Soonest Deadline', 'name' => 'A–Z'] as $sVal => $sLabel):
                    $active = $sort === $sVal;
                    $surl = APP_URL . '/admission-tracker.php?' . http_build_query(array_filter(['q' => $q, 'filter' => $filter, 'sort' => $sVal]));
                ?>
                <a href="<?= e($surl) ?>" class="uni-sort-btn <?= $active ? 'active' : '' ?>"><?= e($sLabel) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (empty($universities)): ?>
        <div style="text-align:center;padding:var(--sp-16)">
            <span class="material-icons-round" style="font-size:64px;color:var(--c-ink-faint)">account_balance</span>
            <h3 style="color:var(--c-ink-light);margin-top:var(--sp-4)">No admission circulars found</h3>
            <p style="color:var(--c-ink-muted)">Check back soon — new circulars are added regularly.</p>
        </div>
        <?php else: ?>
        <div class="uni-grid">
            <?php foreach ($universities as $u):
                $milestone = university_next_milestone($u);
                $statusLabel = university_status_label($u);
                $circularUrl = $u['circular_pdf_url']
                    ? (str_starts_with($u['circular_pdf_url'], 'http') ? $u['circular_pdf_url'] : APP_URL . '/' . ltrim($u['circular_pdf_url'], '/'))
                    : null;
                $shareUrl = APP_URL . '/admission-tracker.php#university-' . (int) $u['id'];
                $daysLeft = $milestone ? (strtotime($milestone['value']) - time()) / 86400 : null;
                $calendarUrl = $milestone
                    ? google_calendar_url($u['university_name'] . ' — ' . $milestone['label'], $milestone['value'], 'Tracked via ' . APP_NAME . ' Admission Tracker: ' . $shareUrl)
                    : null;
            ?>
            <div class="uni-card" id="university-<?= (int) $u['id'] ?>">
                <?php if ($daysLeft !== null && $daysLeft <= 7): ?>
                <div class="uni-badge <?= $daysLeft <= 1 ? 'uni-badge-hot' : '' ?>"><?= $daysLeft <= 1 ? 'Closing Today' : 'Closing Soon' ?></div>
                <?php endif; ?>
                <div class="uni-card-head">
                    <img src="<?= e(university_logo_url($u['logo_url'], $u['university_name'])) ?>" alt="<?= e($u['university_name']) ?>" class="uni-logo">
                    <div style="flex:1;min-width:0">
                        <h3 class="uni-name"><?= e($u['university_name']) ?></h3>
                        <?php if ($milestone): ?>
                        <div class="uni-countdown" data-countdown data-target="<?= e(to_dhaka_iso($milestone['value'])) ?>" data-label="<?= e($milestone['label']) ?>">
                            <span class="uni-countdown-label"><?= e($milestone['label']) ?></span>
                            <span class="uni-countdown-timer">Loading…</span>
                        </div>
                        <?php else: ?>
                        <div class="uni-countdown uni-countdown-static"><?= e($statusLabel) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="uni-dates">
                    <div class="uni-date-item">
                        <span class="material-icons-round">event_available</span>
                        <div><div class="uni-date-label">Form Release</div><div class="uni-date-value"><?= $u['form_release_date'] ? date('d M Y', strtotime($u['form_release_date'])) : '—' ?></div></div>
                    </div>
                    <div class="uni-date-item">
                        <span class="material-icons-round">event_busy</span>
                        <div><div class="uni-date-label">Form Last Date</div><div class="uni-date-value"><?= $u['form_last_date'] ? date('d M Y', strtotime($u['form_last_date'])) : '—' ?></div></div>
                    </div>
                    <div class="uni-date-item">
                        <span class="material-icons-round">edit_calendar</span>
                        <div><div class="uni-date-label">Exam Date</div><div class="uni-date-value"><?= $u['exam_date'] ? date('d M Y', strtotime($u['exam_date'])) : '—' ?></div></div>
                    </div>
                    <div class="uni-date-item">
                        <span class="material-icons-round">emoji_events</span>
                        <div><div class="uni-date-label">Result Date</div><div class="uni-date-value"><?= $u['result_date'] ? date('d M Y', strtotime($u['result_date'])) : '—' ?></div></div>
                    </div>
                </div>

                <?php if (!empty($u['details'])): ?>
                <details class="uni-details">
                    <summary>View Details</summary>
                    <div class="uni-details-body"><?= nl2br(e($u['details'])) ?></div>
                </details>
                <?php endif; ?>

                <div class="uni-actions">
                    <?php if ($circularUrl): ?>
                    <a href="<?= e($circularUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-ghost btn-sm">
                        <span class="material-icons-round" style="font-size:16px">picture_as_pdf</span> Download Circular
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($u['registration_link'])): ?>
                    <a href="<?= e($u['registration_link']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-sm">
                        <span class="material-icons-round" style="font-size:16px">open_in_new</span> Apply Now
                    </a>
                    <?php endif; ?>
                    <?php if ($calendarUrl): ?>
                    <a href="<?= e($calendarUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-ghost btn-sm" title="Add '<?= e($milestone['label']) ?>' to Google Calendar">
                        <span class="material-icons-round" style="font-size:16px">event</span> Remind Me
                    </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-ghost btn-sm uni-share-btn" data-url="<?= e($shareUrl) ?>" title="Copy link to this university">
                        <span class="material-icons-round" style="font-size:16px">share</span> Share
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<style>
.uni-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:var(--sp-6); }
.uni-card { position:relative; background:#fff; border:1px solid var(--c-border); border-radius:var(--r-xl); padding:var(--sp-5); display:flex; flex-direction:column; gap:var(--sp-4); box-shadow:0 2px 10px rgba(13,11,46,.04); transition:box-shadow .2s, transform .2s; }
.uni-card:hover { box-shadow:0 12px 30px rgba(13,11,46,.1); transform:translateY(-2px); }
.uni-badge { position:absolute; top:14px; right:14px; background:#F59E0B; color:#fff; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.03em; padding:4px 10px; border-radius:var(--r-full); box-shadow:0 2px 8px rgba(0,0,0,.12); }
.uni-badge-hot { background:#DC2626; }
.uni-sort-toggle { display:inline-flex; background:var(--c-surface); border-radius:var(--r-full); padding:3px; gap:2px; }
.uni-sort-btn { padding:6px 14px; border-radius:var(--r-full); font-size:var(--text-xs); font-weight:700; color:var(--c-ink-muted); text-decoration:none; white-space:nowrap; }
.uni-sort-btn.active { background:#fff; color:var(--c-primary); box-shadow:0 1px 4px rgba(13,11,46,.08); }
.uni-card-head { display:flex; align-items:flex-start; gap:var(--sp-3); }
.uni-logo { width:56px; height:56px; border-radius:var(--r-md); object-fit:cover; border:1px solid var(--c-border); flex-shrink:0; background:#fff; }
.uni-name { font-size:var(--text-base); font-weight:800; margin:0 0 6px; line-height:1.3; }
.uni-countdown { display:inline-flex; flex-direction:column; gap:2px; background:var(--c-primary-light); color:var(--c-primary); border-radius:var(--r-md); padding:6px 10px; }
.uni-countdown-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; opacity:.85; }
.uni-countdown-timer { font-family:var(--font-latin); font-weight:800; font-size:var(--text-sm); }
.uni-countdown-static { background:var(--c-surface); color:var(--c-ink-muted); font-size:var(--text-xs); font-weight:700; padding:6px 10px; }
.uni-countdown.is-urgent { background:#FEF2F2; color:#DC2626; }
.uni-countdown.is-expired { background:var(--c-surface); color:var(--c-ink-faint); }
.uni-dates { display:grid; grid-template-columns:1fr 1fr; gap:var(--sp-3); background:var(--c-surface); border-radius:var(--r-md); padding:var(--sp-3); }
.uni-date-item { display:flex; align-items:center; gap:8px; }
.uni-date-item .material-icons-round { font-size:18px; color:var(--c-primary); flex-shrink:0; }
.uni-date-label { font-size:10px; color:var(--c-ink-muted); text-transform:uppercase; letter-spacing:.03em; }
.uni-date-value { font-size:var(--text-sm); font-weight:700; font-family:var(--font-latin); }
.uni-details summary { cursor:pointer; font-size:var(--text-sm); font-weight:700; color:var(--c-primary); list-style:none; display:flex; align-items:center; gap:4px; }
.uni-details summary::-webkit-details-marker { display:none; }
.uni-details summary::before { content:'expand_more'; font-family:'Material Icons Round'; font-size:18px; transition:transform .2s; }
.uni-details[open] summary::before { transform:rotate(180deg); }
.uni-details-body { margin-top:var(--sp-3); font-size:var(--text-sm); color:var(--c-ink-light); line-height:1.7; }
.uni-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:auto; padding-top:var(--sp-2); border-top:1px solid var(--c-border); }
</style>

<script src="<?= APP_URL ?>/assets/js/admission-tracker.js?v=<?= APP_VERSION ?>" defer></script>

<?php include __DIR__ . '/includes/footer.php'; ?>
