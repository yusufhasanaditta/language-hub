<?php
defined('B_ACADEMY') or die('Direct access forbidden.');

/**
 * Hand-rolled Web Push sender (RFC 8291 payload encryption + RFC 8292 VAPID).
 * No Composer/vendor is available on this host, so this implements the same
 * protocol the minishlink/web-push library does, using only ext-openssl and
 * ext-curl (both standard on cPanel PHP builds).
 *
 * Flow: browser subscribes via PushManager -> we store {endpoint, p256dh,
 * auth} in push_subscriptions -> to notify, we AES-128-GCM encrypt the JSON
 * payload to the subscriber's key (ECDH with a fresh per-message key pair),
 * sign a VAPID JWT with our fixed VAPID key pair, and POST the encrypted
 * block to the subscription's endpoint (the browser vendor's push service).
 */

// ── base64url helpers ───────────────────────────────────────────────────────

function wp_b64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function wp_b64url_decode(string $data): string {
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode($data);
}

// ── DER key builders (no ASN.1 library available) ──────────────────────────

/** SubjectPublicKeyInfo DER for a raw 65-byte uncompressed P-256 point. */
function wp_spki_der(string $point65): string {
    return "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $point65;
}

/** SEC1 ECPrivateKey DER (RFC 5915) for a raw 32-byte scalar + its 65-byte public point. */
function wp_ec_private_der(string $d32, string $point65): string {
    return "\x30\x77" .
        "\x02\x01\x01" .
        "\x04\x20" . $d32 .
        "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07" .
        "\xa1\x44\x03\x42\x00" . $point65;
}

function wp_pem(string $der, string $label): string {
    return "-----BEGIN $label-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END $label-----\n";
}

function wp_public_key_from_point(string $point65) {
    return openssl_pkey_get_public(wp_pem(wp_spki_der($point65), 'PUBLIC KEY'));
}

function wp_private_key_from_raw(string $d32, string $point65) {
    return openssl_pkey_get_private(wp_pem(wp_ec_private_der($d32, $point65), 'EC PRIVATE KEY'));
}

/** Generate a fresh P-256 key pair. Returns [privateKeyResource, raw65PointPublic, raw32PrivateScalar]. */
function wp_generate_keypair(): array {
    $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $details = openssl_pkey_get_details($res);
    $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
    $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $d = str_pad($details['ec']['d'], 32, "\x00", STR_PAD_LEFT);
    return [$res, "\x04" . $x . $y, $d];
}

// ── HKDF (RFC 5869), SHA-256 ────────────────────────────────────────────────

function wp_hkdf_extract(string $salt, string $ikm): string {
    return hash_hmac('sha256', $ikm, $salt, true);
}

function wp_hkdf_expand(string $prk, string $info, int $length): string {
    return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $length);
}

// ── RFC 8291: encrypt one message for one subscriber ────────────────────────

/**
 * @return array{payload:string, salt:string, serverPublic:string} raw binary
 *         aes128gcm body ready to POST, plus the pieces needed for headers.
 */
function wp_encrypt_payload(string $plaintext, string $clientPublicB64, string $clientAuthB64): array {
    $clientPublic65 = wp_b64url_decode($clientPublicB64); // subscriber's p256dh
    $authSecret     = wp_b64url_decode($clientAuthB64);   // subscriber's auth

    [$ephPrivRes, $serverPublic65] = wp_generate_keypair();
    $clientPubRes = wp_public_key_from_point($clientPublic65);
    if (!$clientPubRes || !$ephPrivRes) {
        throw new RuntimeException('Could not build EC keys for push encryption.');
    }

    $ecdhSecret = openssl_pkey_derive($clientPubRes, $ephPrivRes, 32);
    if ($ecdhSecret === false) {
        throw new RuntimeException('ECDH derive failed for push encryption.');
    }

    $prkKey  = wp_hkdf_extract($authSecret, $ecdhSecret);
    $keyInfo = "WebPush: info\x00" . $clientPublic65 . $serverPublic65;
    $ikm     = wp_hkdf_expand($prkKey, $keyInfo, 32);

    $salt = random_bytes(16);
    $prk  = wp_hkdf_extract($salt, $ikm);

    $cek   = wp_hkdf_expand($prk, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = wp_hkdf_expand($prk, "Content-Encoding: nonce\x00", 12);

    // Single-record aes128gcm: plaintext + 0x02 padding delimiter, no extra padding.
    $padded = $plaintext . "\x02";
    $tag = '';
    $ciphertext = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) {
        throw new RuntimeException('AES-128-GCM encryption failed for push payload.');
    }

    $recordSize = pack('N', 4096);
    $header = $salt . $recordSize . chr(strlen($serverPublic65)) . $serverPublic65;

    return ['payload' => $header . $ciphertext . $tag, 'serverPublic' => $serverPublic65];
}

