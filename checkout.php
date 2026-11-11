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
    SELECT c.*, cat.name_bn as cat_name, cat.name_en as cat_name_en, cat.slug as cat_slug, u.name as teacher_name
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
    // Free course: enrolling is a POST from the course page (enroll.php),
    // never a side effect of opening a URL.
    redirect(course_url($course));
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

        flash('success', 'কুপনে ভর্তি সম্পন্ন। স্বাগতম!');
        redirect(APP_URL . '/learn.php?course=' . $cid . '&welcome=1');
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
        $post_data['cus_email'] = $user['email'] ?: 'student@languagehub.local';
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

    // Gateway not configured. On the live site this must never grant access.
    if (APP_ENV !== 'development') {
        db_exec("UPDATE orders SET status='failed' WHERE id=?", [$orderId]);
        flash('error', 'অনলাইন পেমেন্ট এখন চালু নেই। একটু পরে চেষ্টা করুন বা আমাদের জানান।');
        redirect(APP_URL . '/checkout.php?course=' . $cid);
    }
    // Local development only: mark as paid so the whole flow can be tested
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

    flash('success', 'পেমেন্ট সম্পন্ন (লোকাল টেস্ট)। স্বাগতম!');
    redirect(APP_URL . '/learn.php?course=' . $cid . '&welcome=1');
}


$page_title = 'Checkout — ' . $course['title_bn'];
$body_class = 'lh-checkout';
require_once __DIR__ . '/includes/lh_components.php';
$L = lh_lang($course['cat_slug'] ?? null);
include __DIR__ . '/includes/header.php';
?>

<section class="container lh-co">
    <a href="<?= e(course_url($course)) ?>" class="lh-c-back"><span class="material-icons-round">arrow_back</span><?= t('কোর্সে ফিরুন', 'Back to course') ?></a>
    <h1 class="lh-co-title"><?= t('পেমেন্ট', 'Checkout') ?></h1>

    <ol class="lh-co-steps" aria-label="Steps">
        <li class="is-done"><span class="lh-co-n">1</span><?= t('কোর্স', 'Course') ?></li>
        <li class="is-on"><span class="lh-co-n">2</span><?= t('পেমেন্ট', 'Payment') ?></li>
        <li><span class="lh-co-n">3</span><?= t('ক্লাস শুরু', 'Start') ?></li>
    </ol>

    <div class="lh-co-grid">
        <div class="lh-co-main">
            <!-- The course as a ticket -->
            <article class="lh-co-item" style="--lang:var(<?= $L['var'] ?>)" data-reveal>
                <span class="lh-co-glyph" aria-hidden="true"><?= e($L['glyph']) ?></span>
                <div>
                    <p class="lh-ticket-meta"><?= t($course['cat_name'], $course['cat_name_en'] ?? $course['cat_name']) ?> · <?= $course['type'] === 'live' ? t('লাইভ ব্যাচ', 'Live batch') : t('রেকর্ডেড', 'Recorded') ?></p>
                    <h2><?= t($course['title_bn'], $course['title_en'] ?: $course['title_bn']) ?></h2>
                    <p class="lh-ticket-teacher"><?= e($course['teacher_name']) ?></p>
                </div>
            </article>

            <!-- Coupon -->
            <form method="POST" class="lh-co-coupon" data-reveal>
                <?= csrf_field() ?>
                <input type="hidden" name="post_action" value="apply_coupon">
                <label for="coupon" class="lh-co-label"><span class="material-icons-round">sell</span><?= t('কুপন কোড আছে?', 'Have a coupon?') ?></label>
                <div class="lh-co-coupon-row">
                    <input type="text" id="coupon" name="coupon" class="lh-input" value="<?= e($couponCode) ?>" autocomplete="off" autocapitalize="characters" placeholder="LH10">
                    <button type="submit" class="btn btn-outline"><?= t('প্রয়োগ', 'Apply') ?></button>
                </div>
            </form>
        </div>

        <aside class="lh-co-side">
            <div class="lh-co-sum" data-reveal="right">
                <div class="lh-co-line"><span><?= t('কোর্স ফি', 'Course fee') ?></span><span>৳<?= t_num(format_price($price)) ?></span></div>
                <?php if ($couponDiscount > 0): ?>
                <div class="lh-co-line lh-co-disc"><span><?= t('কুপন', 'Coupon') ?> (<?= e($couponCode) ?>)</span><span>−৳<?= t_num(format_price($couponDiscount)) ?></span></div>
                <?php endif; ?>
                <div class="lh-co-line lh-co-total"><span><?= t('মোট', 'Total') ?></span><span class="lh-price">৳<?= t_num(format_price($finalPrice)) ?></span></div>

                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="post_action" value="place_order">
                    <?php if ($couponCode): ?><input type="hidden" name="coupon" value="<?= e($couponCode) ?>"><?php endif; ?>
                    <button type="submit" class="btn btn-accent lh-btn-block lh-co-pay" data-busy>
                        <span class="material-icons-round">lock</span><?= t('৳' . bn_num(format_price($finalPrice)) . ' পেমেন্ট করুন', 'Pay ৳' . format_price($finalPrice)) ?>
                    </button>
                </form>

                <div class="lh-co-methods" aria-label="Payment methods">
                    <span>bKash</span><span>Nagad</span><span>Rocket</span><span>Card</span>
                </div>
                <p class="lh-co-secure"><span class="material-icons-round">verified_user</span><?= t('SSLCommerz-এর মাধ্যমে নিরাপদ পেমেন্ট', 'Secure payment by SSLCommerz') ?></p>
                <ul class="lh-c-perks">
                    <li><span class="material-icons-round">bolt</span><?= t('পেমেন্টের সাথে সাথেই ক্লাসরুম খুলে যাবে', 'Classroom opens right after payment') ?></li>
                    <li><span class="material-icons-round">receipt_long</span><?= t('ইনভয়েস আপনার প্রোফাইলে থাকবে', 'Invoice saved in your profile') ?></li>
                </ul>
            </div>
        </aside>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
