<?php
// Template — copy to config/secrets.php on each server and fill in real values.
defined('B_ACADEMY') or die('Direct access forbidden.');

define('PROD_DB_NAME', '');
define('PROD_DB_USER', '');
define('PROD_DB_PASS', '');

// P-256 VAPID key pair (base64url). Changing it forces devices to re-subscribe.
define('VAPID_PUBLIC_KEY',  '');
define('VAPID_PRIVATE_KEY', '');

// Email for login codes (free: Gmail + App Password —
// Google Account → Security → 2-Step Verification → App passwords).
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 465);
define('SMTP_USER', '');   // e.g. languagehub.bd@gmail.com
define('SMTP_PASS', '');   // the 16-character App Password
define('SMTP_FROM_NAME', 'Language Hub');
