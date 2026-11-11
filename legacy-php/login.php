<?php
// One screen for login AND sign-up: email → 6-digit code → (new users) name.
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/auth_shell.php';

session_start_secure();
send_security_headers();

if (is_logged_in()) redirect(APP_URL . '/dashboard.php');

$next = filter_var($_POST['next'] ?? $_GET['next'] ?? ($_SESSION['_login_next'] ?? ''), FILTER_SANITIZE_URL);
if (!str_starts_with($next, APP_URL)) $next = APP_URL . '/dashboard.php';
$_SESSION['_login_next'] = $next;

if (isset($_GET['restart'])) {
    unset($_SESSION['_eotp'], $_SESSION['_new_email'], $_SESSION['_pending_uid'], $_SESSION['_pending_at']);
    redirect(APP_URL . '/login.php');
}

$error = '';
$devCode = $_SESSION['_eotp_dev'] ?? null;

// Log in, unless the account is already on MAX_DEVICE_SESSIONS devices —
// then ask which device to sign out first.
function finish_login(array $user, string $next): never {
    unset($_SESSION['_login_next'], $_SESSION['_eotp_dev']);
    db_exec("DELETE FROM user_sessions WHERE user_id=? AND device_name=?", [$user['id'], get_device_info()]);
    if (count(get_active_sessions($user['id'])) >= MAX_DEVICE_SESSIONS) {
        $_SESSION['_pending_uid'] = $user['id'];
        $_SESSION['_pending_at']  = time();
        redirect(APP_URL . '/login.php');
    }
    login_user($user);
    db_exec("UPDATE users SET last_login=NOW(), is_verified=1 WHERE id=?", [$user['id']]);
    redirect($next);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_verify()) {
        $error = t('অনুরোধটি যাচাই করা যায়নি। আবার চেষ্টা করুন।', 'Could not verify the request. Please try again.');

    } elseif ($action === 'send' || $action === 'resend') {
        $email = $action === 'resend' ? ($_SESSION['_eotp']['email'] ?? '') : strtolower(sanitize_string($_POST['email'] ?? '', 150));
        if (!validate_email($email)) {
            $error = t('সঠিক ইমেইল দিন।', 'Enter a valid email address.');
        } elseif ($action === 'resend' && time() - (int)($_SESSION['_eotp']['sent'] ?? 0) < 30) {
            $error = t('একটু অপেক্ষা করে আবার চেষ্টা করুন।', 'Please wait a few seconds before resending.');
        } else {
            rate_limit('email_otp_send', 5);
            $r = email_otp_issue($email);
            $_SESSION['_eotp_dev'] = $devCode = $r['dev_code'];
            if (!$r['sent'] && !$r['dev_code']) {
                unset($_SESSION['_eotp']);
                $error = t('কোড পাঠানো যায়নি। কিছুক্ষণ পর আবার চেষ্টা করুন।', 'We could not send the code. Please try again shortly.');
            }
        }

    } elseif ($action === 'verify') {
        rate_limit('email_otp_verify', 15);
        $email = $_SESSION['_eotp']['email'] ?? '';
        $code  = preg_replace('/\D/', '', $_POST['code'] ?? '');
        $res   = email_otp_check($code);
        if ($res === 'ok') {
            unset($_SESSION['_eotp_dev']);
            $user = db_row("SELECT * FROM users WHERE email=?", [$email]);
            if ($user && !(int)$user['is_active']) {
                $error = t('এই অ্যাকাউন্টটি বন্ধ আছে। আমাদের সাথে যোগাযোগ করুন।', 'This account is disabled. Please contact us.');
            } elseif ($user) {
                finish_login($user, $next);
            } else {
                $_SESSION['_new_email'] = ['email' => $email, 'at' => time()];
            }
        } else {
            $error = match ($res) {
                'wrong'   => t('কোডটি সঠিক নয়।', 'That code is not right.'),
                'locked'  => t('অনেকবার ভুল হয়েছে। নতুন কোড নিন।', 'Too many tries. Please request a new code.'),
                default   => t('কোডের মেয়াদ শেষ। নতুন কোড নিন।', 'The code expired. Please request a new one.'),
            };
        }

    } elseif ($action === 'name') {
        $pending = $_SESSION['_new_email'] ?? null;
        $name = sanitize_string($_POST['name'] ?? '', 100);
        if (!$pending || time() - $pending['at'] > 900) {
            unset($_SESSION['_new_email']);
            $error = t('সময় শেষ হয়ে গেছে। আবার শুরু করুন।', 'This took too long. Please start again.');
        } elseif (mb_strlen($name) < 2) {
            $error = t('আপনার নাম লিখুন।', 'Please enter your name.');
        } else {
            $uid = db_val("SELECT id FROM users WHERE email=?", [$pending['email']])
                ?: db_insert("INSERT INTO users (name, email, role_id, is_active, is_verified) VALUES (?,?,?,1,1)",
                             [$name, $pending['email'], ROLE_STUDENT]);
            unset($_SESSION['_new_email']);
            $user = db_row("SELECT * FROM users WHERE id=?", [(int)$uid]);
            flash('success', 'স্বাগতম, ' . $user['name'] . '!');
            finish_login($user, $next);
        }

    } elseif ($action === 'kill' && isset($_POST['kill_session_id'])) {
        $uid = (int)($_SESSION['_pending_uid'] ?? 0);
        if ($uid && time() - (int)($_SESSION['_pending_at'] ?? 0) <= 300) {
            kill_user_session(sanitize_int($_POST['kill_session_id']), $uid);
            unset($_SESSION['_pending_uid'], $_SESSION['_pending_at']);
            $user = db_row("SELECT * FROM users WHERE id=? AND is_active=1", [$uid]);
            if ($user) finish_login($user, $next);
        }
        unset($_SESSION['_pending_uid'], $_SESSION['_pending_at']);
    }
}

