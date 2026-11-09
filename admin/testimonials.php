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
    $tid = sanitize_int($_POST['testimonial_id'] ?? 0);

    if ($act === 'add' || $act === 'edit') {
        $name = sanitize_string($_POST['name'] ?? '', 255);
        $batch = sanitize_string($_POST['batch'] ?? '', 255);
        $avatar = sanitize_string($_POST['avatar'] ?? '', 10);
        $color = sanitize_string($_POST['color'] ?? '#5B21B6', 20);
        $text = sanitize_string($_POST['text'] ?? '', 500);
        $rating = sanitize_int($_POST['rating'] ?? 5);
        $sort_order = sanitize_int($_POST['sort_order'] ?? 0);
        $is_approved = isset($_POST['is_approved']) ? 1 : 0;

        if ($act === 'add') {
            db_exec("INSERT INTO testimonials (name, batch, avatar, color, text, rating, sort_order, is_approved) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", 
                [$name, $batch, $avatar, $color, $text, $rating, $sort_order, $is_approved]);
            flash('success', 'Testimonial added.');
        } else {
            db_exec("UPDATE testimonials SET name=?, batch=?, avatar=?, color=?, text=?, rating=?, sort_order=?, is_approved=? WHERE id=?", 
                [$name, $batch, $avatar, $color, $text, $rating, $sort_order, $is_approved, $tid]);
            flash('success', 'Testimonial updated.');
        }
    } elseif ($act === 'approve' && $tid) {
        db_exec("UPDATE testimonials SET is_approved=1 WHERE id=?", [$tid]);
        flash('success', 'Testimonial approved.');
    } elseif ($act === 'reject' && $tid) {
        db_exec("UPDATE testimonials SET is_approved=0 WHERE id=?", [$tid]);
        flash('success', 'Testimonial rejected.');
    } elseif ($act === 'delete' && $tid) {
        db_exec("DELETE FROM testimonials WHERE id=?", [$tid]);
        flash('success', 'Testimonial deleted.');
    }
    redirect(APP_URL . '/admin/testimonials.php');
}

$testimonials = db_query("SELECT * FROM testimonials ORDER BY sort_order ASC, created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Testimonials — <?= APP_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@700;800&family=Hind+Siliguri:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= APP_VERSION ?>">
<script>const APP_URL='<?= APP_URL ?>';const CSRF_TOKEN='<?= csrf_token() ?>';</script>
<style>
.modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; }
.modal.active { display:flex; }
.modal-content { background:var(--c-surface); padding:24px; border-radius:12px; width:100%; max-width:500px; }
.modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
.modal-title { font-size:18px; font-weight:700; }
.modal-close { cursor:pointer; background:none; border:none; color:var(--c-ink-muted); }
</style>
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
    <h1 style="font-size:var(--text-2xl)">Home Testimonials</h1>
    <button class="btn btn-primary" onclick="openModal('add')">
        <span class="material-icons-round">add</span> Add Testimonial
    </button>
</div>

<!-- Testimonials table -->
<div class="data-table-wrap">
<table class="data-table">
<thead><tr><th>Avatar</th><th>Name & Batch</th><th>Text</th><th>Rating & Status</th><th>Sort</th><th>Action</th></tr></thead>
<tbody>
<?php if (empty($testimonials)): ?>
<tr><td colspan="6" style="text-align:center;padding:40px;color:var(--c-ink-muted)">No testimonials yet</td></tr>
<?php else: foreach ($testimonials as $t): ?>
<tr>
    <td>
        <div style="width:40px;height:40px;border-radius:50%;background:<?= e($t['color']) ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px">
            <?= e($t['avatar']) ?>
        </div>
    </td>
    <td>
        <div style="font-weight:700;font-size:var(--text-sm)"><?= e($t['name']) ?></div>
        <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= e($t['batch']) ?></div>
    </td>
    <td style="font-size:var(--text-sm);max-width:300px">
        <?= e(mb_substr($t['text'],0,100,'UTF-8')) ?><?= mb_strlen($t['text'],'UTF-8')>100?'…':'' ?>
    </td>
    <td>
        <div style="color:#F59E0B;margin-bottom:4px"><?= str_repeat('★',(int)$t['rating']) ?></div>
        <?php if ($t['is_approved']): ?>
            <span class="status-badge status-active">Approved</span>
        <?php else: ?>
            <span class="status-badge status-warning">Pending</span>
        <?php endif; ?>
    </td>
    <td><?= (int)$t['sort_order'] ?></td>
    <td>
        <div style="display:flex;gap:4px;flex-wrap:wrap">
            <?php if (!$t['is_approved']): ?>
            <form method="POST" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="testimonial_id" value="<?= (int)$t['id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm" style="font-size:11px"><span class="material-icons-round" style="font-size:13px">check</span></button>
            </form>
            <?php else: ?>
            <form method="POST" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="testimonial_id" value="<?= (int)$t['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" style="font-size:11px">Reject</button>
            </form>
            <?php endif; ?>
            <button class="btn btn-ghost btn-sm" onclick="openModal('edit', <?= htmlspecialchars(json_encode($t)) ?>)">
                <span class="material-icons-round" style="font-size:14px">edit</span>
            </button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this testimonial?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="testimonial_id" value="<?= (int)$t['id'] ?>">
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

