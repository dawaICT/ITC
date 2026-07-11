<?php
/**
 * Migration: Create login_activity table
 * 
 * Tracks all user logins (students and staff) for auditing purposes.
 * Lecturers see their assigned students' activity; Admins see all users.
 * 
 * Run once: php migrations/create_login_activity_table.php
 */

require_once dirname(__DIR__) . '/db/connect.php';

echo "=== Login Activity Table Migration ===\n\n";

$sql = "CREATE TABLE IF NOT EXISTS `login_activity` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(50) NOT NULL COMMENT 'staff_id or SID',
    `user_type` ENUM('student','staff') NOT NULL DEFAULT 'student',
    `user_name` VARCHAR(150) DEFAULT NULL COMMENT 'Full name snapshot at login time',
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(500) DEFAULT NULL,
    `login_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_user_type` (`user_type`),
    INDEX `idx_login_at` (`login_at`),
    INDEX `idx_user_type_login` (`user_type`, `login_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

try {
    if ($db->query($sql)) {
        echo "[OK] login_activity table created (or already exists).\n";
    } else {
        echo "[ERROR] Failed to create table: " . $db->error . "\n";
    }
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
}

// Verify the table
$check = $db->query("DESCRIBE login_activity");
if ($check) {
    echo "\nTable structure:\n";
    while ($row = $check->fetch_assoc()) {
        printf("  %-15s %-30s %s\n", $row['Field'], $row['Type'], $row['Key'] ? "[{$row['Key']}]" : '');
    }
    $check->free();
    echo "\n[DONE] Migration complete.\n";
} else {
    echo "[ERROR] Could not verify table.\n";
}
