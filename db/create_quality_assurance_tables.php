<?php
/**
 * Database Migration - Phase 14 Quality Assurance Evaluations Tables
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');

require_once __DIR__ . '/connect.php';

echo "Running Quality Assurance Tables Migration...\n";

// 1. Create course_evaluations table
$sql1 = "CREATE TABLE IF NOT EXISTS course_evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(50) NOT NULL,
    lecturer_id VARCHAR(50) NOT NULL,
    rating_lecturer INT NOT NULL CHECK (rating_lecturer BETWEEN 1 AND 5),
    rating_content INT NOT NULL CHECK (rating_content BETWEEN 1 AND 5),
    rating_facilities INT NOT NULL CHECK (rating_facilities BETWEEN 1 AND 5),
    rating_services INT NOT NULL CHECK (rating_services BETWEEN 1 AND 5),
    comments TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($db->query($sql1)) {
    echo "[SUCCESS] Table 'course_evaluations' created.\n";
} else {
    echo "[ERROR] Failed to create 'course_evaluations': " . $db->error . "\n";
    exit(1);
}

// 2. Create student_evaluated_courses mapping registry (for single-submission enforcement)
$sql2 = "CREATE TABLE IF NOT EXISTS student_evaluated_courses (
    student_id VARCHAR(50) NOT NULL,
    course_code VARCHAR(50) NOT NULL,
    evaluated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id, course_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($db->query($sql2)) {
    echo "[SUCCESS] Table 'student_evaluated_courses' created.\n";
} else {
    echo "[ERROR] Failed to create 'student_evaluated_courses': " . $db->error . "\n";
    exit(1);
}

echo "Migration completed successfully!\n";
?>
