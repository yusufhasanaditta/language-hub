<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
define('B_ACADEMY', true);

require 'config/config.php';
require 'config/database.php';

echo "<h1>Database Update Script</h1>";

try {
    $db = Database::getInstance()->pdo;
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Add classroom_id to exams table if it doesn't exist
    try {
        $db->query("SELECT classroom_id FROM exams LIMIT 1");
        echo "Column 'classroom_id' already exists in 'exams' table.<br>";
    } catch (PDOException $e) {
        $db->exec("ALTER TABLE exams ADD COLUMN classroom_id INT(10) UNSIGNED DEFAULT NULL AFTER course_id");
        echo "<span style='color:green;'>SUCCESS: Added 'classroom_id' to 'exams' table.</span><br>";
    }

    // Add classroom_id to cq_exams table if it doesn't exist
    try {
        $db->query("SELECT classroom_id FROM cq_exams LIMIT 1");
        echo "Column 'classroom_id' already exists in 'cq_exams' table.<br>";
    } catch (PDOException $e) {
        $db->exec("ALTER TABLE cq_exams ADD COLUMN classroom_id INT(10) UNSIGNED DEFAULT NULL AFTER course_id");
        echo "<span style='color:green;'>SUCCESS: Added 'classroom_id' to 'cq_exams' table.</span><br>";
    }

    echo "<h3>Update Complete! You can now access the classroom page.</h3>";
    echo "<b>Security Warning:</b> Please delete this file (db_update.php) from your live server after running it!";

} catch (Throwable $e) {
    echo "<h3 style='color:red;'>Error updating database: " . $e->getMessage() . "</h3>";
}
