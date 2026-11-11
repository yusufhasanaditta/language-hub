<?php
$_SERVER['HTTP_HOST'] = 'localhost';
define('B_ACADEMY', true);
require 'config/config.php';
require 'config/database.php';
try {
    db_exec('ALTER TABLE courses ADD routine_pdf VARCHAR(500) NULL AFTER thumbnail');
    echo 'Done';
} catch (Exception $e) {
    echo $e->getMessage();
}
