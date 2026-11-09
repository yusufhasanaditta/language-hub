<?php
defined('B_ACADEMY') or die('Direct access forbidden.');

require_once __DIR__ . '/web_push.php';

// ── CSRF Protection ───────────────────────────────────────────────────────────

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function csrf_verify(): bool {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals(csrf_token(), $token);
}

function csrf_check(): void {
    if (!csrf_verify()) {
        http_response_code(403);
        json_error('CSRF token mismatch.', 403);
    }
}

// ── Time formatting ───────────────────────────────────────────────────────────
// Defined here (not in header.php) because the notification dropdown in the
// navbar calls this before header.php reaches its own copy further down —
// that ordering caused a fatal "Call to undefined function" on every logged-in
// page load once a user had any notification.
if (!function_exists('time_ago')) {
    function time_ago(string $datetime): string {
        $diff = time() - strtotime($datetime);
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return intdiv($diff, 60) . ' min ago';
        if ($diff < 86400) return intdiv($diff, 3600) . ' hr ago';
        if ($diff < 604800) return intdiv($diff, 86400) . ' days ago';
        return date('d M Y', strtotime($datetime));
    }
}

// ── Schedule reminders ────────────────────────────────────────────────────────
// No cron is available in this environment, so due reminders are processed
// opportunistically on page load (called from includes/header.php for any
// logged-in request) instead of on a fixed schedule.
function process_due_schedule_reminders(int $uid): void {
    $due = db_query("
        SELECT sr.id as reminder_id, s.id as schedule_id, s.title, s.scheduled_at, s.type, c.slug as course_slug
        FROM schedule_reminders sr
        JOIN schedules s ON s.id = sr.schedule_id
        JOIN courses c ON c.id = s.course_id
        WHERE sr.user_id = ?
          AND sr.notified_at IS NULL
          AND s.scheduled_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
    ", [$uid]);

    foreach ($due as $row) {
        $when = date('d M, h:i A', strtotime($row['scheduled_at']));
        db_insert("INSERT INTO notifications (user_id, kind, title, body, action_url) VALUES (?,?,?,?,?)", [
            $uid,
            'schedule_reminder',
            'Upcoming: ' . $row['title'],
            'Starts ' . $when . '.',
            APP_URL . '/course.php?slug=' . urlencode($row['course_slug']) . '#schedules',
        ]);
        db_exec("UPDATE schedule_reminders SET notified_at=NOW() WHERE id=?", [$row['reminder_id']]);
    }
}

// ── XSS Prevention ────────────────────────────────────────────────────────────

function e(mixed $val): string {
    return htmlspecialchars((string)($val ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function nl2p(string $text): string {
    return '<p>' . implode('</p><p>', array_filter(array_map('trim', explode("\n", e($text))))) . '</p>';
}

// ── Input Validation ──────────────────────────────────────────────────────────

function normalize_phone(string $phone): string {
    $clean = preg_replace('/[^\d]/', '', $phone);
    if (strlen($clean) === 13 && str_starts_with($clean, '8801')) {
        return substr($clean, 2);
    }
    if (strlen($clean) === 12 && str_starts_with($clean, '880')) {
        return '0' . substr($clean, 3);
    }
    return $clean;
}

function validate_phone(string $phone): bool {
    $normalized = normalize_phone($phone);
    return (bool)preg_match('/^01[3-9]\d{8}$/', $normalized);
}

function validate_email(string $email): bool {
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Deliberately just a length check — no uppercase/number/symbol requirement,
// so students can pick something simple and easy to remember (e.g. "12345678").
function validate_password(string $pass): bool {
    return strlen($pass) >= PASSWORD_MIN_LENGTH;
}

function sanitize_string(string $str, int $maxlen = 255): string {
    return substr(trim(strip_tags($str)), 0, $maxlen);
}

function sanitize_int(mixed $val): int {
    return (int)filter_var($val, FILTER_SANITIZE_NUMBER_INT);
}

function get_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

// ── Rate Limiting ─────────────────────────────────────────────────────────────

function rate_limit(string $endpoint, int $limit = RATE_LIMIT_PER_MINUTE): void {
    $ip  = get_ip();
    $now = time();
    $win = $now - 60; // 1-minute window

    $row = db_row("SELECT id, hit_count, UNIX_TIMESTAMP(window_start) as ws FROM rate_limits WHERE ip_address=? AND endpoint=?", [$ip, $endpoint]);

    if (!$row || $row['ws'] < $win) {
        db_exec("INSERT INTO rate_limits (ip_address, endpoint, hit_count, window_start)
                 VALUES (?,?,1,NOW())
                 ON DUPLICATE KEY UPDATE hit_count=1, window_start=NOW()", [$ip, $endpoint]);
        return;
    }

    if ($row['hit_count'] >= $limit) {
        http_response_code(429);
        json_error('Too many requests. Please slow down.', 429);
    }

    db_exec("UPDATE rate_limits SET hit_count=hit_count+1 WHERE ip_address=? AND endpoint=?", [$ip, $endpoint]);
}

// ── Login Brute-force Protection ──────────────────────────────────────────────

function is_login_locked(string $identifier): bool {
    $cutoff = date('Y-m-d H:i:s', strtotime('-' . LOGIN_LOCKOUT_MINUTES . ' minutes'));
    $count  = db_val("SELECT COUNT(*) FROM login_attempts WHERE identifier=? AND attempted_at > ?", [$identifier, $cutoff]);
    return (int)$count >= MAX_LOGIN_ATTEMPTS;
}

function check_login_attempts(string $identifier): void {
    if (is_login_locked($identifier)) {
        json_error("Too many failed attempts. Try again after " . LOGIN_LOCKOUT_MINUTES . " minutes.", 429);
    }
}

function record_login_attempt(string $identifier): void {
    db_exec("INSERT INTO login_attempts (identifier, ip_address) VALUES (?,?)", [$identifier, get_ip()]);
}

function clear_login_attempts(string $identifier): void {
    db_exec("DELETE FROM login_attempts WHERE identifier=?", [$identifier]);
}

// ── OTP ───────────────────────────────────────────────────────────────────────

function generate_otp(): string {
    return str_pad((string)random_int(0, 10**OTP_LENGTH - 1), OTP_LENGTH, '0', STR_PAD_LEFT);
}

function store_otp(string $phone, string $otp, string $purpose = 'register'): void {
    // Invalidate old OTPs for this phone+purpose
    $now = date('Y-m-d H:i:s');
    db_exec("UPDATE otps SET used_at=? WHERE phone=? AND purpose=? AND used_at IS NULL", [$now, $phone, $purpose]);
    $expires = date('Y-m-d H:i:s', strtotime('+' . OTP_EXPIRY_MINUTES . ' minutes'));
    db_exec("INSERT INTO otps (phone, otp_code, purpose, expires_at) VALUES (?,?,?,?)", [$phone, $otp, $purpose, $expires]);
}

function verify_otp(string $phone, string $otp, string $purpose = 'register'): bool {
    // Use PHP date() instead of MySQL NOW() to match the timezone used in store_otp()
    $now = date('Y-m-d H:i:s');
    $row = db_row("SELECT id FROM otps WHERE phone=? AND otp_code=? AND purpose=? AND expires_at > ? AND used_at IS NULL ORDER BY id DESC LIMIT 1", [$phone, $otp, $purpose, $now]);
    if ($row) {
        db_exec("UPDATE otps SET used_at=? WHERE id=?", [$now, $row['id']]);
        return true;
    }
    return false;
}

function send_otp(string $phone, string $otp): bool {
    $message = "Your " . APP_NAME . " verification code is: $otp. Valid for " . OTP_EXPIRY_MINUTES . " minutes.";

    if (sms_gateway_configured()) {
        $result = send_sms_bulksmsbd($phone, $message);
        if ($result['success']) return true;
        // Real SMS failed (bad key, no balance, network down) — fall back to
        // demo mode so login/registration isn't blocked, but the OTP won't
        // actually reach the phone. Admin should check Settings > SMS Gateway.
        error_log('BulkSMSBD send failed for ' . $phone . ': ' . $result['raw']);
    }

    if (OTP_DEMO_MODE || !sms_gateway_configured()) {
        // Demo mode / not configured yet: store in session for on-screen display
        $_SESSION['demo_otp'] = $otp;
        return true;
    }

    return false;
}

// ── SMS Gateway (BulkSMSBD) ──────────────────────────────────────────────────

function sms_gateway_configured(): bool {
    return get_setting('sms_bulksmsbd_enabled') === '1'
        && get_setting('sms_bulksmsbd_api_key') !== ''
        && get_setting('sms_bulksmsbd_sender_id') !== '';
}

// Sends one SMS via BulkSMSBD (https://bulksmsbd.net). Numbers must be in
// 01XXXXXXXXX format, matching how phones are stored/validated everywhere else.
function send_sms_bulksmsbd(string $phone, string $message): array {
    $apiKey   = get_setting('sms_bulksmsbd_api_key');
    // BulkSMSBD's API rejects the request (response_code 1003) if senderid is
    // blank — it is NOT optional, it must be the exact Sender ID approved in
    // your BulkSMSBD account panel.
    $senderId = get_setting('sms_bulksmsbd_sender_id');

    if (!$apiKey) {
        return ['success' => false, 'raw' => 'SMS gateway API key not configured'];
    }
    if (!$senderId) {
        return ['success' => false, 'raw' => 'SMS gateway Sender ID not configured'];
    }

    $url = "http://bulksmsbd.net/api/smsapi?" . http_build_query([
        "api_key"  => $apiKey,
        "type"     => "text",
        "number"   => $phone,
        "senderid" => $senderId,
        "message"  => $message
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, APP_ENV === 'production');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, APP_ENV === 'production');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['success' => false, 'raw' => 'cURL error: ' . $err];
    }

    // BulkSMSBD returns response_code=202 on successful submission.
    $decoded = json_decode($raw, true);
    $code = $decoded['response_code'] ?? null;

    return ['success' => $code === 202, 'raw' => $raw];
}

// Checks the BulkSMSBD account balance. Returns null if not configured or the call fails.
function get_sms_gateway_balance(): ?string {
    $apiKey = get_setting('sms_bulksmsbd_api_key');
    if (!$apiKey) return null;

    $url = "http://bulksmsbd.net/api/getBalanceApi?api_key=" . urlencode($apiKey);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, APP_ENV === 'production');
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $raw = curl_exec($ch);
    curl_close($ch);

    if ($raw === false) return null;

    $decoded = json_decode($raw, true);
    return $decoded['balance'] ?? null;
}

// ── JSON Responses ────────────────────────────────────────────────────────────

function json_success(mixed $data = null, string $message = 'Success'): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message = 'Error', int $code = 400, array $errors = []): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $message, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Redirects ─────────────────────────────────────────────────────────────────

function redirect($url) {
    if (isset($_GET['layout']) && strpos($url, 'layout=') === false) {
        $url .= (strpos($url, '?') !== false ? '&' : '?') . 'layout=' . urlencode($_GET['layout']);
    }
    header("Location: $url");
    exit;
}

function redirect_back(string $fallback = '/'): never {
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $url = (strpos($ref, APP_URL) === 0) ? $ref : (APP_URL . $fallback);
    redirect($url);
}

// ── Flash Messages ────────────────────────────────────────────────────────────

function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flash(): array {
    $msgs = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $msgs;
}

function render_flash(): string {
    $html = '';
    foreach (get_flash() as $f) {
        $type = e($f['type']);
        $msg  = e($f['message']);
        $html .= "<div class=\"alert alert-{$type}\">{$msg}</div>";
    }
    return $html;
}

// ── Currency ──────────────────────────────────────────────────────────────────

function format_price(float $amount): string {
    // Plain number only — no currency symbol.
    return number_format($amount, 0, '.', ',');
}

function format_number(int $n): string {
    if ($n >= 1000) return round($n / 1000, 1) . 'k';
    return (string)$n;
}

// ── Duration ─────────────────────────────────────────────────────────────────

function format_duration(int $minutes): string {
    if ($minutes < 60) return $minutes . ' min';
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $h . ' h' . ($m ? ' ' . $m . ' min' : '');
}

// ── Slug ─────────────────────────────────────────────────────────────────────

function make_slug(string $text): string {
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/\s+/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    return trim($text, '-') ?: 'item-' . time();
}

// ── File cache (APCu-first, then file fallback) ───────────────────────────────

function cache_get(string $key): mixed {
    $aKey = 'bac_' . md5($key);
    if (function_exists('apcu_fetch')) {
        $val = apcu_fetch($aKey, $ok);
        if ($ok) return $val;
    }
    $file = CACHE_PATH . md5($key) . '.cache';
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    if (!$data || $data['expires'] < time()) { @unlink($file); return null; }
    return $data['value'];
}

function cache_set(string $key, mixed $value, int $ttl = CACHE_DEFAULT_TTL): void {
    $aKey = 'bac_' . md5($key);
    if (function_exists('apcu_store')) {
        apcu_store($aKey, $value, $ttl);
    }
    if (!is_dir(CACHE_PATH)) mkdir(CACHE_PATH, 0755, true);
    $file  = CACHE_PATH . md5($key) . '.cache';
    $tmp   = $file . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, json_encode(['expires' => time() + $ttl, 'value' => $value]));
    rename($tmp, $file);
}

function cache_delete(string $key): void {
    $aKey = 'bac_' . md5($key);
    if (function_exists('apcu_delete')) apcu_delete($aKey);
    $file = CACHE_PATH . md5($key) . '.cache';
    if (file_exists($file)) @unlink($file);
}

// ── Star Rating ───────────────────────────────────────────────────────────────

function render_stars(float $rating, bool $mini = false): string {
    $html  = '<span class="stars' . ($mini ? ' stars-mini' : '') . '" aria-label="' . number_format($rating, 1) . ' stars">';
    $full  = floor($rating);
    $half  = ($rating - $full) >= 0.5 ? 1 : 0;
    $empty = 5 - $full - $half;
    $html .= str_repeat('<span class="star star-full">★</span>', (int)$full);
    if ($half) $html .= '<span class="star star-half">★</span>';
    $html .= str_repeat('<span class="star star-empty">☆</span>', (int)$empty);
    return $html . '</span>';
}

// ── Pagination ────────────────────────────────────────────────────────────────

function paginate(int $total, int $page, int $perPage, string $urlPattern): string {
    $totalPages = (int)ceil($total / $perPage);
    if ($totalPages <= 1) return '';
    $html = '<div class="pagination">';
    if ($page > 1) {
        $html .= '<a href="' . str_replace('{page}', $page - 1, $urlPattern) . '" class="page-btn">‹</a>';
    }
    $start = max(1, $page - 2);
    $end   = min($totalPages, $page + 2);
    if ($start > 1) $html .= '<a href="' . str_replace('{page}', 1, $urlPattern) . '" class="page-btn">1</a>' . ($start > 2 ? '<span class="page-ellipsis">…</span>' : '');
    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $page ? ' active' : '';
        $html  .= '<a href="' . str_replace('{page}', $i, $urlPattern) . '" class="page-btn' . $active . '">' . $i . '</a>';
    }
    if ($end < $totalPages) $html .= ($end < $totalPages - 1 ? '<span class="page-ellipsis">…</span>' : '') . '<a href="' . str_replace('{page}', $totalPages, $urlPattern) . '" class="page-btn">' . $totalPages . '</a>';
    if ($page < $totalPages) {
        $html .= '<a href="' . str_replace('{page}', $page + 1, $urlPattern) . '" class="page-btn">›</a>';
    }
    return $html . '</div>';
}

// ── Student Roll Number ───────────────────────────────────────────────────────

function generate_roll_number(int $userId, string $name): string {
    $year    = date('y');  // 2-digit year, e.g. "25"
    $initial = strtoupper(mb_substr(trim($name), 0, 1, 'UTF-8'));
    $letter  = preg_match('/[A-Z]/', $initial) ? $initial : 'S';
    $seq     = str_pad($userId, 5, '0', STR_PAD_LEFT);
    return "BA{$year}{$letter}{$seq}";
}

function ensure_roll_number(int $userId, string $name): string {
    $existing = (string)db_val("SELECT roll_number FROM users WHERE id=?", [$userId]);
    if ($existing) return $existing;
    $roll = generate_roll_number($userId, $name);
    // Handle duplicate collision (shouldn't happen, but be safe)
    db_exec("UPDATE users SET roll_number=? WHERE id=? AND (roll_number IS NULL OR roll_number='')", [$roll, $userId]);
    return (string)db_val("SELECT roll_number FROM users WHERE id=?", [$userId]) ?: $roll;
}

// ── Exam leaderboard / official result ─────────────────────────────────────────
// A student's OFFICIAL result for an exam is their FIRST submitted/graded
// attempt. Retakes are kept in exam_attempts for history, but never overwrite
// this. Re-grading the SAME attempt (e.g. a teacher correcting a CQ score)
// still updates it, since that's a correction, not a new attempt.

function record_exam_leaderboard_result(int $examId, int $userId, int $attemptId, float $score): void {
    db_exec(
        "INSERT INTO exam_leaderboard (exam_id, user_id, attempt_id, score)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE
            score      = IF(attempt_id = VALUES(attempt_id), VALUES(score), score),
            updated_at = IF(attempt_id = VALUES(attempt_id), NOW(), updated_at)",
        [$examId, $userId, $attemptId, $score]
    );
    recompute_exam_leaderboard_ranks($examId);
}

function recompute_exam_leaderboard_ranks(int $examId): void {
    db_exec("
        UPDATE exam_leaderboard el
        JOIN (
            SELECT el1.user_id AS uid, 1 + COUNT(el2.user_id) AS new_rank
            FROM exam_leaderboard el1
            LEFT JOIN exam_leaderboard el2 ON el2.exam_id = el1.exam_id AND el2.score > el1.score
            WHERE el1.exam_id = ?
            GROUP BY el1.user_id
        ) ranked ON ranked.uid = el.user_id
        SET el.rank = ranked.new_rank
        WHERE el.exam_id = ?
    ", [$examId, $examId]);
}

// Auto-finalize an MCQ attempt whose time has actually run out, scoring it
// from whatever answers were autosaved. Without this, a later page visit
// would just resume the dead attempt and show a 00:00 timer forever instead
// of starting a fresh one.
function finalize_overdue_mcq_attempt(int $attemptId): void {
    $attempt = db_row("SELECT * FROM exam_attempts WHERE id=? AND status='in_progress'", [$attemptId]);
    if (!$attempt) return;
    $examId = (int)$attempt['exam_id'];
    $exam   = db_row("SELECT * FROM exams WHERE id=?", [$examId]);
    if (!$exam) return;

    $questions = db_query("SELECT id, correct_option, marks FROM exam_questions WHERE exam_id=?", [$examId]);
    $savedRows = db_query("SELECT question_id, chosen FROM exam_attempt_answers WHERE attempt_id=?", [$attemptId]);
    $saved = [];
    foreach ($savedRows as $r) { $saved[(int)$r['question_id']] = $r['chosen']; }

    $negMark = (float)($exam['negative_mark'] ?? 0);
    $correct = $wrong = $skipped = 0;
    $score = 0.0;
    foreach ($questions as $q) {
        $chosen = $saved[(int)$q['id']] ?? null;
        if ($chosen === null) {
            $skipped++;
        } elseif ((string)$chosen === (string)$q['correct_option']) {
            $correct++;
            $score += (float)$q['marks'];
        } else {
            $wrong++;
            $score -= $negMark;
        }
    }
    $score       = max(0.0, round($score, 2));
    $durationSec = (int)($exam['duration'] ?? 0) * 60;

    db_exec(
        "UPDATE exam_attempts SET status='submitted', score=?, total_correct=?, total_wrong=?,
         total_skipped=?, time_taken=?, submitted_at=NOW(), updated_at=NOW() WHERE id=?",
        [$score, $correct, $wrong, $skipped, $durationSec, $attemptId]
    );

    $rank = 1 + (int)db_val(
        "SELECT COUNT(DISTINCT user_id) FROM exam_attempts WHERE exam_id=? AND score>? AND status IN ('submitted','graded') AND id!=?",
        [$examId, $score, $attemptId]
    );
    db_exec("UPDATE exam_attempts SET rank=? WHERE id=?", [$rank, $attemptId]);

    record_exam_leaderboard_result($examId, (int)$attempt['user_id'], $attemptId, $score);
}

// ── Clean / professional URLs ───────────────────────────────────────────────
// Backed by .htaccess rewrite rules: /course/{slug}, /t/{username}. Falls
// back to the old ?query=string form when there's nothing clean to route on,
// so callers never have to branch on it themselves.

function course_url(array $course): string {
    $slug = $course['slug'] ?? '';
    return $slug ? APP_URL . '/course/' . rawurlencode($slug)
                 : APP_URL . '/course.php?id=' . (int)($course['id'] ?? 0);
}

function teacher_url(array $teacher): string {
    $username = $teacher['username'] ?? '';
    $id       = (int)($teacher['tp_id'] ?? $teacher['id'] ?? 0);
    return $username ? APP_URL . '/t/' . rawurlencode($username)
                      : APP_URL . '/teacher-profile.php?id=' . $id;
}

// ── Multi-teacher course access ─────────────────────────────────────────────
// A course has one "owner" (courses.teacher_id) plus optional co-teachers in
// the course_teachers pivot table. These helpers treat all of them equally.

function teacher_owns_course(int $courseId, int $tpId): bool {
    return (bool) db_val(
        "SELECT 1 FROM courses c WHERE c.id=? AND (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))",
        [$courseId, $tpId, $tpId]
    );
}

function teacher_course_ids(int $tpId): array {
    $rows = db_query(
        "SELECT c.id FROM courses c WHERE c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)",
        [$tpId, $tpId]
    );
    return array_map(fn($r) => (int) $r['id'], $rows ?? []);
}

function teacher_owns_exam(int $examId, int $tpId): bool {
    return (bool) db_val(
        "SELECT 1 FROM exams ex JOIN courses c ON c.id=ex.course_id WHERE ex.id=? AND (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))",
        [$examId, $tpId, $tpId]
    );
}

// ── Notifications ─────────────────────────────────────────────────────────
// Central place to write into the `notifications` table so every feature
// (enrollment, notices, grading, live classes...) creates rows the same way
// instead of each call site hand-rolling its own INSERT.

function create_notification(int $userId, string $kind, string $title, ?string $body = null, ?string $actionUrl = null): void {
    if (!$userId) return;
    db_insert(
        "INSERT INTO notifications (user_id, kind, title, body, action_url) VALUES (?,?,?,?,?)",
        [$userId, $kind, $title, $body, $actionUrl]
    );
    // Best-effort phone/desktop popup via Web Push — never let a delivery
    // failure break the caller's actual flow (payment, grading, enrollment...).
    try { send_push_to_user($userId, $title, (string)($body ?? ''), $actionUrl); } catch (Throwable $e) { error_log('send_push_to_user: ' . $e->getMessage()); }
}

// Notify every actively-enrolled student of a course in one query.
function notify_course_students(int $courseId, string $kind, string $title, ?string $body = null, ?string $actionUrl = null): void {
    db_exec(
        "INSERT INTO notifications (user_id, kind, title, body, action_url)
         SELECT e.user_id, ?, ?, ?, ? FROM enrollments e WHERE e.course_id=? AND e.status='active'",
        [$kind, $title, $body, $actionUrl, $courseId]
    );
}

// Notify every student account on the platform (used for sitewide notices).
function notify_all_students(string $kind, string $title, ?string $body = null, ?string $actionUrl = null): void {
    db_exec(
        "INSERT INTO notifications (user_id, kind, title, body, action_url)
         SELECT u.id, ?, ?, ?, ? FROM users u WHERE u.role_id=?",
        [$kind, $title, $body, $actionUrl, ROLE_STUDENT]
    );
}

// Notify literally every user account (students, teachers, admins).
function notify_all_users(string $kind, string $title, ?string $body = null, ?string $actionUrl = null): void {
    db_exec(
        "INSERT INTO notifications (user_id, kind, title, body, action_url)
         SELECT u.id, ?, ?, ?, ? FROM users u",
        [$kind, $title, $body, $actionUrl]
    );
}

// ── University Admission & Exam Tracker ─────────────────────────────────────
// Self-healing, like the other newer features here: creates its own table on
// first use so nothing needs to be imported by hand after a cPanel upload.
function ensure_universities_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db_exec("CREATE TABLE IF NOT EXISTS `universities` (
        `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
        `university_name` varchar(200) NOT NULL,
        `logo_url` varchar(500) DEFAULT NULL,
        `form_release_date` datetime DEFAULT NULL,
        `form_last_date` datetime DEFAULT NULL,
        `exam_date` datetime DEFAULT NULL,
        `result_date` datetime DEFAULT NULL,
        `details` text DEFAULT NULL,
        `circular_pdf_url` varchar(500) DEFAULT NULL,
        `registration_link` varchar(500) DEFAULT NULL,
        `status` enum('active','draft') NOT NULL DEFAULT 'draft',
        `sort_order` smallint(6) NOT NULL DEFAULT 0,
        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
        `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Self-healing: adds the Facebook private group link column to courses on
// first use, so nothing needs to be run by hand after a cPanel upload.
function ensure_course_fb_group_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        db_exec("SELECT fb_group_link FROM courses LIMIT 1");
    } catch (PDOException $e) {
        db_exec("ALTER TABLE courses ADD COLUMN fb_group_link VARCHAR(500) NULL AFTER routine_link");
    }
}

// Extracts the 11-char YouTube video ID from any common URL shape
// (watch?v=, youtu.be/, /embed/, /shorts/, /live/, /v/ — with or without
// extra query params like list=/index=/si=). Returns '' if not recognized.
function extract_youtube_id(string $url): string {
    $parts = parse_url($url);
    $host = strtolower($parts['host'] ?? '');
    $path = $parts['path'] ?? '';
    parse_str($parts['query'] ?? '', $qs);

    if (strpos($host, 'youtu.be') !== false) {
        $segs = explode('/', trim($path, '/'));
        return $segs[0] ?? '';
    }
    if (strpos($host, 'youtube.com') !== false || strpos($host, 'youtube-nocookie.com') !== false) {
        if (!empty($qs['v'])) return $qs['v'];
        if (preg_match('#/(?:embed|v|shorts|live)/([^/?&]+)#', $path, $m)) {
            return $m[1];
        }
    }
    return '';
}

// Self-healing: adds an admin-only "displayed enrollment count" override to
// courses, so marketing can show a boosted social-proof number to visitors
// while the real enrollment count (courses.enrolled_count / a live COUNT(*)
// on enrollments) stays intact everywhere it's used for actual business logic.
function ensure_course_fake_enrollment_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        db_exec("SELECT fake_enrolled_count FROM courses LIMIT 1");
    } catch (PDOException $e) {
        db_exec("ALTER TABLE courses ADD COLUMN fake_enrolled_count INT UNSIGNED NULL AFTER enrolled_count");
    }
}

