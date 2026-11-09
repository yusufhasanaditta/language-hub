<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_admin();

// Save settings helper
function save_setting(string $key, string $value): void {
    db_exec("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?", [$key,$value,$value]);
    cache_delete('setting_' . $key);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $act = sanitize_string($_POST['action'] ?? '', 30);

    if ($act === 'general') {
        save_setting('site_name',        sanitize_string($_POST['site_name'] ?? '', 100));
        save_setting('site_tagline',     sanitize_string($_POST['site_tagline'] ?? '', 200));
        save_setting('site_description', sanitize_string($_POST['site_description'] ?? '', 500));
        save_setting('contact_email',    sanitize_string($_POST['contact_email'] ?? '', 200));
        save_setting('contact_phone',    sanitize_string($_POST['contact_phone'] ?? '', 50));
        save_setting('facebook_url',     sanitize_string($_POST['facebook_url'] ?? '', 300));
        save_setting('youtube_url',      sanitize_string($_POST['youtube_url'] ?? '', 300));
        flash('success', 'General settings saved.');

    } elseif ($act === 'why_us') {
        // We will just store the raw text and parse it by newlines
        $points = trim(strip_tags($_POST['why_us_points'] ?? ''));
        save_setting('why_us_points', $points);
        flash('success', 'Why Us points saved.');

    } elseif ($act === 'payment') {
        save_setting('bkash_number',   sanitize_string($_POST['bkash_number'] ?? '', 20));
        save_setting('nagad_number',   sanitize_string($_POST['nagad_number'] ?? '', 20));
        save_setting('rocket_number',  sanitize_string($_POST['rocket_number'] ?? '', 20));
        save_setting('bank_name',      sanitize_string($_POST['bank_name'] ?? '', 100));
        save_setting('bank_account',   sanitize_string($_POST['bank_account'] ?? '', 50));
        flash('success', 'Payment settings saved.');

    } elseif ($act === 'sslcommerz') {
        save_setting('sslcz_store_id',   sanitize_string($_POST['sslcz_store_id'] ?? '', 100));
        save_setting('sslcz_store_pass', sanitize_string($_POST['sslcz_store_pass'] ?? '', 100));
        save_setting('sslcz_is_live',    isset($_POST['sslcz_is_live']) ? '1' : '0');
        save_setting('sslcz_enabled',    isset($_POST['sslcz_enabled']) ? '1' : '0');
        flash('success', 'SSLCommerz settings saved.');

    } elseif ($act === 'maintenance') {
        save_setting('maintenance_mode', isset($_POST['maintenance_mode']) ? '1' : '0');
        save_setting('maintenance_msg',  sanitize_string($_POST['maintenance_msg'] ?? '', 500));
        flash('success', 'Maintenance settings saved.');

    } elseif ($act === 'smtp') {
        save_setting('smtp_host',     sanitize_string($_POST['smtp_host'] ?? '', 200));
        save_setting('smtp_port',     sanitize_string($_POST['smtp_port'] ?? '587', 10));
        save_setting('smtp_user',     sanitize_string($_POST['smtp_user'] ?? '', 200));
        save_setting('smtp_pass',     sanitize_string($_POST['smtp_pass'] ?? '', 200));
        save_setting('smtp_from',     sanitize_string($_POST['smtp_from'] ?? '', 200));
        flash('success', 'Email settings saved.');

    } elseif ($act === 'sms') {
        save_setting('sms_bulksmsbd_api_key',   sanitize_string($_POST['sms_bulksmsbd_api_key'] ?? '', 200));
        save_setting('sms_bulksmsbd_sender_id', sanitize_string($_POST['sms_bulksmsbd_sender_id'] ?? '', 50));
        save_setting('sms_bulksmsbd_enabled',   isset($_POST['sms_bulksmsbd_enabled']) ? '1' : '0');
        flash('success', 'SMS gateway settings saved.');

    } elseif ($act === 'sms_test') {
        $testNumber = sanitize_string($_POST['test_number'] ?? '', 15);
        if (!validate_phone($testNumber)) {
            flash('error', 'Enter a valid phone number (01XXXXXXXXX) to send the test SMS to.');
        } elseif (!sms_gateway_configured()) {
            flash('error', 'Save your API key, Sender ID, and enable the gateway before sending a test.');
        } else {
            $result = send_sms_bulksmsbd($testNumber, 'This is a test SMS from ' . APP_NAME . '. Your SMS gateway is working.');
            if ($result['success']) {
                flash('success', 'Test SMS sent to ' . $testNumber . '.');
            } else {
                flash('error', 'Test SMS failed: ' . e($result['raw']));
            }
        }
    } elseif ($act === 'ai_integrations') {
        save_setting('gemini_api_key', sanitize_string($_POST['gemini_api_key'] ?? '', 200));
        flash('success', 'AI settings saved.');
    }
    redirect(APP_URL . '/admin/settings.php');
}