// ── RFC 8292: VAPID JWT (ES256) ──────────────────────────────────────────────

/** DER ECDSA signature -> raw fixed-length r||s, as required by JWS ES256. */
function wp_der_sig_to_raw(string $der): string {
    $offset = 2; // skip SEQUENCE tag + length byte (short form; VAPID P-256 sigs are always < 128 bytes total)
    $offset++;   // skip INTEGER tag for r
    $rLen = ord($der[$offset]); $offset++;
    $r = substr($der, $offset, $rLen); $offset += $rLen;
    $offset++;   // skip INTEGER tag for s
    $sLen = ord($der[$offset]); $offset++;
    $s = substr($der, $offset, $sLen);

    // DER integers can carry a leading 0x00 (to stay non-negative) or be
    // shorter than 32 bytes; normalise both to a fixed 32-byte big-endian.
    $r = ltrim($r, "\x00"); $s = ltrim($s, "\x00");
    $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

function wp_vapid_headers(string $endpoint): array {
    $vapidPublicB64  = defined('VAPID_PUBLIC_KEY') ? VAPID_PUBLIC_KEY : '';
    $vapidPrivateB64 = defined('VAPID_PRIVATE_KEY') ? VAPID_PRIVATE_KEY : '';
    if (!$vapidPublicB64 || !$vapidPrivateB64) {
        throw new RuntimeException('VAPID keys are not configured.');
    }

    $parts = parse_url($endpoint);
    $aud = $parts['scheme'] . '://' . $parts['host'];

    $header  = wp_b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = wp_b64url_encode(json_encode([
        'aud' => $aud,
        'exp' => time() + 12 * 3600,
        'sub' => 'mailto:' . (defined('VAPID_CONTACT_EMAIL') ? VAPID_CONTACT_EMAIL : 'admin@' . ($_SERVER['HTTP_HOST'] ?? 'localhost')),
    ]));
    $unsigned = $header . '.' . $payload;

    $publicPoint = wp_b64url_decode($vapidPublicB64);
    $privScalar  = wp_b64url_decode($vapidPrivateB64);
    $privRes = wp_private_key_from_raw($privScalar, $publicPoint);
    if (!$privRes) {
        throw new RuntimeException('Could not load VAPID private key.');
    }

    $derSig = '';
    openssl_sign($unsigned, $derSig, $privRes, OPENSSL_ALGO_SHA256);
    $rawSig = wp_der_sig_to_raw($derSig);

    $jwt = $unsigned . '.' . wp_b64url_encode($rawSig);

    return [
        'Authorization: vapid t=' . $jwt . ', k=' . $vapidPublicB64,
    ];
}

/**
 * Send one Web Push notification. Returns true on success (HTTP 2xx/3xx from
 * the push service), false otherwise. On a 404/410 the subscription is dead
 * and the caller should delete it.
 */
function wp_send(array $subscription, array $payloadData): array {
    $endpoint = $subscription['endpoint'];
    $json = json_encode($payloadData, JSON_UNESCAPED_UNICODE);

    try {
        $enc = wp_encrypt_payload($json, $subscription['p256dh'], $subscription['auth_key']);
        $vapidHeaders = wp_vapid_headers($endpoint);
    } catch (Throwable $e) {
        error_log('Web push encrypt/vapid failed: ' . $e->getMessage());
        return ['ok' => false, 'status' => 0];
    }

    $headers = array_merge($vapidHeaders, [
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'TTL: 86400',
    ]);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $enc['payload'],
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['ok' => $status >= 200 && $status < 300, 'status' => $status];
}

/**
 * Push a notification to every subscription a user has registered (they may
 * have several devices/browsers). Best-effort: never throws, dead
 * subscriptions (404/410) are pruned automatically.
 */
function send_push_to_user(int $userId, string $title, string $body, ?string $url = null): void {
    if (!defined('VAPID_PUBLIC_KEY') || !VAPID_PUBLIC_KEY) return; // not configured yet

    $subs = db_query("SELECT * FROM push_subscriptions WHERE user_id=?", [$userId]);
    foreach ($subs as $sub) {
        $result = wp_send($sub, [
            'title' => $title,
            'body'  => $body,
            'url'   => $url ?: APP_URL,
            'icon'  => APP_URL . '/image/language-hub-logo-192.png',
        ]);
        if (!$result['ok'] && in_array($result['status'], [404, 410], true)) {
            db_exec("DELETE FROM push_subscriptions WHERE id=?", [$sub['id']]);
        }
    }
}
