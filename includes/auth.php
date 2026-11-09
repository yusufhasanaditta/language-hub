<?php
defined('B_ACADEMY') or die('Direct access forbidden.');

// ── Session bootstrap ─────────────────────────────────────────────────────────

define('MAX_DEVICE_SESSIONS', 2);

function session_start_secure(): void {
    if (session_status() === PHP_SESSION_NONE) {
        if (defined('ON_VERCEL') && ON_VERCEL) {
            require_once __DIR__ . '/db_session.php';
            session_set_save_handler(new DbSessionHandler(), true);
        }
        session_name('lh_sess');
        session_start();
    }
    // Regenerate session ID every 30 minutes to prevent fixation
    if (!isset($_SESSION['_created'])) {
        $_SESSION['_created'] = time();
    } elseif (time() - $_SESSION['_created'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
    }

    // Session heartbeat + revocation check (once per 5 min)
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['_session_token'])) {
        if (!isset($_SESSION['_heartbeat']) || time() - $_SESSION['_heartbeat'] > 300) {
            $token = $_SESSION['_session_token'];
            $updated = db_exec("UPDATE user_sessions SET last_seen_at=NOW() WHERE session_token=?", [$token]);
            if ($updated === 0) {
                // Session was remotely revoked — force logout
                unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['_created'],
                      $_SESSION['_session_token'], $_SESSION['_heartbeat']);
                session_regenerate_id(true);
                return;
            }
            $_SESSION['_heartbeat'] = time();
        }
    }
}

// ── Auth checks ───────────────────────────────────────────────────────────────

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function current_user(): ?array {
    if (!is_logged_in()) return null;
    static $user = null;
    if ($user !== null) return $user;
    $user = db_row("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.is_active=1", [(int)$_SESSION['user_id']]);
    return $user ?: null;
}

function require_login(string $redirect = '/login.php'): void {
    if (!is_logged_in()) {
        flash('error', 'Please log in first.');
        redirect(APP_URL . $redirect . '?next=' . urlencode($_SERVER['REQUEST_URI']));
    }
}

function require_role(int ...$roles): void {
    require_login();
    $user = current_user();
    if (!$user || !in_array((int)$user['role_id'], $roles, true)) {
        http_response_code(403);
        die(render_403());
    }
}

function require_admin(): void {
    require_role(ROLE_ADMIN, ROLE_OPS);
}

function require_teacher(): void {
    require_role(ROLE_TEACHER, ROLE_ADMIN);
}

function is_admin(): bool {
    $u = current_user();
    return $u && in_array((int)$u['role_id'], [ROLE_ADMIN, ROLE_OPS], true);
}

function is_teacher(): bool {
    $u = current_user();
    return $u && in_array((int)$u['role_id'], [ROLE_TEACHER, ROLE_ADMIN], true);
}

// ── Login / Logout ────────────────────────────────────────────────────────────

function get_device_info(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/iPhone/i', $ua))       $os = 'iPhone';
    elseif (preg_match('/iPad/i', $ua))     $os = 'iPad';
    elseif (preg_match('/Android/i', $ua))  $os = 'Android';
    elseif (preg_match('/Windows/i', $ua))  $os = 'Windows';
    elseif (preg_match('/Mac OS X/i', $ua)) $os = 'Mac';
    elseif (preg_match('/Linux/i', $ua))    $os = 'Linux';
    else                                     $os = 'Other';

    if (preg_match('/Edg\//i', $ua))        $br = 'Edge';
    elseif (preg_match('/Chrome/i', $ua))   $br = 'Chrome';
    elseif (preg_match('/Firefox/i', $ua))  $br = 'Firefox';
    elseif (preg_match('/Safari/i', $ua))   $br = 'Safari';
    else                                     $br = 'Browser';

    return "$br · $os";
}

function get_active_sessions(int $userId): array {
    db_exec("DELETE FROM user_sessions WHERE user_id=? AND last_seen_at < DATE_SUB(NOW(), INTERVAL 30 DAY)", [$userId]);
    return db_query("SELECT id, device_name, ip, created_at, last_seen_at FROM user_sessions WHERE user_id=? ORDER BY last_seen_at DESC", [$userId]);
}

function kill_user_session(int $sessionId, int $userId): bool {
    return db_exec("DELETE FROM user_sessions WHERE id=? AND user_id=?", [$sessionId, $userId]) > 0;
}

function login_user(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_role'] = $user['role_id'];
    $_SESSION['_created']  = time();

    // Register this session in DB
    $token = bin2hex(random_bytes(32));
    $_SESSION['_session_token'] = $token;
    $_SESSION['_heartbeat']     = time();
    db_insert("INSERT INTO user_sessions (user_id, session_token, device_name, ip, created_at, last_seen_at) VALUES (?,?,?,?,NOW(),NOW())",
        [$user['id'], $token, get_device_info(), get_ip()]);

    db_exec("UPDATE users SET last_login=NOW() WHERE id=?", [$user['id']]);
    clear_login_attempts($user['email'] ?? $user['phone'] ?? '');
}

function logout_user(): void {
    if (!empty($_SESSION['_session_token'])) {
        db_exec("DELETE FROM user_sessions WHERE session_token=?", [$_SESSION['_session_token']]);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ── Enrollment check ─────────────────────────────────────────────────────────

function is_enrolled(int $courseId): bool {
    if (!is_logged_in()) return false;
    $uid = (int)$_SESSION['user_id'];
    static $cache = [];
    if (!isset($cache[$courseId])) {
        $cache[$courseId] = (bool)db_val(
            "SELECT 1 FROM enrollments WHERE user_id=? AND course_id=? AND status='active'",
            [$uid, $courseId]
        );
    }
    return $cache[$courseId];
}

// ── Error pages ───────────────────────────────────────────────────────────────

function render_403(): string {
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>403 - Access Denied</title>
    <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#F5F0FA;}
    .box{text-align:center;padding:40px;}.icon{font-size:64px;}.h{font-size:28px;color:#5B21B6;}.p{color:#666;}
    a{color:#5B21B6;text-decoration:none;}</style></head><body>
    <div class="box"><div class="icon">🔒</div><div class="h">Access Denied</div>
    <p class="p">You do not have permission to view this page.</p><a href="' . APP_URL . '/">Back to Homepage</a></div></body></html>';
}
