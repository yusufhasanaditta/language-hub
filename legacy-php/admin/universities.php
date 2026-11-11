<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_admin();
ensure_universities_table();

$action  = sanitize_string($_GET['action'] ?? 'list', 20);
$edit_id = sanitize_int($_GET['id'] ?? 0);

// ── Handle POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $postAction = sanitize_string($_POST['action'] ?? '', 20);

    if ($postAction === 'save') {
        $id       = sanitize_int($_POST['id'] ?? 0);
        $name     = sanitize_string($_POST['university_name'] ?? '', 200);
        $details  = trim((string) ($_POST['details'] ?? ''));
        $regLink  = sanitize_string($_POST['registration_link'] ?? '', 500);
        $status   = ($_POST['status'] ?? '') === 'active' ? 'active' : 'draft';
        $sortOrder = sanitize_int($_POST['sort_order'] ?? 0);

        $formRelease = sanitize_string($_POST['form_release_date'] ?? '', 30) ?: null;
        $formLast    = sanitize_string($_POST['form_last_date'] ?? '', 30) ?: null;
        $examDate    = sanitize_string($_POST['exam_date'] ?? '', 30) ?: null;
        $resultDate  = sanitize_string($_POST['result_date'] ?? '', 30) ?: null;

        if (!$name) {
            flash('error', 'University name is required.');
            redirect(APP_URL . '/admin/universities.php?action=' . ($id ? 'edit&id=' . $id : 'add'));
        }
        if ($regLink && !filter_var($regLink, FILTER_VALIDATE_URL)) {
            flash('error', 'Registration link does not look like a valid URL.');
            redirect(APP_URL . '/admin/universities.php?action=' . ($id ? 'edit&id=' . $id : 'add'));
        }
        if ($formRelease && $formLast && strtotime($formLast) < strtotime($formRelease)) {
            flash('error', 'Form fill-up last date cannot be before the form release date.');
            redirect(APP_URL . '/admin/universities.php?action=' . ($id ? 'edit&id=' . $id : 'add'));
        }

        // Logo: uploaded file takes priority over a pasted URL; either can
        // fall back to whatever was already saved when editing.
        $logo = sanitize_string($_POST['existing_logo_url'] ?? '', 500);
        $logoUrlInput = trim((string) ($_POST['logo_url_input'] ?? ''));
        if ($logoUrlInput) $logo = $logoUrlInput;
        if (!empty($_FILES['logo']['tmp_name'])) {
            $up = handle_image_upload('logo', 'universities');
            if ($up) $logo = $up;
        }

        $circular = sanitize_string($_POST['existing_circular_pdf_url'] ?? '', 500);
        $circularUrlInput = trim((string) ($_POST['circular_pdf_url_input'] ?? ''));
        if ($circularUrlInput) $circular = $circularUrlInput;
        if (!empty($_FILES['circular_pdf']['tmp_name'])) {
            $up = handle_file_upload('circular_pdf', 'universities');
            if ($up) $circular = $up;
        }

        if ($id) {
            db_exec(
                "UPDATE universities SET university_name=?, logo_url=?, form_release_date=?, form_last_date=?,
                 exam_date=?, result_date=?, details=?, circular_pdf_url=?, registration_link=?, status=?,
                 sort_order=?, updated_at=NOW() WHERE id=?",
                [$name, $logo, $formRelease, $formLast, $examDate, $resultDate, $details, $circular, $regLink, $status, $sortOrder, $id]
            );
            flash('success', 'University updated.');
        } else {
            db_insert(
                "INSERT INTO universities (university_name, logo_url, form_release_date, form_last_date,
                 exam_date, result_date, details, circular_pdf_url, registration_link, status, sort_order, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())",
                [$name, $logo, $formRelease, $formLast, $examDate, $resultDate, $details, $circular, $regLink, $status, $sortOrder]
            );
            flash('success', 'University added.');
        }
        redirect(APP_URL . '/admin/universities.php');

    } elseif ($postAction === 'toggle_status') {
        $id = sanitize_int($_POST['id'] ?? 0);
        if ($id) {
            db_exec("UPDATE universities SET status=IF(status='active','draft','active'), updated_at=NOW() WHERE id=?", [$id]);
            flash('success', 'Status changed.');
        }
        redirect(APP_URL . '/admin/universities.php');

    } elseif ($postAction === 'delete') {
        $id = sanitize_int($_POST['id'] ?? 0);
        if ($id) {
            db_exec("DELETE FROM universities WHERE id=?", [$id]);
            flash('success', 'University deleted.');
        }
        redirect(APP_URL . '/admin/universities.php');
    }
}

