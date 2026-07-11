<?php
declare(strict_types=1);
/**
 * Idempotent installer for transport session attendance ("how many reported").
 * Run: C:\xampp\php\php.exe transport\scripts\install_attendance.php
 */
define('IS_SCRIPT', true);
require_once __DIR__ . '/../../db/connect.php';

echo "Transport attendance installer\n==============================\n";
if ($db->query("
    CREATE TABLE IF NOT EXISTS transport_session_attendance (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        enrollment_id INT NOT NULL,
        trainee_id INT NULL,
        status ENUM('present','absent','late','excused') NOT NULL DEFAULT 'present',
        marked_by VARCHAR(80) NULL,
        marked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_session_enrollment (session_id, enrollment_id),
        KEY idx_session (session_id),
        KEY idx_enrollment (enrollment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
")) {
    echo "  OK   transport_session_attendance\n";
} else {
    echo "  FAIL: " . $db->error . "\n";
}
echo "\nDone.\n";
