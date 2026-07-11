<?php
/**
 * Migration: create short_course_assessment for short-course CA marks.
 *
 * Short courses have no semester/term/year, so they cannot use
 * semester_assessment (whose natural key includes semester + Year). This table
 * keys CA on (short_course_id, student_id) instead.
 *
 * Idempotent. Run from CLI:  php migrate_short_course_assessment.php
 */

require 'db/connect.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

echo "Short-course CA table migration\n" . str_repeat('=', 60) . "\n\n";

$sql = "CREATE TABLE IF NOT EXISTS short_course_assessment (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    short_course_id INT NOT NULL,
    course_code VARCHAR(50) NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    A1 DECIMAL(6,2) NULL DEFAULT NULL,
    A2 DECIMAL(6,2) NULL DEFAULT NULL,
    A3 DECIMAL(6,2) NULL DEFAULT NULL,
    T1 DECIMAL(6,2) NULL DEFAULT NULL,
    T2 DECIMAL(6,2) NULL DEFAULT NULL,
    Total_CA DECIMAL(6,2) NULL DEFAULT NULL,
    posted_by VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sc_assessment (short_course_id, student_id),
    KEY idx_sc_course_code (course_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($db->query($sql)) {
    echo "OK: short_course_assessment table is present.\n";
} else {
    error_log('migrate_short_course_assessment failed: ' . $db->error);
    echo "ERROR: " . $db->error . "\n";
}

$db->close();