// The number to SHOW to students/visitors: the admin-set display override if
// one is set (>0), otherwise the real enrollment count. Never use this for
// access control or business logic — only for on-page social-proof display.
function public_enrolled_count(array $course): int {
    $fake = (int)($course['fake_enrolled_count'] ?? 0);
    return $fake > 0 ? $fake : (int)($course['enrolled_count'] ?? 0);
}

// Self-healing: adds the subject/section column to virtual_classrooms on
// first use, so classrooms can be grouped by subject (Bangla, English, GK...).
function ensure_classroom_subject_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        db_exec("SELECT subject FROM virtual_classrooms LIMIT 1");
    } catch (PDOException $e) {
        db_exec("ALTER TABLE virtual_classrooms ADD COLUMN subject VARCHAR(100) NULL AFTER course_id, ADD INDEX idx_subject (course_id, subject)");
    }
}

// Groups a flat list of virtual_classrooms rows by their `subject` column.
// Empty/null subjects bucket into 'General', which is always sorted last.
function group_classrooms_by_subject(array $classrooms): array {
    $groups = [];
    foreach ($classrooms as $cr) {
        $subj = trim($cr['subject'] ?? '');
        $key = $subj !== '' ? $subj : 'General';
        $groups[$key][] = $cr;
    }
    $hasGeneral = isset($groups['General']);
    if ($hasGeneral) $general = $groups['General'];
    if ($hasGeneral) unset($groups['General']);
    ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
    if ($hasGeneral) $groups['General'] = $general;
    return $groups;
}

