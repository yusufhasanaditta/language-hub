<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// SSLCommerz calls this URL back via a cross-site POST from their own domain
// (an auto-submitting form on the gateway page). Because session cookies are
// SameSite=Strict site-wide (config/config.php), the browser will NOT send
// our `lh_sess` cookie on that cross-site hop. If we called session_start()
// anyway, PHP would silently create a brand-new empty session and send a
// Set-Cookie for it — which then OVERWRITES the student's real, logged-in
// session cookie on their browser, bouncing them to the login page right
// after they paid (require_login() on the next page sees no user).
//
// The order lookup and enrollment below never depend on the session (they
// use $orderId/$order['user_id'] from the database), so it's safe to skip
// session entirely when the cookie wasn't sent, and report status via a URL
// flag instead of flash() for that case.
session_name('lh_sess');
$hadSessionCookie = isset($_COOKIE['lh_sess']);
if ($hadSessionCookie) {
    session_start_secure();
}

// Redirect helper: uses flash() only when we know a real session survived
// the round trip; otherwise stays silent rather than clobbering the cookie.
function pc_bail(bool $hadCookie, string $type, string $message, string $url): never {
    if ($hadCookie) flash($type, $message);
    redirect($url);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pc_bail($hadSessionCookie, 'error', 'Invalid callback request.', APP_URL . '/courses.php');
}

$status = $_POST['status'] ?? '';
$val_id = $_POST['val_id'] ?? '';
// These POST values are only used for routing the user back on failure.
// Everything that grants access is re-derived below from the gateway's
// server-side validation response and our own database.
$orderId = (int)($_POST['value_a'] ?? 0);
$cid = (int)($_POST['value_b'] ?? 0);

if (!$orderId || !$cid) {
    pc_bail($hadSessionCookie, 'error', 'Missing critical order information.', APP_URL . '/courses.php');
}

if ($status === 'FAILED' || $status === 'CANCELLED') {
    pc_bail($hadSessionCookie, 'error', 'Payment was ' . strtolower($status) . '.', APP_URL . '/checkout.php?course=' . $cid);
}

