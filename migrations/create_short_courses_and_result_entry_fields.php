<?php
/**
 * Migration: short-course result-entry support.
 *
 * Creates the missing short_courses table used by admin/short_courses.php and
 * adds optional result metadata fields without changing existing result rows.
 */
require_once __DIR__ . '/../db/connect.php';

echo "Running short-course result-entry migration...\n";

$sql = "CREATE TABLE IF NOT EXISTS short_courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(50) NOT NULL,
    course_name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    duration_value INT NOT NULL DEFAULT 3,
    duration_unit ENUM('days','weeks','months') NOT NULL DEFAULT 'weeks',
    fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    max_capacity INT NOT NULL DEFAULT 30,
    prerequisites TEXT NULL,
    delivery_mode ENUM('full-time','part-time','online','blended') NOT NULL DEFAULT 'full-time',
    status ENUM('active','inactive','upcoming') NOT NULL DEFAULT 'active',
    start_date DATE NULL,
    end_date DATE NULL,
    created_by VARCHAR(50) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_short_courses_code (course_code),
    KEY idx_short_courses_status (status),
    KEY idx_short_courses_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

if ($db->query($sql)) {
    echo "short_courses table created/verified.\n";
} else {
    echo "short_courses error: " . $db->error . "\n";
}

$sql = "CREATE TABLE IF NOT EXISTS short_course_enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    short_course_id INT NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    enrollment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completion_date DATE NULL,
    status ENUM('enrolled','active','completed','withdrawn','expired') DEFAULT 'enrolled',
    certificate_issued TINYINT(1) DEFAULT 0,
    enrolled_by VARCHAR(50) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_course_student (short_course_id, student_id),
    KEY idx_student (student_id),
    KEY idx_short_course (short_course_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

if ($db->query($sql)) {
    echo "short_course_enrollments table created/verified.\n";
} else {
    echo "short_course_enrollments error: " . $db->error . "\n";
}

$columns = [
    'programme_type' => "ALTER TABLE exams ADD COLUMN programme_type ENUM('yearly','short_course') NOT NULL DEFAULT 'yearly' AFTER Year",
    'assessment_date' => "ALTER TABLE exams ADD COLUMN assessment_date DATE NULL AFTER programme_type",
    'assessment_id' => "ALTER TABLE exams ADD COLUMN assessment_id BIGINT UNSIGNED NULL AFTER assessment_date",
];

foreach ($columns as $column => $alter) {
    $check = $db->query("SHOW COLUMNS FROM exams LIKE '" . $db->real_escape_string($column) . "'");
    if ($check && $check->num_rows === 0) {
        if ($db->query($alter)) {
            echo "Added exams.{$column}.\n";
        } else {
            echo "Could not add exams.{$column}: " . $db->error . "\n";
        }
    }
    if ($check) {
        $check->free();
    }
}

echo "Migration complete.\n";