// Pick the nearest upcoming milestone for a university row (drives the live
// countdown on both admission-tracker.php and the homepage teaser section).
function university_next_milestone(array $u): ?array {
    $candidates = [
        ['key' => 'form_release_date', 'label' => 'Form Releases In',   'value' => $u['form_release_date']],
        ['key' => 'form_last_date',    'label' => 'Form Deadline In',   'value' => $u['form_last_date']],
        ['key' => 'exam_date',         'label' => 'Exam Starts In',     'value' => $u['exam_date']],
        ['key' => 'result_date',       'label' => 'Result Publishes In','value' => $u['result_date']],
    ];
    $future = array_filter($candidates, fn($c) => !empty($c['value']) && strtotime($c['value']) > time());
    if (!$future) return null;
    usort($future, fn($a, $b) => strtotime($a['value']) <=> strtotime($b['value']));
    return $future[0];
}

function university_status_label(array $u): string {
    if (!empty($u['result_date']) && strtotime($u['result_date']) <= time()) return 'Result Published';
    if (!empty($u['exam_date']) && strtotime($u['exam_date']) <= time()) return 'Awaiting Result';
    if (!empty($u['form_last_date']) && strtotime($u['form_last_date']) <= time()) return 'Form Closed';
    return 'Details Coming Soon';
}