if ($status === 'VALID' && $val_id) {
    $sslcz_store_id = get_setting('sslcz_store_id');
    $sslcz_store_pass = get_setting('sslcz_store_pass');
    $sslcz_is_live = get_setting('sslcz_is_live') === '1';

    $requested_url = $sslcz_is_live
        ? ("https://securepay.sslcommerz.com/validator/api/validationserverAPI.php?val_id=" . urlencode($val_id) . "&store_id=" . urlencode($sslcz_store_id) . "&store_passwd=" . urlencode($sslcz_store_pass) . "&v=1&format=json")
        : ("https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php?val_id=" . urlencode($val_id) . "&store_id=" . urlencode($sslcz_store_id) . "&store_passwd=" . urlencode($sslcz_store_pass) . "&v=1&format=json");

    $handle = curl_init();
    curl_setopt($handle, CURLOPT_URL, $requested_url);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, APP_ENV === 'production'); // always verify on the live server
    $result = curl_exec($handle);
    $code = curl_getinfo($handle, CURLINFO_HTTP_CODE);

    if ($code == 200 && !( curl_errno($handle))) {
        $result = json_decode($result, true);

        // Verify status and EXACT amount matching the order in DB
        $order = db_row("SELECT * FROM orders WHERE id=?", [$orderId]);
        if (!$order) {
            pc_bail($hadSessionCookie, 'error', 'Order not found.', APP_URL . '/courses.php');
        }

        // The validated transaction must belong to THIS order: checkout.php
        // builds tran_id as "BA_<uniqid>_<orderId>" and sends value_a=orderId.
        // Without this, one real payment's val_id could be replayed to mark a
        // different order paid.
        $vTranId = (string)($result['tran_id'] ?? '');
        $tranMatchesOrder = $vTranId !== ''
            && str_ends_with($vTranId, '_' . $orderId)
            && (int)($result['value_a'] ?? 0) === $orderId;

        // Course and coupon come from our DB / the gateway-validated payload,
        // never from the browser POST.
        $cid = (int)db_val(
            "SELECT item_id FROM order_items WHERE order_id=? AND item_type='course' LIMIT 1",
            [$orderId]
        );
        $couponIdToUse = (int)($result['value_c'] ?? 0) ?: null;

        if (!$tranMatchesOrder || !$cid) {
            pc_bail($hadSessionCookie, 'error', 'Payment does not match this order.', APP_URL . '/courses.php');
        }

        if ($result['status'] === 'VALID' || $result['status'] === 'VALIDATED') {
            if (abs((float)$result['amount'] - (float)$order['total']) < 0.01 && $result['currency'] === 'BDT') {
                // Atomic pending→paid flip: if two callbacks race, only one wins.
                $invoiceNo = $order['invoice_no']
                    ?: 'LH-' . date('Y') . '-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT);
                $buyer = db_row("SELECT name, email, phone FROM users WHERE id=?", [$order['user_id']]);
                $flipped = $order['status'] !== 'paid' && db_exec(
                    "UPDATE orders
                        SET status='paid', paid_at=NOW(), invoice_no=?,
                            billing_name=?, billing_phone=?, billing_email=?
                      WHERE id=? AND status<>'paid'",
                    [
                        $invoiceNo,
                        $buyer['name']  ?? null,
                        $buyer['phone'] ?? null,
                        $buyer['email'] ?? null,
                        $orderId,
                    ]
                ) > 0;

                if ($flipped) {
                    // Insert Payment (validated values only)
                    db_insert(
                        "INSERT INTO payments (order_id, gateway, tran_id, val_id, amount, status, ipn_verified, paid_at, created_at)
                         VALUES (?, 'sslcommerz', ?, ?, ?, 'success', 1, NOW(), NOW())",
                        [$orderId, $vTranId, $val_id, (float)$result['amount']]
                    );

                    // Enroll Student
                    $userId = $order['user_id'];
                    db_exec(
                        "INSERT IGNORE INTO enrollments (user_id,course_id,status,order_id,enrolled_at)
                         VALUES (?,?,'active',?,NOW())",
                        [$userId, $cid, $orderId]
                    );

                    // Update Course counts
                    db_exec("UPDATE courses SET enrolled_count=enrolled_count+1 WHERE id=?", [$cid]);

                    // Update Coupon usage
                    if ($couponIdToUse) {
                        db_exec("UPDATE coupons SET used_count=used_count+1 WHERE id=?", [$couponIdToUse]);
                    }

                    // Notification carrying the invoice link
                    db_insert(
                        "INSERT INTO notifications (user_id, kind, title, body, action_url) VALUES (?,?,?,?,?)",
                        [
                            $userId,
                            'payment',
                            'Payment received — invoice ' . $invoiceNo,
                            'Your enrollment is active. Tap to view or download the invoice.',
                            APP_URL . '/invoice.php?order=' . $orderId,
                        ]
                    );

                    $successUrl = APP_URL . '/my-courses.php';
                    if ($hadSessionCookie) {
                        flash('success', 'Payment successful. You are enrolled — invoice ' . $invoiceNo . ' is in your Orders page.');
                    } else {
                        // No session survived the gateway round trip — signal success
                        // via a URL flag instead; my-courses.php re-verifies this
                        // against the database before showing a banner for it.
                        $successUrl .= '?just_enrolled=' . (int) $cid . '&order=' . (int) $orderId;
                    }
                    redirect($successUrl);
                } else {
                    pc_bail($hadSessionCookie, 'info', 'This order is already paid.', APP_URL . '/my-courses.php');
                }
            } else {
                pc_bail($hadSessionCookie, 'error', 'Payment amount mismatch. Paid: ' . $result['amount'] . ', Required: ' . $order['total'], APP_URL . '/checkout.php?course=' . $cid);
            }
        } else {
            pc_bail($hadSessionCookie, 'error', 'Payment validation failed at Gateway.', APP_URL . '/checkout.php?course=' . $cid);
        }
    } else {
        pc_bail($hadSessionCookie, 'error', 'Failed to connect to validation server.', APP_URL . '/checkout.php?course=' . $cid);
    }
} else {
    pc_bail($hadSessionCookie, 'error', 'Invalid payment status or missing validation ID.', APP_URL . '/checkout.php?course=' . $cid);
}