// ── Load edit record ────────────────────────────────────────────────────
$editUni = null;
if (($action === 'edit') && $edit_id) {
    $editUni = db_row("SELECT * FROM universities WHERE id=?", [$edit_id]);
    if (!$editUni) { flash('error', 'University not found.'); redirect(APP_URL . '/admin/universities.php'); }
}

// ── List data ────────────────────────────────────────────────────────────
$search  = sanitize_string($_GET['q'] ?? '', 100);
$page    = max(1, sanitize_int($_GET['page'] ?? 1));
$per     = 20;
$where   = $search ? "WHERE university_name LIKE ?" : '';
$params  = $search ? ['%' . $search . '%'] : [];
$total   = (int) db_val("SELECT COUNT(*) FROM universities $where", $params);
$offset  = ($page - 1) * $per;
$universities = db_query("SELECT * FROM universities $where ORDER BY sort_order ASC, form_last_date IS NULL, form_last_date ASC LIMIT $per OFFSET $offset", $params);
$activeCount = (int) db_val("SELECT COUNT(*) FROM universities WHERE status='active'");

function u_dt(?string $v): string { return $v ? date('Y-m-d\TH:i', strtotime($v)) : ''; }
function u_dt_display(?string $v): string { return $v ? date('d M Y, h:i A', strtotime($v)) : '—'; }

$page_title = 'Admission Tracker';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?> — <?= APP_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@700;800&family=Hind+Siliguri:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<script>const APP_URL='<?= APP_URL ?>';const CSRF_TOKEN='<?= csrf_token() ?>';</script>
</head>
<body>
<div class="admin-layout">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="admin-content">
<?php foreach (get_flash() as $f): ?>
<div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:16px">
    <span class="material-icons-round"><?= $f['type'] === 'success' ? 'check_circle' : 'error' ?></span>
    <span><?= e($f['message']) ?></span>
</div>
<?php endforeach; ?>

<?php if ($action === 'add' || $action === 'edit'): ?>
<!-- ── Add / Edit Form ── -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:var(--sp-6)">
    <a href="<?= APP_URL ?>/admin/universities.php" class="btn btn-ghost btn-sm"><span class="material-icons-round">arrow_back</span></a>
    <h1 style="font-size:var(--text-2xl)"><?= $action === 'edit' ? 'Edit University' : 'Add University' ?></h1>