// Resolves a logo to a real URL: an uploaded local file, a pasted external
// URL, or a generated placeholder if nothing was provided.
function university_logo_url(?string $logo, string $name = 'University'): string {
    if (!empty($logo)) {
        if (preg_match('#^https?://#i', $logo)) return $logo;
        $path = ltrim($logo, '/');
        if (file_exists(APP_ROOT . '/' . $path)) return APP_URL . '/' . $path;
    }
    $initials = urlencode(mb_strtoupper(mb_substr($name, 0, 2, 'UTF-8'), 'UTF-8'));
    return "https://placehold.co/200x200/5B21B6/ffffff?text={$initials}";
}

// A stored datetime is always Asia/Dhaka wall-clock time (see date_default_timezone_set
// in config/config.php). Emit it with an explicit +06:00 offset so JS's `new Date(...)`
// parses it as that exact instant everywhere, then each visitor's browser renders the
// countdown against their own local clock — no client-side timezone math needed.
function to_dhaka_iso(?string $datetime): ?string {
    if (!$datetime) return null;
    $ts = strtotime($datetime);
    if (!$ts) return null;
    return date('Y-m-d\TH:i:s', $ts) . '+06:00';
}

// "Add to Google Calendar" link for a milestone date — lets a guest save a
// deadline reminder without creating an account (used on admission-tracker.php).
function google_calendar_url(string $title, ?string $datetime, string $details = ''): ?string {
    if (!$datetime) return null;
    $ts = strtotime($datetime);
    if (!$ts) return null;
    $start = gmdate('Ymd\THis\Z', $ts);
    $end   = gmdate('Ymd\THis\Z', $ts + 3600);
    return 'https://www.google.com/calendar/render?' . http_build_query([
        'action'  => 'TEMPLATE',
        'text'    => $title,
        'dates'   => "$start/$end",
        'details' => $details,
    ]);
}

