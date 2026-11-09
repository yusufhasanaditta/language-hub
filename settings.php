<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_login();

$user = current_user();
$uid  = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $act = sanitize_string($_POST['action'] ?? '', 20);

    if ($act === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$user['password_hash']) {
            flash('error', 'This account has no password set (OTP login).');
        } elseif (!password_verify($current, $user['password_hash'])) {
            flash('error', 'Your current password is incorrect.');
        } elseif (!validate_password($new)) {
            flash('error', 'New password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.');
        } elseif ($new !== $confirm) {
            flash('error', 'New password and confirmation do not match.');
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
            db_exec("UPDATE users SET password_hash=? WHERE id=?", [$hash, $uid]);
            flash('success', 'Password changed successfully.');
        }
        redirect(APP_URL . '/settings.php#password');
    }

    if ($act === 'update_email') {
        $email = sanitize_string($_POST['email'] ?? '', 180);
        if ($email && !validate_email($email)) {
            flash('error', 'Please enter a valid email address.');
        } elseif ($email && db_val("SELECT 1 FROM users WHERE email=? AND id!=?", [$email, $uid])) {
            flash('error', 'This email is already in use.');
        } else {
            db_exec("UPDATE users SET email=? WHERE id=?", [$email ?: null, $uid]);
            flash('success', 'Email updated.');
        }
        redirect(APP_URL . '/settings.php#email');
    }

    if ($act === 'delete_account') {
        $confirm = sanitize_string($_POST['confirm_delete'] ?? '', 10);
        $pass    = $_POST['delete_password'] ?? '';
        if ($confirm !== 'DELETE') {
            flash('error', 'Confirmation is incorrect. Please type DELETE.');
            redirect(APP_URL . '/settings.php#danger');
        }
        if ($user['password_hash'] && !password_verify($pass, $user['password_hash'])) {
            flash('error', 'Incorrect password.');
            redirect(APP_URL . '/settings.php#danger');
        }
        // Soft delete
        db_exec("UPDATE users SET is_active=0, phone=CONCAT('deleted_',id,'_',phone), email=NULL WHERE id=?", [$uid]);
        session_destroy();
        redirect(APP_URL . '/login.php?deleted=1');
    }
}

$user = current_user(); // refresh
$page_title = 'Account Settings';
$extra_css = '<style>
.settings-section{background:#fff;border-radius:var(--r-xl);border:1px solid var(--c-border);margin-bottom:var(--sp-6);overflow:hidden}
.settings-section-header{padding:var(--sp-5) var(--sp-6);border-bottom:1px solid var(--c-border);display:flex;align-items:center;gap:12px}
.settings-section-body{padding:var(--sp-6)}
.danger-zone{border-color:#FCA5A5;background:#FFF5F5}
.danger-zone .settings-section-header{background:#FFF0F0;border-bottom-color:#FCA5A5}
</style>';
include __DIR__ . '/includes/header.php';
?>

<div style="background:linear-gradient(135deg,#1A1033,#2D1B8A);padding:var(--sp-10) 0 var(--sp-8);color:#fff">
    <div class="container">
        <a href="<?= APP_URL ?>/profile.php" style="display:inline-flex;align-items:center;gap:6px;color:rgba(255,255,255,.6);font-size:var(--text-sm);text-decoration:none;margin-bottom:var(--sp-4)">
            <span class="material-icons-round" style="font-size:16px">arrow_back</span> Back to Profile
        </a>
        <div style="display:flex;align-items:center;gap:var(--sp-4)">
            <div style="width:52px;height:52px;border-radius:var(--r-lg);background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <span class="material-icons-round" style="font-size:28px;color:#fff">settings</span>
            </div>
            <div>
                <h1 style="color:#fff;font-size:clamp(1.25rem,3vw,1.75rem);margin-bottom:4px">Account Settings</h1>
                <p style="color:rgba(255,255,255,.65);margin:0;font-size:var(--text-sm)"><?= e($user['name']) ?> · <?= e($user['phone']) ?></p>
            </div>
        </div>
    </div>
</div>

<div class="container" style="padding-top:var(--sp-8);padding-bottom:var(--sp-16);max-width:680px">

<?php foreach (get_flash() as $f): ?>
<div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:16px">
    <span class="material-icons-round"><?= $f['type']==='success'?'check_circle':'error' ?></span>
    <span><?= e($f['message']) ?></span>
</div>
<?php endforeach; ?>

<!-- Change password -->
<div class="settings-section" id="password">
    <div class="settings-section-header">
        <span class="material-icons-round" style="color:var(--c-primary)">lock</span>
        <div>
            <div style="font-weight:700;font-size:var(--text-base)">Change Password</div>
            <div style="font-size:var(--text-xs);color:var(--c-ink-muted)">Use a strong password</div>
        </div>
    </div>
    <div class="settings-section-body">
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_password">
            <div class="form-group">
                <label class="form-label">Current Password *</label>
                <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
            </div>
            <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)">
                <div class="form-group">
                    <label class="form-label">New Password *</label>
                    <input type="password" name="new_password" class="form-control" required autocomplete="new-password"
                           placeholder="At least 8 characters">
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm *</label>
                    <input type="password" name="confirm_password" class="form-control" required autocomplete="new-password">
                </div>
            </div>
            <div class="form-hint" style="margin-bottom:var(--sp-4)">Any password you'll remember is fine — just 8+ characters.</div>
            <button type="submit" class="btn btn-primary">
                <span class="material-icons-round">lock_reset</span> Change Password
            </button>
        </form>
    </div>
</div>

<!-- Update email -->
<div class="settings-section" id="email">
    <div class="settings-section-header">
        <span class="material-icons-round" style="color:var(--c-primary)">email</span>
        <div>
            <div style="font-weight:700;font-size:var(--text-base)">Update Email</div>
            <div style="font-size:var(--text-xs);color:var(--c-ink-muted)">Current: <?= e($user['email'] ?? 'Not set') ?></div>
        </div>
    </div>
    <div class="settings-section-body">
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_email">
            <div class="form-group">
                <label class="form-label">New Email</label>
                <input type="email" name="email" class="form-control" value="<?= e($user['email'] ?? '') ?>" placeholder="yourname@example.com">
                <div class="form-hint">Leaving this blank will remove your email.</div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">
                <span class="material-icons-round">save</span> Save
            </button>
        </form>
    </div>
</div>

<!-- Account info -->
<div class="settings-section">
    <div class="settings-section-header">
        <span class="material-icons-round" style="color:var(--c-primary)">info</span>
        <div>
            <div style="font-weight:700;font-size:var(--text-base)">Account Information</div>
        </div>
    </div>
    <div class="settings-section-body">
        <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)">
            <?php $infoRows = [
                ['badge', 'Member ID', '#' . $uid],
                ['phone', 'Phone Number', $user['phone'] ?? '—'],
                ['calendar_today', 'Joined On', date('d F Y', strtotime($user['created_at']))],
                ['verified_user', 'Verification Status', $user['is_verified'] ? 'Verified' : 'Pending'],
            ];
            foreach ($infoRows as [$icon, $lab, $val]): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:var(--sp-3);background:var(--c-surface-2);border-radius:var(--r-md)">
                <span class="material-icons-round" style="font-size:18px;color:var(--c-primary);flex-shrink:0"><?= $icon ?></span>
                <div>
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted)"><?= $lab ?></div>
                    <div style="font-size:var(--text-sm);font-weight:700;color:var(--c-ink)"><?= e($val) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Quick links -->
