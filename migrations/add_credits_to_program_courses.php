<?php
/**
 * Migration: add the missing `credits` column to `program_courses`.
 *
 * The canonical schema (admin/db/setup_programs.sql) defines
 * `credits INT DEFAULT 3` on program_courses, and the admin course-assignment
 * code (admin/ajax/manage_semester_courses.php, admin/modern_semester_courses.php)
 * INSERTs/UPDATEs that column. The live table drifted and never had it, so every
 * add/update/bulk-edit failed with "Unknown column 'credits'" and new program
 * courses never persisted (and so never showed on the Program Courses page).
 *
 * Idempotent: only adds the column if it is missing.
 */

require __DIR__ . '/../db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$check = $db->prepare(
    "SELECT COUNT(*) AS c
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'program_courses'
        AND COLUMN_NAME = 'credits'"
);
$check->execute();
$exists = (int) $check->get_result()->fetch_assoc()['c'] > 0;
$check->close();

if ($exists) {
    echo "`credits` already exists on program_courses, nothing to do.\n";
} else {
    $db->query("ALTER TABLE `program_courses` ADD COLUMN `credits` INT NOT NULL DEFAULT 3 AFTER `semester`");
    echo "Added `credits` INT NOT NULL DEFAULT 3 to program_courses.\n";
}
