<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();
require_login();

$cid    = sanitize_int($_GET['course'] ?? 0);
$userId = (int)$_SESSION['user_id'];
$user   = current_user();

if (!$cid) { flash('error', 'Course not found.'); redirect(APP_URL . '/courses.php'); }

$course = db_row("
    SELECT c.*, cat.name_bn as cat_name, u.name as teacher_name
    FROM courses c
    JOIN categories cat ON cat.id=c.category_id
    JOIN teacher_profiles tp ON tp.id=c.teacher_id
    JOIN users u ON u.id=tp.user_id
    WHERE c.id=? AND c.status='published'
", [$cid]);
if (!$course) { flash('error', 'Course not found.'); redirect(APP_URL . '/courses.php'); }

if (is_enrolled($cid)) {
    flash('info', 'You are already enrolled.');
    redirect(APP_URL . '/learn.php?course=' . $cid);
}

$price = $course['is_free'] ? 0 : (float)($course['sale_price'] ?: $course['price']);
if ($price <= 0) {
    // Free course — just enroll
    db_exec("INSERT IGNORE INTO enrollments (user_id,course_id,status,enrolled_at) VALUES (?,?,'active',NOW())", [$userId,$cid]);
    db_exec("UPDATE courses SET enrolled_count=enrolled_count+1 WHERE id=?", [$cid]);
    create_notification(
        $userId, 'enrollment', 'Enrolled in ' . $course['title_bn'],
        'You now have full access to this course. Happy learning!',
        APP_URL . '/learn.php?course=' . $cid
    );
    flash('success', 'Free enrollment complete!');
    redirect(APP_URL . '/my-courses.php');
}

// Apply coupon
$couponCode    = '';
$couponDiscount = 0;
$coupon = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $postAction = $_POST['post_action'] ?? '';
    
    // Always check for coupon in POST, whether applying it or placing order
    $postedCoupon = $_POST['coupon'] ?? '';
    if ($postedCoupon) {
        $couponCode = strtoupper(sanitize_string($postedCoupon, 30));
        $coupon = db_row(
            "SELECT * FROM coupons WHERE code=? AND is_active=1
             AND (expires_at IS NULL OR expires_at>=CURDATE())
             AND (max_uses=0 OR used_count<max_uses)",
            [$couponCode]
        );
        if (!$coupon) {
            if ($postAction === 'apply_coupon') flash('error', 'This coupon code is invalid or expired.');
            $couponCode = '';
        } else {
            $couponDiscount = $coupon['type'] === 'percent'
                ? round($price * $coupon['value'] / 100, 2)
                : min($price, (float)$coupon['value']);
            if ($postAction === 'apply_coupon') flash('success', 'Coupon applied!');
        }
    }
}

$finalPrice = max(0, $price - $couponDiscount);