// Load current settings
$s = [];
$rows = db_query("SELECT setting_key, setting_value FROM settings");
foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_value'];
$gs = fn($k, $d='') => $s[$k] ?? $d;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Settings — <?= APP_NAME ?></title>
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

<h1 style="font-size:var(--text-2xl);margin-bottom:var(--sp-8)">System Settings</h1>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-6)">

<!-- General settings -->
<div class="card" style="padding:var(--sp-6)">
    <h3 style="margin-bottom:var(--sp-5)">General Info</h3>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="general">
        <div class="form-group"><label class="form-label">Site Name</label><input type="text" name="site_name" class="form-control" value="<?= e($gs('site_name',APP_NAME)) ?>"></div>
        <div class="form-group"><label class="form-label">Tagline</label><input type="text" name="site_tagline" class="form-control" value="<?= e($gs('site_tagline',APP_TAGLINE)) ?>"></div>
        <div class="form-group"><label class="form-label">Short Description</label><textarea name="site_description" class="form-control" rows="3"><?= e($gs('site_description')) ?></textarea></div>
        <div class="form-group"><label class="form-label">Contact Email</label><input type="email" name="contact_email" class="form-control" value="<?= e($gs('contact_email')) ?>"></div>
        <div class="form-group"><label class="form-label">Contact Phone</label><input type="tel" name="contact_phone" class="form-control" value="<?= e($gs('contact_phone')) ?>"></div>
        <div class="form-group"><label class="form-label">Facebook Page URL</label><input type="url" name="facebook_url" class="form-control" value="<?= e($gs('facebook_url')) ?>"></div>
        <div class="form-group"><label class="form-label">YouTube Channel URL</label><input type="url" name="youtube_url" class="form-control" value="<?= e($gs('youtube_url')) ?>"></div>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
</div>

<!-- Why Us settings -->
<div class="card" style="padding:var(--sp-6)">
    <h3 style="margin-bottom:var(--sp-5)">Homepage "Why Us" Features</h3>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="why_us">
        <div class="form-group">
            <label class="form-label">Points (One per line)</label>
            <textarea name="why_us_points" class="form-control" rows="12" placeholder="Live Classes&#10;Quizzes&#10;Support"><?= e($gs('why_us_points', "Directly watch live classes\nPractice quizzes on every topic\nQuality education at a low cost\nGet guidelines from top achievers\nRecorded classes available for revision\nDedicated doubt-solving support\nGet attractive feedback\nFree classes and study materials")) ?></textarea>
            <div style="font-size:12px;color:#666;margin-top:4px">Enter each feature point on a new line. These will appear on the homepage with checkmarks.</div>
        </div>
        <button type="submit" class="btn btn-primary">Save Points</button>
    </form>
</div>