<div class="settings-section">
    <div class="settings-section-header">
        <span class="material-icons-round" style="color:var(--c-primary)">link</span>
        <div><div style="font-weight:700;font-size:var(--text-base)">Quick Links</div></div>
    </div>
    <div class="settings-section-body" style="padding:var(--sp-4) var(--sp-6)">
        <?php $quickLinks = [
            [APP_URL.'/', 'home', 'Home Page'],
            [APP_URL.'/courses.php', 'school', 'Courses Page'],
            [APP_URL.'/stories.php', 'auto_stories', 'Success Stories'],
            [APP_URL.'/profile.php', 'person', 'Edit Profile'],
            [APP_URL.'/orders.php', 'receipt', 'Order History'],
            [APP_URL.'/certificates.php', 'workspace_premium', 'My Certificates'],
            [APP_URL.'/messages.php', 'chat', 'Messages'],
        ];
        foreach ($quickLinks as $ql): ?>
        <a href="<?= $ql[0] ?>" style="display:flex;align-items:center;gap:var(--sp-3);padding:var(--sp-2) 0;color:var(--c-ink-light);font-size:var(--text-sm);font-weight:600;text-decoration:none;border-bottom:1px solid var(--c-border)">
            <span class="material-icons-round" style="font-size:18px;color:var(--c-primary)"><?= $ql[1] ?></span>
            <?= $ql[2] ?>
            <span class="material-icons-round" style="font-size:14px;margin-left:auto;color:var(--c-ink-faint)">chevron_right</span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Danger zone -->
<div class="settings-section danger-zone" id="danger">
    <div class="settings-section-header">
        <span class="material-icons-round" style="color:var(--c-error)">warning</span>
        <div>
            <div style="font-weight:700;font-size:var(--text-base);color:var(--c-error)">Danger Zone</div>
            <div style="font-size:var(--text-xs);color:var(--c-ink-muted)">These actions cannot be undone</div>
        </div>
    </div>
    <div class="settings-section-body">
        <details>
            <summary style="cursor:pointer;color:var(--c-error);font-weight:700;font-size:var(--text-sm);margin-bottom:var(--sp-4);list-style:none">
                <span class="material-icons-round" style="font-size:16px;vertical-align:middle">expand_more</span> Delete Account
            </summary>
            <div style="background:#FFF5F5;border-radius:var(--r-lg);padding:var(--sp-5);margin-top:var(--sp-3)">
                <p style="color:var(--c-error);font-size:var(--text-sm);margin-bottom:var(--sp-4)">
                    <strong>Warning:</strong> Deleting your account will permanently remove all your course access, exam history, and certificates.
                </p>
                <form method="POST" onsubmit="return confirm('Are you sure you want to delete your account? This cannot be undone.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_account">
                    <div class="form-group">
                        <label class="form-label">Type <strong>DELETE</strong> to confirm</label>
                        <input type="text" name="confirm_delete" class="form-control" placeholder="DELETE" pattern="DELETE" required>
                    </div>
                    <?php if ($user['password_hash']): ?>
                    <div class="form-group">
                        <label class="form-label">Confirm Password</label>
                        <input type="password" name="delete_password" class="form-control" required>
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn" style="background:var(--c-error);color:#fff;border:none">
                        <span class="material-icons-round">delete_forever</span> Permanently Delete Account
                    </button>
                </form>
            </div>
        </details>
    </div>
</div>

</div>

<style>
@media(max-width:600px){ .settings-section-body .grid-2{grid-template-columns:1fr !important} }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