<!-- Modal -->
<div id="testiModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-title" id="modalTitle">Add Testimonial</div>
            <button class="modal-close" onclick="closeModal()"><span class="material-icons-round">close</span></button>
        </div>
        <form method="POST" id="testiForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="testimonial_id" id="formId" value="">
            
            <div class="form-group">
                <label class="form-label">Name</label>
                <input type="text" name="name" id="formName" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Batch / Description</label>
                <input type="text" name="batch" id="formBatch" class="form-control" required placeholder="e.g., HSC 2025, Science">
            </div>
            <div style="display:flex; gap:16px;">
                <div class="form-group" style="flex:1">
                    <label class="form-label">Avatar Initial</label>
                    <input type="text" name="avatar" id="formAvatar" class="form-control" maxlength="2" required placeholder="e.g., R">
                </div>
                <div class="form-group" style="flex:1">
                    <label class="form-label">Color (Hex)</label>
                    <input type="text" name="color" id="formColor" class="form-control" required placeholder="e.g., #5B21B6">
                </div>
                <div class="form-group" style="flex:1">
                    <label class="form-label">Sort Order</label>
                    <input type="number" name="sort_order" id="formSort" class="form-control" value="0">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Text</label>
                <textarea name="text" id="formText" class="form-control" rows="4" required></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Rating</label>
                <select name="rating" id="formRating" class="form-control">
                    <option value="5">5 Stars</option>
                    <option value="4">4 Stars</option>
                    <option value="3">3 Stars</option>
                    <option value="2">2 Stars</option>
                    <option value="1">1 Star</option>
                </select>
            </div>
            <div class="form-group" style="display:flex;align-items:center;gap:12px;margin-bottom:var(--sp-4)">
                <input type="checkbox" name="is_approved" id="formApproved" style="width:18px;height:18px" checked>
                <label for="formApproved" style="font-weight:700;cursor:pointer">Approved (visible on homepage)</label>
            </div>
            <div style="text-align:right">
                <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(action, data = null) {
    document.getElementById('modalTitle').textContent = action === 'add' ? 'Add Testimonial' : 'Edit Testimonial';
    document.getElementById('formAction').value = action;
    
    if (action === 'edit' && data) {
        document.getElementById('formId').value = data.id;
        document.getElementById('formName').value = data.name;
        document.getElementById('formBatch').value = data.batch;
        document.getElementById('formAvatar').value = data.avatar;
        document.getElementById('formColor').value = data.color;
        document.getElementById('formSort').value = data.sort_order;
        document.getElementById('formText').value = data.text;
        document.getElementById('formRating').value = data.rating;
        document.getElementById('formApproved').checked = data.is_approved == 1;
    } else {
        document.getElementById('formId').value = '';
        document.getElementById('testiForm').reset();
        document.getElementById('formApproved').checked = true;
    }
    
    document.getElementById('testiModal').classList.add('active');
}

function closeModal() {
    document.getElementById('testiModal').classList.remove('active');
}
</script>

</div>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
