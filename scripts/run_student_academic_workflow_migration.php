<?php
/**
 * Apply student academic workflow schema extensions.
 * Usage: C:\xampp\php\php.exe scripts/run_student_academic_workflow_migration.php
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    echo "CLI only.\n";
    exit(1);
}

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');
require_once __DIR__ . '/../db/connect.php';

function saw_column_exists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function saw_add_column(mysqli $db, string $table, string $ddl): void
{
    if (@$db->query($ddl)) {
        echo "OK: {$ddl}\n";
    } else {
        echo "SKIP/FAIL: {$ddl} — " . $db->error . "\n";
    }
}

echo "Student academic workflow migration\n";

$periodCols = [
    "ALTER TABLE academic_periods ADD COLUMN registration_open TINYINT(1) NOT NULL DEFAULT 1 AFTER status",
    "ALTER TABLE academic_periods ADD COLUMN docket_open TINYINT(1) NOT NULL DEFAULT 0 AFTER registration_open",
    "ALTER TABLE academic_periods ADD COLUMN exam_slip_open TINYINT(1) NOT NULL DEFAULT 0 AFTER docket_open",
];
foreach ($periodCols as $ddl) {
    if (!saw_column_exists($db, 'academic_periods', explode(' ', str_replace('ADD COLUMN ', '', $ddl))[0])) {
        saw_add_column($db, 'academic_periods', $ddl);
    } else {
        echo "EXISTS: academic_periods column already present\n";
    }
}

$semCols = [
    'academic_period_id' => "ALTER TABLE semester_registration ADD COLUMN academic_period_id INT NULL AFTER academic_year",
    'registration_status' => "ALTER TABLE semester_registration ADD COLUMN registration_status ENUM('pending','registered','blocked') NOT NULL DEFAULT 'registered' AFTER registration_date",
    'fee_status' => "ALTER TABLE semester_registration ADD COLUMN fee_status ENUM('eligible','not_eligible','sponsored','unknown') NOT NULL DEFAULT 'unknown' AFTER registration_status",
];
foreach ($semCols as $col => $ddl) {
    if (!saw_column_exists($db, 'semester_registration', $col)) {
        saw_add_column($db, 'semester_registration', $ddl);
    }
}

$db->query("UPDATE academic_periods SET period_number = CAST(NULLIF(semester_term, '') AS UNSIGNED) WHERE period_number IS NULL AND semester_term REGEXP '^[0-9]+$'");
$db->query("UPDATE academic_periods SET period_name = CONCAT(CASE WHEN period_type = 'semester' THEN 'Semester ' ELSE 'Term ' END, COALESCE(period_number, semester_term)) WHERE (period_name IS NULL OR period_name = '') AND semester_term IS NOT NULL");
$db->query("UPDATE academic_periods SET registration_open = 1 WHERE is_current = 1");

// Unique index — prefer student_id, fall back to SID
$sidCol = saw_column_exists($db, 'semester_registration', 'student_id') ? 'student_id' : (saw_column_exists($db, 'semester_registration', 'SID') ? 'SID' : null);
if ($sidCol !== null && saw_column_exists($db, 'semester_registration', 'program_code')
    && saw_column_exists($db, 'semester_registration', 'academic_year')
    && saw_column_exists($db, 'semester_registration', 'semester')
    && saw_column_exists($db, 'semester_registration', 'period_type')) {
    $idxCheck = $db->query("SHOW INDEX FROM semester_registration WHERE Key_name = 'uq_student_period_registration'");
    if (!$idxCheck || $idxCheck->num_rows === 0) {
        $sql = "ALTER TABLE semester_registration ADD UNIQUE KEY uq_student_period_registration (`{$sidCol}`, program_code, academic_year, semester, period_type)";
        if (@$db->query($sql)) {
            echo "OK: unique index uq_student_period_registration\n";
        } else {
            echo "WARN: unique index — " . $db->error . " (duplicates may exist; clean data manually)\n";
        }
    }
    if ($idxCheck) {
        $idxCheck->free();
    }
}

echo "Done.\n";
