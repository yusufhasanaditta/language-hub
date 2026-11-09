<?php
define('B_ACADEMY', true);
require_once __DIR__ . '/../config/config.php';
$data = json_decode(file_get_contents('php://input'), true);
if ($data) {
    file_put_contents(__DIR__ . '/../error_log_js.txt', json_encode($data) . "\n", FILE_APPEND);
}
echo json_encode(['success' => true]);
