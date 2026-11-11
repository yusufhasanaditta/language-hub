<?php
defined('B_ACADEMY') or die('Direct access forbidden.');

// ── App ──────────────────────────────────────────────────────────────────────
define('APP_NAME',    'Language Hub');
define('APP_TAGLINE', 'নতুন ভাষা, নতুন সুযোগ');
define('APP_VERSION', '1.2.1');
// Auto: localhost = development, live server = production
$_host_raw  = $_SERVER['HTTP_HOST'] ?? '';
$_server_ip = $_SERVER['SERVER_ADDR'] ?? '';
$_isLocal = stripos($_SERVER['DOCUMENT_ROOT'] ?? '', 'xampp') !== false
         || in_array($_server_ip, ['127.0.0.1', '::1'], true)
         || in_array($_host_raw, ['localhost', '127.0.0.1', '::1', '192.168.0.104', '192.168.68.76'], true)
         || str_starts_with($_host_raw, 'localhost:');
define('APP_ENV', $_isLocal ? 'development' : 'production');
// Running on Vercel (serverless): settings come from environment variables and
// only /tmp is writable.
define('ON_VERCEL', getenv('VERCEL') === '1');

// Auto-detect base URL robustly
$_protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (ON_VERCEL && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) ? 'https' : 'http';
$_host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$docRoot   = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
$appRoot   = str_replace('\\', '/', dirname(__DIR__));
if ($docRoot !== '' && str_starts_with($appRoot, $docRoot)) {
    $_base = substr($appRoot, strlen($docRoot));
} else {
    // The project lives outside the web root (e.g. on the Desktop, linked into
    // htdocs with a junction). Work out the URL prefix from the running script:
    // strip the script's path-inside-the-app off the end of its URL path.
    $_scriptReal = str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $_scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $_inApp      = str_starts_with($_scriptReal, $appRoot) ? substr($_scriptReal, strlen($appRoot)) : '';
    $_base       = ($_inApp !== '' && str_ends_with($_scriptName, $_inApp))
        ? substr($_scriptName, 0, -strlen($_inApp))
        : '/' . basename($appRoot);
}
$_base     = rtrim(str_replace(' ', '%20', $_base), '/');
define('APP_URL',  $_protocol . '://' . $_host . $_base);
define('APP_ROOT', dirname(__DIR__));

// ── Secrets (config/secrets.php — never committed) ──────────────────────────
// On Vercel the same names are read from Project → Settings → Environment
// Variables instead of a file.
if (is_file(__DIR__ . '/secrets.php')) {
    require_once __DIR__ . '/secrets.php';
} elseif (ON_VERCEL) {
    foreach (['PROD_DB_HOST', 'PROD_DB_PORT', 'PROD_DB_NAME', 'PROD_DB_USER', 'PROD_DB_PASS', 'PROD_DB_SSL',
              'VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY',
              'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM_NAME'] as $_k) {
        $_v = getenv($_k);
        if ($_v !== false && !defined($_k)) define($_k, $_v);
    }
} elseif (!$_isLocal) {
    http_response_code(500);
    die('Server configuration missing.');
}

// ── Database ─────────────────────────────────────────────────────────────────
if ($_isLocal) {
    define('DB_HOST', 'localhost');
    define('DB_PORT', 3306);
    define('DB_NAME', 'language_hub');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_SSL',  false);
} else {
    define('DB_HOST', defined('PROD_DB_HOST') ? PROD_DB_HOST : 'localhost');
    define('DB_PORT', defined('PROD_DB_PORT') ? (int)PROD_DB_PORT : 3306);
    define('DB_NAME', defined('PROD_DB_NAME') ? PROD_DB_NAME : '');
    define('DB_USER', defined('PROD_DB_USER') ? PROD_DB_USER : '');
    define('DB_PASS', defined('PROD_DB_PASS') ? PROD_DB_PASS : '');
    // Cloud MySQL (TiDB, Aiven …) needs TLS; a cPanel localhost database does not.
    define('DB_SSL',  defined('PROD_DB_SSL') ? filter_var(PROD_DB_SSL, FILTER_VALIDATE_BOOLEAN) : ON_VERCEL);
}
define('DB_CHARSET', 'utf8mb4');

// ── Security ─────────────────────────────────────────────────────────────────
define('CSRF_TOKEN_LENGTH',      32);
define('SESSION_LIFETIME',       2592000);   // 30 days
define('MAX_LOGIN_ATTEMPTS',     5);
define('LOGIN_LOCKOUT_MINUTES',  15);
define('RATE_LIMIT_PER_MINUTE',  120);    // requests per IP per minute
define('PASSWORD_MIN_LENGTH',    8);
define('BCRYPT_COST',            12);

// ── OTP ──────────────────────────────────────────────────────────────────────
define('OTP_LENGTH',          6);
define('OTP_EXPIRY_MINUTES',  10);
define('OTP_DEMO_MODE',       false);  // set false in production (use real SMS)

// ── Pagination ───────────────────────────────────────────────────────────────
define('COURSES_PER_PAGE',  12);
define('USERS_PER_PAGE',    20);

// ── File Uploads ─────────────────────────────────────────────────────────────
define('MAX_UPLOAD_MB',       10);
define('UPLOAD_PATH',         ON_VERCEL ? '/tmp/uploads/' : APP_ROOT . '/uploads/');
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);

// ── Currency ─────────────────────────────────────────────────────────────────
// Prices render as plain numbers — no currency symbol anywhere.
define('APP_CURRENCY',    'BDT');   // used for gateway/API calls only, never printed

// ── Cache ─────────────────────────────────────────────────────────────────────
define('CACHE_PATH',      ON_VERCEL ? '/tmp/lh-cache/' : APP_ROOT . '/cache/');
define('CACHE_DEFAULT_TTL', 300); // 5 minutes

// ── Web Push (VAPID) ──────────────────────────────────────────────────────────
// VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY are defined in config/secrets.php.
// Changing them invalidates every device that has already subscribed.

// ── Roles ─────────────────────────────────────────────────────────────────────
define('ROLE_STUDENT',    1);
define('ROLE_TEACHER',    2);
define('ROLE_TA',         3);
define('ROLE_MODERATOR',  4);
define('ROLE_OPS',        5);
define('ROLE_ADMIN',      6);

// ── Error handling ───────────────────────────────────────────────────────────
if (APP_ENV === 'production') {
    ini_set('display_errors', 0);
    error_reporting(0);
} else {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

// ── Session security ─────────────────────────────────────────────────────────
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime',  SESSION_LIFETIME);
ini_set('session.use_strict_mode', 1);
if (APP_ENV === 'production') {
    ini_set('session.cookie_secure', 1);
}

// ── Timezone ─────────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Dhaka');

// ── Security headers ─────────────────────────────────────────────────────────
function send_security_headers(): void {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (APP_ENV === 'production') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
