<?php
/**
 * Migration: Create short_course_enrollments table
 * Links students to short courses with enrollment tracking.
 */
require_once __DIR__ . '/../db/connect.php';

echo "Running short_course_enrollments migration...\n";

// Create enrollments table
$sql = "CREATE TABLE IF NOT EXISTS short_course_enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    short_course_id INT NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    enrollment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completion_date DATE NULL,
    status ENUM('enrolled','completed','withdrawn','expired') DEFAULT 'enrolled',
    certificate_issued TINYINT(1) DEFAULT 0,
    enrolled_by VARCHAR(50) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_course_student (short_course_id, student_id),
    KEY idx_student (student_id),
    KEY idx_status (status),
    CONSTRAINT fk_sce_course FOREIGN KEY (short_course_id) REFERENCES short_courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_sce_student FOREIGN KEY (student_id) REFERENCES students(SID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

if ($db->query($sql)) {
    echo "✓ short_course_enrollments table created/verified.\n";
} else {
    echo "✗ Error: " . $db->error . "\n";
}

// Add Email column to student_login if missing (needed for short course students)
$check = $db->query("SHOW COLUMNS FROM student_login LIKE 'Email'");
if ($check && $check->num_rows === 0) {
    $db->query("ALTER TABLE student_login ADD COLUMN Email VARCHAR(255) NULL AFTER Password");
    echo "✓ Added Email column to student_login.\n";
}

echo "Migration complete.\n";