<!-- Payment settings -->
<div class="card" style="padding:var(--sp-6)">
    <h3 style="margin-bottom:var(--sp-5)">Payment Settings</h3>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="payment">
        <div class="form-group"><label class="form-label">bKash Number</label><input type="tel" name="bkash_number" class="form-control" value="<?= e($gs('bkash_number')) ?>" placeholder="01XXXXXXXXX"></div>
        <div class="form-group"><label class="form-label">Nagad Number</label><input type="tel" name="nagad_number" class="form-control" value="<?= e($gs('nagad_number')) ?>" placeholder="01XXXXXXXXX"></div>
        <div class="form-group"><label class="form-label">Rocket Number</label><input type="tel" name="rocket_number" class="form-control" value="<?= e($gs('rocket_number')) ?>" placeholder="01XXXXXXXXX"></div>
        <div class="form-group"><label class="form-label">Bank Name</label><input type="text" name="bank_name" class="form-control" value="<?= e($gs('bank_name')) ?>"></div>
        <div class="form-group"><label class="form-label">Bank Account Number</label><input type="text" name="bank_account" class="form-control" value="<?= e($gs('bank_account')) ?>"></div>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
</div>

<!-- SSLCommerz settings -->
<div class="card" style="padding:var(--sp-6)">
    <h3 style="margin-bottom:var(--sp-5)">SSLCommerz Gateway</h3>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="sslcommerz">
        
        <div class="form-group" style="display:flex;align-items:center;gap:12px;margin-bottom:var(--sp-4)">
            <input type="checkbox" name="sslcz_enabled" id="sslcz_enabled" style="width:18px;height:18px" <?= $gs('sslcz_enabled')==='1'?'checked':'' ?>>
            <label for="sslcz_enabled" style="font-weight:700;cursor:pointer">Enable SSLCommerz Payment Gateway</label>
        </div>

        <div class="form-group"><label class="form-label">Store ID</label><input type="text" name="sslcz_store_id" class="form-control" value="<?= e($gs('sslcz_store_id')) ?>"></div>
        <div class="form-group"><label class="form-label">Store Password</label><input type="text" name="sslcz_store_pass" class="form-control" value="<?= e($gs('sslcz_store_pass')) ?>"></div>
        
        <div class="form-group" style="display:flex;align-items:center;gap:12px;margin-top:var(--sp-4);margin-bottom:var(--sp-4)">
            <input type="checkbox" name="sslcz_is_live" id="sslcz_is_live" style="width:18px;height:18px" <?= $gs('sslcz_is_live')==='1'?'checked':'' ?>>
            <label for="sslcz_is_live" style="font-weight:700;cursor:pointer;color:#dc2626">Live Mode (Uncheck for Sandbox/Test Mode)</label>
        </div>

        <button type="submit" class="btn btn-primary">Save SSLCommerz Settings</button>
    </form>
</div>

<!-- Maintenance mode -->
<div class="card" style="padding:var(--sp-6)">
    <h3 style="margin-bottom:var(--sp-5)">Maintenance Mode</h3>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="maintenance">
        <div class="form-group" style="display:flex;align-items:center;gap:12px">
            <input type="checkbox" name="maintenance_mode" id="mmode" style="width:18px;height:18px" <?= $gs('maintenance_mode')==='1'?'checked':'' ?>>
            <label for="mmode" style="font-weight:700;cursor:pointer">Enable maintenance mode</label>
        </div>
        <div class="form-group">
            <label class="form-label">Maintenance Message</label>
            <textarea name="maintenance_msg" class="form-control" rows="3" placeholder="The site is temporarily down..."><?= e($gs('maintenance_msg')) ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
</div>

<!-- SMTP settings -->
<div class="card" style="padding:var(--sp-6)">
    <h3 style="margin-bottom:var(--sp-5)">Email (SMTP) Settings</h3>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="smtp">
        <div class="form-group"><label class="form-label">SMTP Host</label><input type="text" name="smtp_host" class="form-control" value="<?= e($gs('smtp_host','smtp.gmail.com')) ?>"></div>
        <div class="form-group"><label class="form-label">SMTP Port</label><input type="number" name="smtp_port" class="form-control" value="<?= e($gs('smtp_port','587')) ?>"></div>
        <div class="form-group"><label class="form-label">Email / Username</label><input type="email" name="smtp_user" class="form-control" value="<?= e($gs('smtp_user')) ?>"></div>
        <div class="form-group"><label class="form-label">Password</label><input type="password" name="smtp_pass" class="form-control" placeholder="Leave blank to keep unchanged"></div>
        <div class="form-group"><label class="form-label">Sender Email</label><input type="email" name="smtp_from" class="form-control" value="<?= e($gs('smtp_from')) ?>"></div>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
