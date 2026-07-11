<?php
/**
 * Database Migration - Phase 7 Competency & Practical Task Tracking
 */

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');

require_once __DIR__ . '/../db/connect.php';

if (!isset($db) || $db->connect_error) {
    die("Database connection failed.\n");
}

echo "=== Competency Tracking Migration Started ===\n";

// 1. Create el_competencies table
$sql1 = "CREATE TABLE IF NOT EXISTS `el_competencies` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `course_code` VARCHAR(64) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX (`course_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

if ($db->query($sql1)) {
    echo "[PASS] Table 'el_competencies' created or already exists.\n";
} else {
    die("[FAIL] Creating table 'el_competencies': " . $db->error . "\n");
}

// 2. Create el_student_competencies table
$sql2 = "CREATE TABLE IF NOT EXISTS `el_student_competencies` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `student_id` VARCHAR(64) NOT NULL,
    `competency_id` INT(11) NOT NULL,
    `status` ENUM('not_started', 'in_progress', 'competent', 'verified') NOT NULL DEFAULT 'not_started',
    `evidence_path` VARCHAR(255) NULL,
    `lecturer_id` VARCHAR(64) NULL,
    `lecturer_verified_at` DATETIME NULL,
    `trainer_id` VARCHAR(64) NULL,
    `trainer_verified_at` DATETIME NULL,
    `industry_supervisor_name` VARCHAR(255) NULL,
    `industry_verified_at` DATETIME NULL,
    `notes` TEXT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `stud_comp_uniq` (`student_id`, `competency_id`),
    INDEX (`student_id`),
    INDEX (`competency_id`),
    FOREIGN KEY (`competency_id`) REFERENCES `el_competencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

if ($db->query($sql2)) {
    echo "[PASS] Table 'el_student_competencies' created or already exists.\n";
} else {
    die("[FAIL] Creating table 'el_student_competencies': " . $db->error . "\n");
}

// 3. Seed some default competencies for our test courses if they are empty
$check = $db->query("SELECT COUNT(*) AS total FROM `el_competencies` WHERE `course_code` = 'BSCS-201'");
$row = $check->fetch_assoc();
if ($row['total'] == 0) {
    $seeds = [
        ['course_code' => 'BSCS-201', 'title' => 'Database Schema Design', 'description' => 'Design a fully normalized 3NF database schema for an e-commerce system, including ERD diagram and DDL SQL script.'],
        ['course_code' => 'BSCS-201', 'title' => 'Prepared Statement Implementation', 'description' => 'Write a PHP script using prepared statements to insert, update, and fetch records safely from a MySQL database.'],
        ['course_code' => 'BSCS-201', 'title' => 'Stored Procedures & Triggers', 'description' => 'Create a stored procedure to calculate student CGPA and a database trigger to log result audits.'],
    ];
    
    foreach ($seeds as $s) {
        $stmt = $db->prepare("INSERT INTO `el_competencies` (`course_code`, `title`, `description`) VALUES (?, ?, ?)");
        $stmt->bind_param('sss', $s['course_code'], $s['title'], $s['description']);
        $stmt->execute();
        $stmt->close();
    }
    echo "[PASS] Seeded default competencies for course BSCS-201.\n";
} else {
    echo "[NOTE] Table 'el_competencies' already has records. Seeding skipped.\n";
}

echo "=== Competency Tracking Migration Completed Successfully ===\n";
?>
