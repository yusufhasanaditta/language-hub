<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
send_security_headers();

// Only accessible during an active impersonation session
if (empty($_SESSION['original_admin'])) {
    redirect(APP_URL . '/admin/');
}

// Update ended_at in audit log
$adminId  = (int)$_SESSION['original_admin']['id'];
$targetId = (int)($_SESSION['user_id'] ?? 0);
if ($adminId && $targetId) {
    db_exec(
        "UPDATE admin_impersonations SET ended_at=NOW()
         WHERE admin_id=? AND target_id=? AND ended_at IS NULL
         ORDER BY created_at DESC LIMIT 1",
        [$adminId, $targetId]
    );
}

// Restore admin session
$_SESSION['user_id']   = $_SESSION['original_admin']['id'];
$_SESSION['user_role'] = $_SESSION['original_admin']['role'];
unset($_SESSION['original_admin']);

flash('success', 'You are back in your admin session.');
redirect(APP_URL . '/admin/teachers.php');
