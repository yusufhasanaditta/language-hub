<?php
// Minimal SMTP mailer + email one-time codes.
// Configure in config/secrets.php (free with a Gmail account + App Password):
//   define('SMTP_HOST', 'smtp.gmail.com'); define('SMTP_PORT', 465);
//   define('SMTP_USER', 'you@gmail.com');  define('SMTP_PASS', 'xxxx xxxx xxxx xxxx');
defined('B_ACADEMY') or die('Direct access forbidden.');

function smtp_configured(): bool {
    return defined('SMTP_HOST') && SMTP_HOST !== '' && defined('SMTP_USER') && SMTP_USER !== ''
        && defined('SMTP_PASS') && SMTP_PASS !== '';
}

/** Send one HTML email over SMTPS (implicit TLS, port 465). Returns true on success. */
function send_mail(string $to, string $subject, string $html, string $text = ''): bool {
    if (!smtp_configured() || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $port = defined('SMTP_PORT') ? (int)SMTP_PORT : 465;
    $ctx  = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp   = @stream_socket_client('ssl://' . SMTP_HOST . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { error_log("SMTP connect failed: $errstr"); return false; }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 515)) !== false) { $out .= $line; if (($line[3] ?? '') === ' ') break; }
        return $out;
    };
    $cmd = function (string $c, array $ok) use ($fp, $read): bool {
        fwrite($fp, $c . "\r\n");
        $r = $read();
        if (!in_array(substr($r, 0, 3), $ok, true)) { error_log('SMTP error after "' . strtok($c, ' ') . '": ' . trim($r)); return false; }
        return true;
    };

    $from     = SMTP_USER;
    $fromName = defined('SMTP_FROM_NAME') && SMTP_FROM_NAME ? SMTP_FROM_NAME : APP_NAME;
    $host     = parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost';
    $boundary = 'b' . bin2hex(random_bytes(8));
    $text     = $text ?: trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html))));
    $enc      = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';

    $msg = implode("\r\n", [
        'Date: ' . date('r'),
        'From: ' . $enc($fromName) . " <$from>",
        "To: <$to>",
        'Subject: ' . $enc($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . "@$host>",
        'MIME-Version: 1.0',
        "Content-Type: multipart/alternative; boundary=\"$boundary\"",
        '',
        "--$boundary",
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($text)),
        "--$boundary",
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($html)),
        "--$boundary--",
        '',
    ]);

    $ok = str_starts_with($read(), '220')
        && $cmd("EHLO $host", ['250'])
        && $cmd('AUTH LOGIN', ['334'])
        && $cmd(base64_encode(SMTP_USER), ['334'])
        && $cmd(base64_encode(SMTP_PASS), ['235'])
        && $cmd("MAIL FROM:<$from>", ['250'])
        && $cmd("RCPT TO:<$to>", ['250', '251'])
        && $cmd('DATA', ['354'])
        && $cmd(str_replace("\r\n.", "\r\n..", $msg) . "\r\n.", ['250']);
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}

// ── Email one-time codes ─────────────────────────────────────────────────────
// The code is kept hashed in the session, expires after OTP_EXPIRY_MINUTES and
// allows 5 wrong tries before a new code must be requested.
const EMAIL_OTP_MAX_TRIES = 5;

/** Issue a code for $email and mail it. Returns the code only in development
 *  when no SMTP is configured (so it can be shown on screen); otherwise null. */
function email_otp_issue(string $email): array {
    $code = generate_otp();
    $_SESSION['_eotp'] = [
        'email' => $email,
        'hash'  => password_hash($code, PASSWORD_DEFAULT),
        'exp'   => time() + OTP_EXPIRY_MINUTES * 60,
        'tries' => 0,
        'sent'  => time(),
    ];
    $html = '<div style="font-family:Arial,sans-serif;max-width:420px;margin:auto;padding:24px;color:#1A1033">'
          . '<h2 style="margin:0 0 8px;color:#5B21B6">' . e(APP_NAME) . '</h2>'
          . '<p style="margin:0 0 16px">আপনার লগইন কোড / Your login code:</p>'
          . '<p style="font-size:32px;letter-spacing:8px;font-weight:700;margin:0 0 16px">' . $code . '</p>'
          . '<p style="color:#6E6684;font-size:13px;margin:0">' . OTP_EXPIRY_MINUTES . ' মিনিটের মধ্যে ব্যবহার করুন। কাউকে কোডটি দেবেন না।<br>'
          . 'Valid for ' . OTP_EXPIRY_MINUTES . ' minutes. Never share this code.</p></div>';
    $sent = send_mail($email, $code . ' — ' . APP_NAME . ' login code', $html);
    $devCode = (!$sent && APP_ENV === 'development' && !smtp_configured()) ? $code : null;
    return ['sent' => $sent, 'dev_code' => $devCode];
}

/** 'ok' | 'wrong' | 'expired' | 'locked' | 'none' */
function email_otp_check(string $code): string {
    $o = $_SESSION['_eotp'] ?? null;
    if (!$o) return 'none';
    if (time() > $o['exp']) { unset($_SESSION['_eotp']); return 'expired'; }
    if ($o['tries'] >= EMAIL_OTP_MAX_TRIES) { unset($_SESSION['_eotp']); return 'locked'; }
    if (!preg_match('/^\d{' . OTP_LENGTH . '}$/', $code) || !password_verify($code, $o['hash'])) {
        if (++$_SESSION['_eotp']['tries'] >= EMAIL_OTP_MAX_TRIES) { unset($_SESSION['_eotp']); return 'locked'; }
        return 'wrong';
    }
    unset($_SESSION['_eotp']);
    return 'ok';
}