</div>

<!-- SMS Gateway (BulkSMSBD) settings -->
<div class="card" style="padding:var(--sp-6)">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-5)">
        <h3 style="margin:0">SMS Gateway — BulkSMSBD</h3>
        <?php if (sms_gateway_configured()):
            $balance = get_sms_gateway_balance();
        ?>
        <span class="status-badge status-active" title="Live balance from BulkSMSBD">
            <?= $balance !== null ? 'Balance: ' . e($balance) : 'Connected' ?>
        </span>
        <?php else: ?>
        <span class="status-badge" style="background:var(--c-surface-hover);color:var(--c-ink-muted)">Not configured</span>
        <?php endif; ?>
    </div>
    <p style="font-size:var(--text-sm);color:var(--c-ink-muted);margin-top:0;margin-bottom:var(--sp-5)">
        This sends the login/registration OTP by real SMS through <a href="https://bulksmsbd.net" target="_blank" style="color:var(--c-primary)">BulkSMSBD</a>.
        Until this is filled in and enabled, OTPs are shown on-screen instead (demo mode) so login still works.
    </p>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="sms">
        <div class="form-group">
            <label class="form-label">API Key</label>
            <input type="text" name="sms_bulksmsbd_api_key" class="form-control" value="<?= e($gs('sms_bulksmsbd_api_key')) ?>" placeholder="Your BulkSMSBD API key" autocomplete="off">
        </div>
        <div class="form-group">
            <label class="form-label">Sender ID <span style="color:var(--c-danger,#dc3545)">(required)</span></label>
            <input type="text" name="sms_bulksmsbd_sender_id" class="form-control" value="<?= e($gs('sms_bulksmsbd_sender_id')) ?>" placeholder="e.g. 8809XXXXXXXX or your approved masking name" required>
            <p style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:4px">
                BulkSMSBD rejects every send without this. Copy the exact Sender ID shown in your BulkSMSBD panel under "Sender ID" — leaving it blank causes error 1003.
            </p>
        </div>
        <div class="form-group" style="display:flex;align-items:center;gap:12px">
            <input type="checkbox" name="sms_bulksmsbd_enabled" id="smsEnabled" style="width:18px;height:18px" <?= $gs('sms_bulksmsbd_enabled')==='1'?'checked':'' ?>>
            <label for="smsEnabled" style="font-weight:700;cursor:pointer">Send real SMS for OTP (turn off to fall back to on-screen demo OTP)</label>
        </div>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
    <form method="POST" style="margin-top:var(--sp-5);padding-top:var(--sp-5);border-top:1px solid var(--c-border);display:flex;gap:12px;align-items:flex-end">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="sms_test">
        <div class="form-group" style="flex:1;margin-bottom:0">
            <label class="form-label">Send a test SMS</label>
            <input type="tel" name="test_number" class="form-control" placeholder="01XXXXXXXXX">
        </div>
        <button type="submit" class="btn btn-outline">Send Test</button>
    </form>
</div>

<!-- AI Integrations -->
<div class="card" style="padding:var(--sp-6)">
    <h3 style="margin-bottom:var(--sp-5)">AI Integrations (Google Gemini)</h3>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ai_integrations">
        <div class="form-group">
            <label class="form-label">Google Gemini API Key</label>
            <input type="password" name="gemini_api_key" class="form-control" value="<?= e($gs('gemini_api_key')) ?>" placeholder="AIzaSy...">
            <div style="font-size:12px;color:#666;margin-top:4px">Used for automatically generating course details from thumbnails.</div>
        </div>
        <button type="submit" class="btn btn-primary">Save API Key</button>
    </form>
</div>

</div>

</div>
</div>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= APP_VERSION ?>" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>" defer></script>
</body></html>