// Pending order in session (used after SSLCommerz callback)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && ($_POST['post_action'] ?? '') === 'place_order') {
    rate_limit('checkout', 10);

    $txnId = 'LH-' . strtoupper(bin2hex(random_bytes(6)));
    $couponIdToUse = $coupon['id'] ?? null;

    if ($finalPrice <= 0) {
        // Coupon made it free
        $orderId = db_insert(
            "INSERT INTO orders (user_id, subtotal, discount, total, coupon_code, status, created_at, paid_at)
             VALUES (?,?,?,?,?,'paid',NOW(),NOW())",
            [$userId, $price, $couponDiscount, $finalPrice, $couponCode ?: null]
        );

        db_insert(
            "INSERT INTO order_items (order_id, item_type, item_id, title, price)
             VALUES (?, 'course', ?, ?, ?)",
            [$orderId, $cid, $course['title_bn'], $finalPrice]
        );

        db_exec(
            "INSERT IGNORE INTO enrollments (user_id,course_id,status,order_id,enrolled_at)
             VALUES (?,?,'active',?,NOW())",
            [$userId, $cid, $orderId]
        );

        db_exec("UPDATE courses SET enrolled_count=enrolled_count+1 WHERE id=?", [$cid]);
        if ($couponIdToUse) {
            db_exec("UPDATE coupons SET used_count=used_count+1 WHERE id=?", [$couponIdToUse]);
        }

        create_notification(
            $userId, 'enrollment', 'Enrolled in ' . $course['title_bn'],
            'You now have full access to this course. Happy learning!',
            APP_URL . '/learn.php?course=' . $cid
        );

        flash('success', 'Free enrollment complete using coupon!');
        redirect(APP_URL . '/my-courses.php');
    }

    // Paid course - Place pending order first
    $orderId = db_insert(
        "INSERT INTO orders (user_id, subtotal, discount, total, coupon_code, status, created_at)
         VALUES (?,?,?,?,?,'pending',NOW())",
        [$userId, $price, $couponDiscount, $finalPrice, $couponCode ?: null]
    );

    db_insert(
        "INSERT INTO order_items (order_id, item_type, item_id, title, price)
         VALUES (?, 'course', ?, ?, ?)",
        [$orderId, $cid, $course['title_bn'], $finalPrice]
    );

    // SSLCommerz integration
    $sslcz_enabled = get_setting('sslcz_enabled') === '1';
    $sslcz_store_id = get_setting('sslcz_store_id');
    $sslcz_store_pass = get_setting('sslcz_store_pass');
    $sslcz_is_live = get_setting('sslcz_is_live') === '1';

    if ($sslcz_enabled && $sslcz_store_id && $sslcz_store_pass) {
        $post_data = array();
        $post_data['store_id'] = $sslcz_store_id;
        $post_data['store_passwd'] = $sslcz_store_pass;
        $post_data['total_amount'] = $finalPrice;
        $post_data['currency'] = "BDT";
        $post_data['tran_id'] = "LH_".uniqid()."_".$orderId;
        $post_data['success_url'] = APP_URL . "/payment_callback.php";
        $post_data['fail_url'] = APP_URL . "/payment_callback.php";
        $post_data['cancel_url'] = APP_URL . "/payment_callback.php";

        // CUSTOMER INFORMATION
        $post_data['cus_name'] = $user['name'] ?: 'Student';
        $post_data['cus_email'] = $user['email'] ?: 'student@bacademy.com';
        $post_data['cus_add1'] = "Dhaka";
        $post_data['cus_city'] = "Dhaka";
        $post_data['cus_country'] = "Bangladesh";
        $post_data['cus_phone'] = $user['phone'] ?: '01700000000';
        
        $post_data['shipping_method'] = "NO";
        $post_data['product_name'] = $course['title_bn'] ?: 'Course Enrollment';
        $post_data['product_category'] = "Education";
        $post_data['product_profile'] = "non-physical-goods";

        // Optional parameters to identify order & course in callback
        $post_data['value_a'] = $orderId;
        $post_data['value_b'] = $cid;
        if ($couponIdToUse) {
            $post_data['value_c'] = $couponIdToUse;
        }

        $direct_api_url = $sslcz_is_live 
            ? "https://securepay.sslcommerz.com/gwprocess/v4/api.php" 
            : "https://sandbox.sslcommerz.com/gwprocess/v4/api.php";

        $handle = curl_init();
        curl_setopt($handle, CURLOPT_URL, $direct_api_url);
        curl_setopt($handle, CURLOPT_TIMEOUT, 30);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($handle, CURLOPT_POST, 1);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, APP_ENV === 'production'); // always verify on the live server
        
        $content = curl_exec($handle);
        $code = curl_getinfo($handle, CURLINFO_HTTP_CODE);

        if ($code == 200 && !( curl_errno($handle))) {
            curl_close($handle);
            $sslcommerzResponse = json_decode($content, true);
            if (isset($sslcommerzResponse['GatewayPageURL']) && $sslcommerzResponse['GatewayPageURL'] !== "") {
                echo "<meta http-equiv='refresh' content='0;url=".$sslcommerzResponse['GatewayPageURL']."'>";
                echo "<script>window.location.href = '".$sslcommerzResponse['GatewayPageURL']."';</script>";
                exit;
            } else {
                flash('error', 'JSON Data parsing error from SSLCommerz.');
                redirect(APP_URL . '/checkout.php?course=' . $cid);
            }
        } else {
            curl_close($handle);
            flash('error', 'Failed to connect with SSLCommerz API.');
            redirect(APP_URL . '/checkout.php?course=' . $cid);
        }
    }

    // Fallback if not configured but trying to checkout
    // Demo payment — mark as paid immediately
    db_exec("UPDATE orders SET status='paid', paid_at=NOW() WHERE id=?", [$orderId]);
    
    db_insert(
        "INSERT INTO payments (order_id, gateway, tran_id, amount, status, paid_at, created_at)
         VALUES (?, 'manual', ?, ?, 'success', NOW(), NOW())",
        [$orderId, 'DEMO-'.$txnId, $finalPrice]
    );

    db_exec(
        "INSERT IGNORE INTO enrollments (user_id,course_id,status,order_id,enrolled_at)
         VALUES (?,?,'active',?,NOW())",
        [$userId, $cid, $orderId]
    );

    db_exec("UPDATE courses SET enrolled_count=enrolled_count+1 WHERE id=?", [$cid]);
    if ($couponIdToUse) {
        db_exec("UPDATE coupons SET used_count=used_count+1 WHERE id=?", [$couponIdToUse]);
    }

    create_notification(
        $userId, 'payment', 'Payment received for ' . $course['title_bn'],
        'Your enrollment is active. Tap to view the invoice.',
        APP_URL . '/invoice.php?order=' . $orderId
    );

    flash('success', 'Payment successful! (Demo mode) Start your course.');
    redirect(APP_URL . '/my-courses.php');
}