</div>
<form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) ($editUni['id'] ?? 0) ?>">
    <input type="hidden" name="existing_logo_url" value="<?= e($editUni['logo_url'] ?? '') ?>">
    <input type="hidden" name="existing_circular_pdf_url" value="<?= e($editUni['circular_pdf_url'] ?? '') ?>">

    <div style="display:grid;grid-template-columns:1fr 320px;gap:var(--sp-6)" class="uni-form-grid">
        <div style="display:flex;flex-direction:column;gap:var(--sp-5)">
            <div class="card" style="padding:var(--sp-6)">
                <h3 style="margin-bottom:var(--sp-4)">Basic Info</h3>
                <div class="form-group">
                    <label class="form-label">University Name *</label>
                    <input type="text" name="university_name" class="form-control" required value="<?= e($editUni['university_name'] ?? '') ?>" placeholder="e.g. Dhaka University">
                </div>
                <div class="form-group">
                    <label class="form-label">Additional Details</label>
                    <textarea name="details" class="form-control" rows="6" placeholder="Eligibility, unit-wise info, seat count, notes for students..."><?= e($editUni['details'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Registration / Apply Link</label>
                    <input type="url" name="registration_link" class="form-control" value="<?= e($editUni['registration_link'] ?? '') ?>" placeholder="https://admission.example.edu.bd">
                </div>
            </div>

            <div class="card" style="padding:var(--sp-6)">
                <h3 style="margin-bottom:var(--sp-4)">Important Dates</h3>
                <p style="color:var(--c-ink-muted);font-size:var(--text-xs);margin-top:-8px;margin-bottom:var(--sp-4)">Leave any date empty if it hasn't been announced yet — students will simply not see a countdown for it.</p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)">
                    <div class="form-group">
                        <label class="form-label">Form Release Date</label>
                        <input type="datetime-local" name="form_release_date" class="form-control" value="<?= u_dt($editUni['form_release_date'] ?? null) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Form Fill-up Last Date</label>
                        <input type="datetime-local" name="form_last_date" class="form-control" value="<?= u_dt($editUni['form_last_date'] ?? null) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Exam Date</label>
                        <input type="datetime-local" name="exam_date" class="form-control" value="<?= u_dt($editUni['exam_date'] ?? null) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Result Date</label>
                        <input type="datetime-local" name="result_date" class="form-control" value="<?= u_dt($editUni['result_date'] ?? null) ?>">
                    </div>
                </div>
                <small style="color:var(--c-ink-muted)">All times are Bangladesh Standard Time (GMT+6).</small>
            </div>
        </div>

        <div style="display:flex;flex-direction:column;gap:var(--sp-5)">
            <div class="card" style="padding:var(--sp-6)">
                <h3 style="margin-bottom:var(--sp-4)">Logo</h3>
                <?php if (!empty($editUni['logo_url'])): ?>
                <img src="<?= e(university_logo_url($editUni['logo_url'], $editUni['university_name'])) ?>" alt="" style="width:80px;height:80px;object-fit:cover;border-radius:var(--r-md);margin-bottom:var(--sp-3);display:block;border:1px solid var(--c-border)">
                <?php endif; ?>
                <div class="form-group">
                    <label class="form-label">Upload Logo</label>
                    <input type="file" name="logo" class="form-control" accept="image/jpeg,image/png,image/webp">
                </div>
                <div class="form-group">
                    <label class="form-label">— or paste an Image URL</label>
                    <input type="url" name="logo_url_input" class="form-control" placeholder="https://...">
                </div>
            </div>

            <div class="card" style="padding:var(--sp-6)">
                <h3 style="margin-bottom:var(--sp-4)">Admission Circular</h3>
                <?php if (!empty($editUni['circular_pdf_url'])): ?>
                <a href="<?= e(str_starts_with($editUni['circular_pdf_url'], 'http') ? $editUni['circular_pdf_url'] : APP_URL . '/' . ltrim($editUni['circular_pdf_url'], '/')) ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="margin-bottom:var(--sp-3)">
                    <span class="material-icons-round" style="font-size:15px">picture_as_pdf</span> View current file
                </a>
                <?php endif; ?>
                <div class="form-group">
                    <label class="form-label">Upload PDF</label>
                    <input type="file" name="circular_pdf" class="form-control" accept="application/pdf">
                </div>
                <div class="form-group">
                    <label class="form-label">— or paste a PDF URL</label>
                    <input type="url" name="circular_pdf_url_input" class="form-control" placeholder="https://...">
                </div>
            </div>

            <div class="card" style="padding:var(--sp-6)">
                <h3 style="margin-bottom:var(--sp-4)">Publishing</h3>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <option value="draft" <?= ($editUni['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft (hidden from students)</option>
                        <option value="active" <?= ($editUni['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active (visible on tracker)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="<?= (int) ($editUni['sort_order'] ?? 0) ?>">
                    <small style="color:var(--c-ink-muted)">Lower numbers show first.</small>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%">
                    <span class="material-icons-round">save</span> <?= $action === 'edit' ? 'Save Changes' : 'Add University' ?>
                </button>
            </div>
        </div>
    </div>
</form>

<?php else: ?>
<!-- ── List ── -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--sp-3);margin-bottom:var(--sp-6)">
    <div>
        <h1 style="font-size:var(--text-2xl)">Admission Tracker</h1>
        <p style="color:var(--c-ink-muted);margin:0"><?= $activeCount ?> active / <?= $total ?> total universities</p>
    </div>
    <a href="<?= APP_URL ?>/admin/universities.php?action=add" class="btn btn-primary btn-sm">
        <span class="material-icons-round">add</span> Add University
    </a>
</div>

<form method="GET" style="display:flex;gap:var(--sp-3);margin-bottom:var(--sp-6)">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search by university name..." class="form-control" style="max-width:320px">
    <button type="submit" class="btn btn-primary btn-sm"><span class="material-icons-round">search</span></button>
    <?php if ($search): ?><a href="<?= APP_URL ?>/admin/universities.php" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
</form>

<?php if (empty($universities)): ?>
<div style="text-align:center;padding:var(--sp-16);background:var(--c-surface);border-radius:var(--r-lg);border:2px dashed var(--c-border)">
    <span class="material-icons-round" style="font-size:56px;color:var(--c-ink-faint)">account_balance</span>
    <h3 style="margin:12px 0 8px;color:var(--c-ink-light)">No universities added yet</h3>
    <a href="<?= APP_URL ?>/admin/universities.php?action=add" class="btn btn-primary" style="margin-top:8px">Add Your First University</a>
</div>
<?php else: ?>
<div class="data-table-wrap">
    <table class="data-table">
        <thead><tr><th>University</th><th>Form Last Date</th><th>Exam Date</th><th>Result Date</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
            <?php foreach ($universities as $u): ?>
            <tr>
                <td>
                    <div style="display:flex;align-items:center;gap:10px">
                        <img src="<?= e(university_logo_url($u['logo_url'], $u['university_name'])) ?>" style="width:36px;height:36px;object-fit:cover;border-radius:6px;flex-shrink:0">
                        <span style="font-weight:700;font-size:var(--text-sm)"><?= e($u['university_name']) ?></span>
                    </div>
                </td>
                <td style="font-size:var(--text-xs)"><?= u_dt_display($u['form_last_date']) ?></td>
                <td style="font-size:var(--text-xs)"><?= u_dt_display($u['exam_date']) ?></td>
                <td style="font-size:var(--text-xs)"><?= u_dt_display($u['result_date']) ?></td>
                <td>
                    <span class="status-badge <?= $u['status'] === 'active' ? 'status-active' : 'status-draft' ?>">
                        <?= $u['status'] === 'active' ? 'Active' : 'Draft' ?>
                    </span>
                </td>
                <td>
                    <div style="display:flex;gap:4px">
                        <a href="<?= APP_URL ?>/admin/universities.php?action=edit&id=<?= (int) $u['id'] ?>" class="btn btn-ghost btn-sm" title="Edit">
                            <span class="material-icons-round" style="font-size:15px">edit</span>
                        </a>
                        <form method="POST" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <button type="submit" class="btn btn-ghost btn-sm" title="<?= $u['status'] === 'active' ? 'Set to draft' : 'Publish' ?>">
                                <span class="material-icons-round" style="font-size:15px"><?= $u['status'] === 'active' ? 'toggle_off' : 'toggle_on' ?></span>
                            </button>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this university? This cannot be undone.')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--c-error)" title="Delete">
                                <span class="material-icons-round" style="font-size:15px">delete</span>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= paginate($total, $page, $per, APP_URL . '/admin/universities.php?' . http_build_query(array_filter(['q' => $search, 'page' => '{page}']))) ?>
<?php endif; ?>
<?php endif; ?>

</div>
</div>
<style>
@media(max-width:900px){.uni-form-grid{grid-template-columns:1fr !important}}
</style>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>
