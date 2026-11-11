<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
send_security_headers();

// CSRF check for logout (prevents CSRF-based logout)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (($_GET['token'] ?? '') !== csrf_token()) {
        redirect(APP_URL . '/');
    }
}

logout_user();
flash('success', 'You have been logged out successfully.');
redirect(APP_URL . '/login.php');
