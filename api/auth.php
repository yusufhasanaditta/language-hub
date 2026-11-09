<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();

$raw   = file_get_contents('php://input');
$body  = $raw ? (json_decode($raw, true) ?? []) : [];
$input = array_merge($_POST, $body);

$action = sanitize_string($input['action'] ?? '', 30);
$token  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    json_error('Invalid CSRF token', 403);
}

// ── send_otp ──────────────────────────────────────────────────────────────────
if ($action === 'send_otp') {
    rate_limit('otp_send', 5);

    $phone = sanitize_string($input['phone'] ?? '', 15);
    if (!validate_phone($phone)) json_error('Invalid phone number');

    $user = db_row("SELECT id, name, is_active FROM users WHERE phone=?", [$phone]);
    if (!$user) json_error('No account found with this phone number');
    if (!$user['is_active']) json_error('Account is inactive. Please contact support.');

    $otp = generate_otp();
    store_otp($phone, $otp, 'login');
    send_otp($phone, $otp);

    $response = ['sent' => true, 'phone' => $phone];
    if (OTP_DEMO_MODE) $response['demo_otp'] = $otp;
    json_success($response);
}

// ── verify_otp_login ──────────────────────────────────────────────────────────
if ($action === 'verify_otp') {
    rate_limit('otp_verify', 10);

    $phone = sanitize_string($input['phone'] ?? '', 15);
    $otp   = sanitize_string($input['otp'] ?? '', 10);

    if (!validate_phone($phone) || strlen($otp) < 4) json_error('Invalid input');

    check_login_attempts($phone);

    if (!verify_otp($phone, $otp, 'login')) {
        record_login_attempt($phone);
        json_error('Incorrect or expired OTP');
    }

    $user = db_row("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.phone=? AND u.is_active=1", [$phone]);
    if (!$user) json_error('Account not found');

    clear_login_attempts($phone);
    login_user($user);

    $redirect = $_SESSION['redirect_after_login'] ?? APP_URL . '/dashboard.php';
    unset($_SESSION['redirect_after_login']);
    json_success(['redirect' => $redirect]);
}

json_error('Unknown action');
