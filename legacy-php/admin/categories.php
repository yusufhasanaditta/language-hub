<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $act = sanitize_string($_POST['action'] ?? '', 20);
    $cid = sanitize_int($_POST['category_id'] ?? 0);

    if ($act === 'add' || $act === 'edit') {
        $name_bn = sanitize_string($_POST['name_bn'] ?? '', 100);
        $name_en = sanitize_string($_POST['name_en'] ?? '', 100);
        $slug = sanitize_string($_POST['slug'] ?? '', 100);
        $icon = sanitize_string($_POST['icon'] ?? '', 50);
        $color = sanitize_string($_POST['color'] ?? '', 20);
        $sort_order = sanitize_int($_POST['sort_order'] ?? 0);

        if (!$name_bn || !$slug) {
            flash('error', 'Category Name (Bangla) and Slug are required.');
        } else {
            // Check slug uniqueness
            $exists = db_val("SELECT id FROM categories WHERE slug=? AND id!=?", [$slug, $cid]);
            if ($exists) {
                flash('error', 'This slug is already used by another category.');
            } else {
                if ($act === 'add') {
                    db_exec("INSERT INTO categories (name_bn, name_en, slug, icon, color, sort_order) VALUES (?, ?, ?, ?, ?, ?)", 
                        [$name_bn, $name_en, $slug, $icon, $color, $sort_order]);
                    flash('success', 'Category added.');
                } else {
                    db_exec("UPDATE categories SET name_bn=?, name_en=?, slug=?, icon=?, color=?, sort_order=? WHERE id=?", 
                        [$name_bn, $name_en, $slug, $icon, $color, $sort_order, $cid]);
                    flash('success', 'Category updated.');
                }
            }
        }
    } elseif ($act === 'delete' && $cid) {
        $courses = db_val("SELECT COUNT(*) FROM courses WHERE category_id=?", [$cid]);
        if ($courses > 0) {
            flash('error', 'Cannot delete this category because it contains courses.');
        } else {
            db_exec("DELETE FROM categories WHERE id=?", [$cid]);
            flash('success', 'Category deleted.');
        }
    }
    redirect(APP_URL . '/admin/categories.php');
}

$categories = db_query("SELECT * FROM categories ORDER BY sort_order ASC, id DESC");
$page_title = 'Manage Categories';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($page_title) ?> — <?= APP_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@700;800&family=Hind+Siliguri:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<script>const APP_URL='<?= APP_URL ?>';const CSRF_TOKEN='<?= csrf_token() ?>';</script>
</head>
<body>
<div class="admin-layout">
<?php include __DIR__.'/includes/sidebar.php'; ?>
<div class="admin-content">
<?php foreach(get_flash() as $f): ?>
<div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:16px">
    <span class="material-icons-round"><?= $f['type']==='success'?'check_circle':'error' ?></span>
    <span><?= e($f['message']) ?></span>
</div>
<?php endforeach; ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6)">
    <h1 style="font-size:var(--text-2xl)">Categories</h1>
    <button class="btn btn-primary btn-sm" onclick="openModal('add')">
        <span class="material-icons-round">add</span> New Category
    </button>
</div>

<!-- Categories table -->
<div class="data-table-wrap">
<table class="data-table">
<thead><tr><th>Icon</th><th>Category Name</th><th>Slug</th><th>Sort</th><th>Action</th></tr></thead>
<tbody>
<?php if (empty($categories)): ?>
<tr><td colspan="5" style="text-align:center;padding:40px;color:var(--c-ink-muted)">No categories found</td></tr>
<?php else: foreach ($categories as $c): ?>
<tr>
    <td>
        <?php if ($c['icon']): ?>
        <span class="material-icons-round" style="color:<?= e($c['color'] ?: 'var(--c-ink)') ?>;font-size:24px"><?= e($c['icon']) ?></span>
        <?php else: ?>
        <span style="color:var(--c-ink-muted)">—</span>
        <?php endif; ?>
    </td>
    <td>
        <div style="font-weight:700"><?= e($c['name_bn']) ?></div>
        <?php if ($c['name_en']): ?><div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= e($c['name_en']) ?></div><?php endif; ?>
    </td>
    <td style="font-family:var(--font-latin);font-size:var(--text-sm)"><?= e($c['slug']) ?></td>
    <td><?= (int)$c['sort_order'] ?></td>
    <td>
        <div style="display:flex;gap:4px">
            <button class="btn btn-ghost btn-sm" onclick="openModal('edit', <?= htmlspecialchars(json_encode($c)) ?>)">
                <span class="material-icons-round" style="font-size:14px">edit</span>
            </button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this category?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="category_id" value="<?= (int)$c['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--c-error)">
                    <span class="material-icons-round" style="font-size:14px">delete</span>
                </button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>

<!-- Add/Edit Modal -->
<div class="modal" id="catModal">
    <div class="modal-overlay" onclick="closeModal()"></div>
    <div class="modal-content" style="max-width:500px">
        <h2 id="modalTitle" style="margin-bottom:var(--sp-4)">Add Category</h2>
        <form method="POST" id="catForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="category_id" id="formId" value="">
            
            <div class="form-group">
                <label class="form-label">Category Name (Bangla) *</label>
                <input type="text" name="name_bn" id="formNameBn" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Category Name (English)</label>
                <input type="text" name="name_en" id="formNameEn" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Slug *</label>
                <input type="text" name="slug" id="formSlug" class="form-control" required pattern="[a-z0-9\-]+">
                <small style="color:var(--c-ink-muted)">URL friendly name. Lowercase and hyphens only.</small>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)">
                <div class="form-group">
                    <label class="form-label">Material Icon</label>
                    <input type="text" name="icon" id="formIcon" class="form-control" placeholder="e.g. school">
                </div>
                <div class="form-group">
                    <label class="form-label">Color (Hex)</label>
                    <input type="text" name="color" id="formColor" class="form-control" placeholder="#5B21B6">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Sort Order</label>
                <input type="number" name="sort_order" id="formSort" class="form-control" value="0">
            </div>
            <div style="text-align:right">
                <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

</div>
</div>

<script>
const formNameBn = document.getElementById('formNameBn');
const formSlug = document.getElementById('formSlug');

formNameBn.addEventListener('input', () => {
    if (document.getElementById('formAction').value === 'add') {
        formSlug.value = formNameBn.value
            .toLowerCase()
            .replace(/[ঀ-৿]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }
});

function openModal(act, data = null) {
    document.getElementById('modalTitle').innerText = act === 'add' ? 'Add Category' : 'Edit Category';
    document.getElementById('formAction').value = act;
    
    if (data) {
        document.getElementById('formId').value = data.id;
        document.getElementById('formNameBn').value = data.name_bn;
        document.getElementById('formNameEn').value = data.name_en;
        document.getElementById('formSlug').value = data.slug;
        document.getElementById('formIcon').value = data.icon;
        document.getElementById('formColor').value = data.color;
        document.getElementById('formSort').value = data.sort_order;
    } else {
        document.getElementById('formId').value = '';
        document.getElementById('catForm').reset();
    }
    
    document.getElementById('catModal').classList.add('active');
}
function closeModal() {
    document.getElementById('catModal').classList.remove('active');
}
</script>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