$page_title = 'Checkout — ' . $course['title_bn'];
$extra_css  = '<style>
@media (max-width: 768px) {
    .checkout-grid { grid-template-columns: 1fr !important; }
}
</style>';
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width:900px;padding:var(--sp-8) var(--sp-4)">
    <h1 style="font-size:var(--text-2xl);margin-bottom:var(--sp-6)">Checkout</h1>

    <div class="checkout-grid" style="display:grid;grid-template-columns:1fr 360px;gap:var(--sp-6)">
        <!-- Left: Order summary + coupon -->
        <div>
            <div class="card" style="padding:var(--sp-6);margin-bottom:var(--sp-5)">
                <h3 style="margin-bottom:var(--sp-4)">Order Summary</h3>
                <div style="display:flex;gap:var(--sp-4);align-items:flex-start">
                    <?php if ($course['thumbnail']): ?>
                    <img src="<?= e($course['thumbnail']) ?>" alt=""
                         style="width:100px;height:60px;object-fit:cover;border-radius:var(--r-md);flex-shrink:0">
                    <?php endif; ?>
                    <div>
                        <div style="font-weight:700;font-size:var(--text-base)"><?= e($course['title_bn']) ?></div>
                        <div style="color:var(--c-ink-muted);font-size:var(--text-sm);margin-top:4px">
                            <?= e($course['teacher_name']) ?> · <?= e($course['cat_name']) ?>
                        </div>
                    </div>
                </div>
                <div style="border-top:1px solid var(--c-border);margin-top:var(--sp-5);padding-top:var(--sp-5)">
                    <div style="display:flex;justify-content:space-between;margin-bottom:var(--sp-3)">
                        <span>Course Price</span>
                        <span style="font-family:var(--font-latin)"><?= number_format($price, 0) ?></span>
                    </div>
                    <?php if ($couponDiscount > 0): ?>
                    <div style="display:flex;justify-content:space-between;margin-bottom:var(--sp-3);color:var(--c-success)">
                        <span>Coupon Discount (<?= e($couponCode) ?>)</span>
                        <span style="font-family:var(--font-latin)">- <?= number_format($couponDiscount, 0) ?></span>
                    </div>
                    <?php endif; ?>
                    <div style="display:flex;justify-content:space-between;font-weight:800;font-size:var(--text-lg);border-top:1px solid var(--c-border);padding-top:var(--sp-3)">
                        <span>Total</span>
                        <span style="font-family:var(--font-latin);color:var(--c-primary)"><?= number_format($finalPrice, 0) ?></span>
                    </div>
                </div>
            </div>

            <!-- Coupon -->
            <div class="card" style="padding:var(--sp-5)">
                <h3 style="margin-bottom:var(--sp-4)">Coupon Code</h3>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="post_action" value="apply_coupon">
                    <div style="display:flex;gap:var(--sp-3)">
                        <input type="text" name="coupon" class="form-control"
                               value="<?= e($couponCode) ?>"
                               placeholder="BACADEMY10" style="text-transform:uppercase;font-family:var(--font-latin)">
                        <button type="submit" class="btn btn-ghost" style="white-space:nowrap">Apply</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right: Pay button + info -->
        <div>
            <div class="card" style="padding:var(--sp-6)">
                <h3 style="margin-bottom:var(--sp-5)">Make Payment</h3>
                <div style="font-size:var(--text-3xl);font-weight:800;font-family:var(--font-latin);color:var(--c-primary);margin-bottom:var(--sp-5)">
                    <?= number_format($finalPrice, 0) ?>
                </div>

                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="post_action" value="place_order">
                    <?php if ($couponCode): ?>
                    <input type="hidden" name="coupon" value="<?= e($couponCode) ?>">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-accent" style="width:100%;font-size:var(--text-base);padding:16px">
                        <span class="material-icons-round">payment</span>
                        Make Payment
                    </button>
                </form>

                <div style="margin-top:var(--sp-5);padding:var(--sp-4);background:var(--c-surface);border-radius:var(--r-md)">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:var(--sp-3);font-weight:700">
                        <span class="material-icons-round" style="color:var(--c-success);font-size:18px">verified</span>
                        30-day money-back guarantee
                    </div>
                    <ul style="list-style:none;padding:0;margin:0;font-size:var(--text-sm);color:var(--c-ink-muted)">
                        <li style="display:flex;gap:8px;margin-bottom:6px"><span class="material-icons-round" style="font-size:15px;color:var(--c-success)">check</span> Lifetime access</li>
                        <li style="display:flex;gap:8px;margin-bottom:6px"><span class="material-icons-round" style="font-size:15px;color:var(--c-success)">check</span> Certificate</li>
                        <li style="display:flex;gap:8px"><span class="material-icons-round" style="font-size:15px;color:var(--c-success)">check</span> Mobile access</li>
                    </ul>
                </div>

                <div style="margin-top:var(--sp-4);text-align:center">
                    <img src="<?= APP_URL ?>/assets/img/ssl-badge.png" alt="SSL Secured"
                         style="height:32px;opacity:.7" onerror="this.style.display='none'">
                    <div style="font-size:var(--text-xs);color:var(--c-ink-muted);margin-top:8px">
                        Secure payment by SSLCommerz
                    </div>
                </div>
            </div>

            <div style="text-align:center;margin-top:var(--sp-4)">
                <a href="<?= APP_URL ?>/course.php?slug=<?= e($course['slug']) ?>"
                   style="color:var(--c-ink-muted);font-size:var(--text-sm)">
                    ← Back to Course
                </a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

