<?php
// Sign-up and login are one flow now (email code) — see login.php.
define('B_ACADEMY', true);
require_once __DIR__ . '/config/config.php';
header('Location: ' . APP_URL . '/login.php' . (isset($_GET['next']) ? '?next=' . urlencode((string)$_GET['next']) : ''), true, 302);
exit;
