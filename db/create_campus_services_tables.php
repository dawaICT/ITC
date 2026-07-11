<?php
/**
 * Database Migration - Digital Campus Services and Clearance Tables
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');

require_once __DIR__ . '/../db/connect.php';

echo "=== Running Campus Services Migration ===\n";

// 1. student_campus_requests Table
$sqlRequests = "CREATE TABLE IF NOT EXISTS student_campus_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20) NOT NULL,
    service_type ENUM('counselling', 'medical', 'hostel', 'transport', 'general') NOT NULL,
    subject VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('Pending', 'In Progress', 'Scheduled', 'Resolved', 'Rejected') DEFAULT 'Pending',
    appointment_date DATETIME NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_student (student_id),
    INDEX idx_service (service_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($db->query($sqlRequests)) {
    echo "[OK] Table 'student_campus_requests' created or exists.\n";
} else {
    echo "[ERROR] Failed to create table 'student_campus_requests': " . $db->error . "\n";
    exit(1);
}

// 2. student_clearance Table
$sqlClearance = "CREATE TABLE IF NOT EXISTS student_clearance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20) NOT NULL,
    finance_cleared TINYINT(1) DEFAULT 0,
    library_cleared TINYINT(1) DEFAULT 0,
    academic_cleared TINYINT(1) DEFAULT 0,
    admin_cleared TINYINT(1) DEFAULT 0,
    admin_notes TEXT NULL,
    graduation_status ENUM('Not Eligible', 'Eligible', 'Applied', 'Approved', 'Graduated') DEFAULT 'Not Eligible',
    graduation_year INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_student (student_id),
    INDEX idx_graduation (graduation_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($db->query($sqlClearance)) {
    echo "[OK] Table 'student_clearance' created or exists.\n";
} else {
    echo "[ERROR] Failed to create table 'student_clearance': " . $db->error . "\n";
    exit(1);
}

echo "=== Migration Completed Successfully! ===\n";
?>
