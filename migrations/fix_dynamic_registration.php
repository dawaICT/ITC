<?php
/**
 * Make academic registration genuinely period-aware while keeping the legacy
 * semester_registration table compatible with old callers.
 *
 * Short courses remain in short_courses/short_course_enrollments, where their
 * duration is stored as a value plus unit (days, weeks or months).
 */

require __DIR__ . '/../db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function dynamic_reg_column_exists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function dynamic_reg_index_exists(mysqli $db, string $table, string $index): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

if (!dynamic_reg_column_exists($db, 'programs', 'period_mode')) {
    $db->query("ALTER TABLE programs ADD period_mode ENUM('semester','term') NOT NULL DEFAULT 'semester' AFTER study_mode");
    $db->query("UPDATE programs SET period_mode = 'term' WHERE LOWER(program_type) IN ('certificate','craft certificate','trade certificate','diploma') OR program_code LIKE 'ITC-%'");
    echo "Added and seeded programs.period_mode.\n";
}

if (!dynamic_reg_column_exists($db, 'academic_periods', 'period_type')) {
    $db->query("ALTER TABLE academic_periods ADD period_type ENUM('semester','term') NOT NULL DEFAULT 'semester' AFTER academic_year");
    echo "Added academic_periods.period_type.\n";
}

if (!dynamic_reg_column_exists($db, 'semester_registration', 'period_type')) {
    $db->query("ALTER TABLE semester_registration ADD period_type ENUM('semester','term') NOT NULL DEFAULT 'semester' AFTER semester");
    echo "Added semester_registration.period_type.\n";
}

// The live table carried a required numeric legacy SID alongside student_id.
// Make it string-compatible and optional so the canonical student_id writer can
// insert modern IDs, while old pages that still populate SID continue to work.
$db->query("ALTER TABLE semester_registration MODIFY SID VARCHAR(50) NULL");

if (dynamic_reg_index_exists($db, 'semester_registration', 'semester_reg_unique')) {
    $db->query('ALTER TABLE semester_registration DROP INDEX semester_reg_unique');
}
if (!dynamic_reg_index_exists($db, 'semester_registration', 'uq_registration_period')) {
    $db->query('ALTER TABLE semester_registration ADD UNIQUE KEY uq_registration_period (SID, program_code, period_type, semester, academic_year)');
}
if (!dynamic_reg_index_exists($db, 'semester_registration', 'uq_registration_period_student')) {
    $db->query('ALTER TABLE semester_registration ADD UNIQUE KEY uq_registration_period_student (student_id, program_code, period_type, semester, academic_year)');
}

if (dynamic_reg_index_exists($db, 'academic_periods', 'unique_period')) {
    $db->query('ALTER TABLE academic_periods DROP INDEX unique_period');
}
if (!dynamic_reg_index_exists($db, 'academic_periods', 'uq_academic_period_type')) {
    $db->query('ALTER TABLE academic_periods ADD UNIQUE KEY uq_academic_period_type (academic_year, period_type, semester_term)');
}

echo "Dynamic registration schema is ready.\n";