// Which step to show
$pendingSessions = [];
if (!empty($_SESSION['_pending_uid']) && time() - (int)$_SESSION['_pending_at'] <= 300) {
    $pendingSessions = get_active_sessions((int)$_SESSION['_pending_uid']);
}
$step = $pendingSessions ? 'devices'
      : (!empty($_SESSION['_new_email']) ? 'name'
      : (!empty($_SESSION['_eotp']) ? 'code' : 'email'));
$codeEmail = $_SESSION['_eotp']['email'] ?? '';

auth_open('Log in');
?>
<div class="lh-step" data-step="<?= $step ?>">
<?php if ($step === 'email'): ?>
    <span class="lh-auth-ico material-icons-round" aria-hidden="true">waving_hand</span>
    <h1 class="lh-auth-title"><?= t('শুরু করুন', 'Let’s get started') ?></h1>
    <p class="lh-auth-sub"><?= t('ইমেইল দিন — আমরা একটি কোড পাঠাব। নতুন হলে অ্যাকাউন্ট নিজে থেকেই তৈরি হবে।',
                                 'Enter your email and we’ll send you a code. New here? Your account is created automatically.') ?></p>
    <?php if ($error): ?><p class="lh-auth-error" role="alert"><?= $error ?></p><?php endif; ?>
    <form method="POST" class="lh-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send">
        <label for="email"><?= t('ইমেইল', 'Email') ?></label>
        <input type="email" id="email" name="email" class="lh-input" inputmode="email" autocomplete="email"
               placeholder="name@gmail.com" value="<?= e($_POST['email'] ?? '') ?>" required autofocus>
        <button type="submit" class="btn btn-primary lh-btn-block" data-busy><?= t('কোড পাঠান', 'Send code') ?> <span class="material-icons-round">arrow_forward</span></button>
    </form>
    <p class="lh-auth-fine"><?= t('চালিয়ে গেলে আপনি আমাদের', 'By continuing you agree to our') ?>
        <a href="<?= APP_URL ?>/terms.php"><?= t('শর্তাবলিতে', 'Terms') ?></a> <?= t('সম্মত হচ্ছেন।', '.') ?></p>