// ── Site traffic tracking (feeds the admin analytics dashboard) ────────────
// Self-healing: creates its own table on first use so nothing extra needs to
// be imported by hand after uploading these files to cPanel.
function track_page_view(): void {
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|curl|wget|python-requests|headless/i', $ua)) {
        return;
    }

    $path = substr((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), 0, 255);
    $ref  = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 255) ?: null;
    $ip   = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45) ?: null;
    $uid  = $_SESSION['user_id'] ?? null;
    $visitorKey = session_id() ?: substr(sha1(($ip ?? '') . $ua), 0, 40);
    $device = preg_match('/mobile|android|iphone/i', $ua) ? 'mobile' : (preg_match('/ipad|tablet/i', $ua) ? 'tablet' : 'desktop');

    $sql = "INSERT INTO page_views (path, user_id, visitor_key, ip_address, user_agent, referrer, device, created_at)
            VALUES (?,?,?,?,?,?,?,NOW())";
    $params = [$path, $uid, $visitorKey, $ip, $ua, $ref, $device];

    try {
        db_exec($sql, $params);
    } catch (PDOException $e) {
        db_exec("CREATE TABLE IF NOT EXISTS `page_views` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `path` varchar(255) NOT NULL,
            `user_id` int(10) unsigned DEFAULT NULL,
            `visitor_key` varchar(64) NOT NULL,
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` varchar(255) DEFAULT NULL,
            `referrer` varchar(255) DEFAULT NULL,
            `device` varchar(20) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_created` (`created_at`),
            KEY `idx_visitor` (`visitor_key`),
            KEY `idx_path` (`path`(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db_exec($sql, $params);
    }
}

// ── Per-course view tracking (feeds the admin course analytics page) ───────
// Self-healing table, same pattern as track_page_view() above.
function track_course_view(int $courseId): void {
    if ($courseId <= 0) return;
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|curl|wget|python-requests|headless/i', $ua)) {
        return;
    }

    $ref  = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 255) ?: null;
    $ip   = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45) ?: null;
    $uid  = $_SESSION['user_id'] ?? null;
    $visitorKey = session_id() ?: substr(sha1(($ip ?? '') . $ua), 0, 40);
    $device = preg_match('/mobile|android|iphone/i', $ua) ? 'mobile' : (preg_match('/ipad|tablet/i', $ua) ? 'tablet' : 'desktop');

    $sql = "INSERT INTO course_views (course_id, user_id, visitor_key, ip_address, user_agent, referrer, device, created_at)
            VALUES (?,?,?,?,?,?,?,NOW())";
    $params = [$courseId, $uid, $visitorKey, $ip, $ua, $ref, $device];

    try {
        db_exec($sql, $params);
    } catch (PDOException $e) {
        db_exec("CREATE TABLE IF NOT EXISTS `course_views` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `course_id` int(10) unsigned NOT NULL,
            `user_id` int(10) unsigned DEFAULT NULL,
            `visitor_key` varchar(64) NOT NULL,
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` varchar(255) DEFAULT NULL,
            `referrer` varchar(255) DEFAULT NULL,
            `device` varchar(20) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_course` (`course_id`),
            KEY `idx_created` (`created_at`),
            KEY `idx_visitor` (`visitor_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db_exec($sql, $params);
    }
}

// ── Social share image (Open Graph / Twitter Card) ──────────────────────────
// A page-specific image URL only counts as "real" if it resolves to a file we
// actually host; helper functions like course_thumbnail_url()/avatar_url()
// silently fall back to a placehold.co placeholder when the file is missing,
// which is fine inline but must never leak into a link-preview image.
function is_own_asset_url(?string $url): bool {
    return $url !== null && str_starts_with($url, APP_URL . '/');
}

function default_share_image(): string {
    return APP_URL . '/image/language-hub-logo-original.jpg';
}

function teacher_owns_live_session(int $sessionId, int $tpId): bool {
    return (bool) db_val(
        "SELECT 1 FROM live_sessions ls JOIN courses c ON c.id=ls.course_id WHERE ls.id=? AND (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))",
        [$sessionId, $tpId, $tpId]
    );
}

// ── Avatar ────────────────────────────────────────────────────────────────────

function avatar_url(?string $avatar, string $name = 'U'): string {
    if ($avatar) {
        $path = str_starts_with($avatar, 'uploads/') ? $avatar : 'uploads/' . ltrim($avatar, '/');
        if (file_exists(APP_ROOT . '/' . $path)) {
            return APP_URL . '/' . $path;
        }
    }
    $initials = urlencode(mb_strtoupper(mb_substr($name, 0, 2, 'UTF-8'), 'UTF-8'));
    return "https://placehold.co/400x400/5B21B6/ffffff?text={$initials}";
}

// ── Course Thumbnail ──────────────────────────────────────────────────────────

function course_thumbnail_url(?string $thumbnail, string $title = 'Course', string $dim = '600x340', string $color = '5B21B6'): string {
    if (!empty($thumbnail)) {
        $path = ltrim($thumbnail, '/');
        if (file_exists(APP_ROOT . '/' . $path)) {
            return APP_URL . '/' . $path;
        }
    }
    return "https://placehold.co/{$dim}/{$color}/ffffff?text=" . urlencode($title ?: 'Course');
}

// ── Settings ──────────────────────────────────────────────────────────────────

function get_setting(string $key, mixed $default = ''): mixed {
    $cacheKey = 'setting_' . $key;
    $cached   = cache_get($cacheKey);
    if ($cached !== null) return $cached;

    $val = db_val("SELECT setting_value FROM settings WHERE setting_key=?", [$key]);
    $result = $val ?? $default;
    cache_set($cacheKey, $result, 600);
    return $result;
}

// ── Uploads ───────────────────────────────────────────────────────────────────

function handle_file_upload(string $fieldName, string $subDir = '', array $allowedMimes = []): ?string {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) return null;

    $file = $_FILES[$fieldName];
    $maxBytes = MAX_UPLOAD_MB * 1024 * 1024;

    if ($file['size'] > $maxBytes) return null;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    
    // Default allowed mimes if not provided
    if (empty($allowedMimes)) {
        $allowedMimes = [
            'application/pdf', 
            'application/x-pdf',
            'application/acrobat',
            'applications/vnd.pdf',
            'text/pdf',
            'text/x-pdf',
            'application/msword', 
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/jpeg', 'image/png', 'image/webp'
        ];
    }
    
    $extInfo = pathinfo($file['name'], PATHINFO_EXTENSION);
    $ext = strtolower($extInfo) ?: 'file';

    if (!in_array($mime, $allowedMimes, true)) {
        // Fallback for PDF if finfo fails but extension is pdf
        if ($ext === 'pdf') {
            // allow it
        } else {
            return null;
        }
    }

    $dir = APP_ROOT . '/uploads';
    if ($subDir) $dir .= '/' . trim($subDir, '/');
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = uniqid(date('Ymd_His_')) . '.' . $ext;
    $dest     = $dir . '/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        $rel = '/uploads' . ($subDir ? '/' . trim($subDir, '/') : '') . '/' . $filename;
        return $rel;
    }
    return null;
}

function handle_image_upload(string $fieldName, string $subDir = ''): ?string {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) return null;

    $file = $_FILES[$fieldName];
    $maxBytes = MAX_UPLOAD_MB * 1024 * 1024;

    if ($file['size'] > $maxBytes) return null;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ALLOWED_IMAGE_TYPES, true)) return null;

    $ext  = match($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        default      => null,
    };
    if (!$ext) return null;

    $dir = UPLOAD_PATH . ($subDir ? rtrim($subDir, '/') . '/' : '');
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) return null;

    return 'uploads/' . ($subDir ? $subDir . '/' : '') . $filename;
}

// ── Bangla / English toggle ──────────────────────────────────────────────────
// Renders both languages; CSS shows the one matching <html data-lang>, so
// switching is instant and needs no page reload. Bangla is the default.
if (!function_exists('t')) {
    function t(string $bn, string $en): string {
        return '<span class="i18n" lang="bn">' . e($bn) . '</span><span class="i18n" lang="en">' . e($en) . '</span>';
    }
}