<?php elseif ($step === 'code'): ?>
    <span class="lh-auth-ico material-icons-round" aria-hidden="true">mark_email_unread</span>
    <h1 class="lh-auth-title"><?= t('কোডটি দিন', 'Enter the code') ?></h1>
    <p class="lh-auth-sub"><?= t('কোড পাঠানো হয়েছে:', 'We sent a code to') ?> <strong><?= e($codeEmail) ?></strong></p>
    <?php if ($devCode): ?>
        <p class="lh-auth-dev"><?= t('লোকাল টেস্ট (ইমেইল সেটআপ হয়নি) — কোড:', 'Local test (email not set up) — code:') ?> <b><?= e($devCode) ?></b></p>
    <?php endif; ?>
    <?php if ($error): ?><p class="lh-auth-error" role="alert"><?= $error ?></p><?php endif; ?>
    <form method="POST" class="lh-form" id="codeForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify">
        <label for="code" class="sr-only"><?= t('৬ সংখ্যার কোড', '6-digit code') ?></label>
        <div class="lh-code">
            <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code"
                   pattern="\d{<?= OTP_LENGTH ?>}" maxlength="<?= OTP_LENGTH ?>" required autofocus>
            <div class="lh-code-boxes" aria-hidden="true"><?php for ($i = 0; $i < OTP_LENGTH; $i++): ?><span></span><?php endfor; ?></div>
        </div>
        <button type="submit" class="btn btn-primary lh-btn-block" data-busy><?= t('যাচাই করুন', 'Verify') ?></button>
    </form>
    <div class="lh-auth-row">
        <a href="<?= APP_URL ?>/login.php?restart=1"><?= t('ইমেইল বদলান', 'Change email') ?></a>
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="resend">
            <button type="submit" class="lh-linkbtn" id="resendBtn" data-wait="30"><?= t('আবার পাঠান', 'Resend code') ?></button></form>
    </div>
    <p class="lh-auth-fine"><?= t('ইমেইল না পেলে Spam/Promotions ফোল্ডার দেখুন।', 'Can’t find it? Check your Spam or Promotions folder.') ?></p>

<?php elseif ($step === 'name'): ?>
    <span class="lh-auth-ico material-icons-round" aria-hidden="true">badge</span>
    <h1 class="lh-auth-title"><?= t('আপনার নাম কী?', 'What’s your name?') ?></h1>
    <p class="lh-auth-sub"><?= t('সার্টিফিকেটে এই নামটিই থাকবে।', 'This name will appear on your certificates.') ?></p>
    <?php if ($error): ?><p class="lh-auth-error" role="alert"><?= $error ?></p><?php endif; ?>
    <form method="POST" class="lh-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="name">
        <label for="name"><?= t('পুরো নাম', 'Full name') ?></label>
        <input type="text" id="name" name="name" class="lh-input" autocomplete="name" maxlength="100" required autofocus>
        <button type="submit" class="btn btn-primary lh-btn-block" data-busy><?= t('শুরু করুন', 'Start learning') ?></button>
    </form>

<?php else: ?>
    <span class="lh-auth-ico material-icons-round" aria-hidden="true">devices</span>
    <h1 class="lh-auth-title"><?= t('ডিভাইস লিমিট', 'Device limit reached') ?></h1>
    <p class="lh-auth-sub"><?= t('একটি অ্যাকাউন্ট সর্বোচ্চ ২টি ডিভাইসে চালু থাকে। একটি থেকে লগআউট করুন।',
                                 'An account can be signed in on 2 devices. Sign out of one to continue.') ?></p>
    <div class="lh-devices">
        <?php foreach ($pendingSessions as $ps): ?>
        <form method="POST" class="lh-device">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="kill">
            <input type="hidden" name="kill_session_id" value="<?= (int)$ps['id'] ?>">
            <span class="material-icons-round" aria-hidden="true">smartphone</span>
            <span class="lh-device-name"><?= e($ps['device_name'] ?? 'Device') ?><small><?= date('d M, H:i', strtotime($ps['last_seen_at'])) ?></small></span>
            <button type="submit" class="lh-btn-quiet"><?= t('লগআউট', 'Sign out') ?></button>
        </form>
        <?php endforeach; ?>
    </div>
    <p class="lh-auth-alt"><a href="<?= APP_URL ?>/login.php?restart=1"><?= t('অন্য অ্যাকাউন্টে লগইন', 'Use a different account') ?></a></p>
<?php endif; ?>
</div>
<?php auth_close(); ?>
