<?php
declare(strict_types=1);

/**
 * Read-only live-schema audit used by the exhibition hardening workflow.
 *
 * Usage:
 *   php scripts/exhibition_schema_audit.php
 *   php scripts/exhibition_schema_audit.php --json
 */

require_once dirname(__DIR__) . '/db/connect.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$json = in_array('--json', $argv ?? [], true);
$database = (string) $db->query('SELECT DATABASE()')->fetch_row()[0];

/** @return array<int, array<string, mixed>> */
function audit_rows(mysqli $db, string $sql): array
{
    $result = $db->query($sql);
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();
    return $rows;
}

/** @return array<int, string> */
function audit_table_names(mysqli $db): array
{
    $names = [];
    foreach (audit_rows($db, 'SHOW TABLES') as $row) {
        $names[] = (string) reset($row);
    }
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}

/** @return array<int, array<string, mixed>> */
function audit_columns(mysqli $db, string $table): array
{
    $stmt = $db->prepare(
        'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** @return array<string, mixed> */
function audit_check(mysqli $db, string $label, string $sql): array
{
    try {
        $row = $db->query($sql)->fetch_assoc();
        return ['label' => $label, 'ok' => true, 'result' => $row ?: []];
    } catch (Throwable $e) {
        return ['label' => $label, 'ok' => false, 'error' => $e->getMessage()];
    }
}

$tables = audit_table_names($db);
$tableSet = array_fill_keys($tables, true);
$focusTables = [
    'access_right', 'academic_years', 'online_applicants', 'processed_applicants',
    'students', 'student_login', 'student_program', 'staff', 'user_credentials',
    'positions', 'staff_positions', 'programs', 'program_courses', 'courses',
    'course_registration', 'course_lecturer', 'semester_registration',
    'attendance_logs', 'el_attendance', 'assessments', 'semester_assessment',
    'payments', 'invoices', 'fee_structure', 'portal_alerts', 'notifications',
    'audit_logs', 'ai_conversations', 'ai_messages', 'ai_usage_logs', 'ai_skill_taxonomy',
    'ai_recommendations', 'employer_internships', 'employer_profiles',
    'alumni_certificates', 'alumni_employment_tracking',
];

$focus = [];
foreach ($focusTables as $table) {
    $focus[$table] = [
        'exists' => isset($tableSet[$table]),
        'columns' => isset($tableSet[$table]) ? audit_columns($db, $table) : [],
    ];
}

$foreignKeys = audit_rows(
    $db,
    "SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
     FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
     ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION"
);

$indexes = audit_rows(
    $db,
    "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS COLUMNS
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
     GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
     ORDER BY TABLE_NAME, INDEX_NAME"
);

$checks = [];
$safeChecks = [
    ['students_without_login', "SELECT COUNT(*) AS count FROM students s LEFT JOIN student_login l ON l.Sid=s.SID WHERE l.Sid IS NULL"],
    ['logins_without_student', "SELECT COUNT(*) AS count FROM student_login l LEFT JOIN students s ON s.SID=l.Sid WHERE s.SID IS NULL"],
    ['students_without_any_enrolment', "SELECT COUNT(*) AS count FROM students s LEFT JOIN student_program sp ON sp.Sid=s.SID LEFT JOIN short_course_enrollments sce ON sce.student_id=s.SID WHERE sp.Sid IS NULL AND sce.student_id IS NULL"],
    ['program_links_without_student', "SELECT COUNT(*) AS count FROM student_program sp LEFT JOIN students s ON s.SID=sp.Sid WHERE s.SID IS NULL"],
    ['program_links_without_program', "SELECT COUNT(*) AS count FROM student_program sp LEFT JOIN programs p ON p.program_code=sp.program_code WHERE p.program_code IS NULL"],
    ['staff_without_credentials', "SELECT COUNT(*) AS count FROM staff s LEFT JOIN user_credentials u ON u.staff_id=s.staff_id WHERE u.staff_id IS NULL"],
    ['credentials_without_staff', "SELECT COUNT(*) AS count FROM user_credentials u LEFT JOIN staff s ON s.staff_id=u.staff_id WHERE s.staff_id IS NULL"],
    ['staff_without_position', "SELECT COUNT(*) AS count FROM staff s LEFT JOIN staff_positions sp ON sp.staff_id=s.staff_id WHERE sp.staff_id IS NULL"],
    ['duplicate_student_program_links', "SELECT COUNT(*) AS count FROM (SELECT Sid, program_code, COUNT(*) c FROM student_program GROUP BY Sid, program_code HAVING COUNT(*)>1) d"],
    ['duplicate_course_registrations', "SELECT COUNT(*) AS count FROM (SELECT Sid, course_code, Year, academic_year, COUNT(*) c FROM course_registration GROUP BY Sid, course_code, Year, academic_year HAVING COUNT(*)>1) d"],
    ['course_registrations_without_student', "SELECT COUNT(*) AS count FROM course_registration cr LEFT JOIN students s ON s.SID=cr.Sid WHERE s.SID IS NULL"],
    ['legacy_course_rows_without_student', "SELECT COUNT(*) AS count FROM student_courses sc LEFT JOIN students s ON s.SID=sc.student_id WHERE s.SID IS NULL"],
];
foreach ($safeChecks as [$label, $sql]) {
    $checks[] = audit_check($db, $label, $sql);
}

$report = [
    'generated_at' => date(DATE_ATOM),
    'database' => $database,
    'server_version' => $db->server_info,
    'table_count' => count($tables),
    'tables' => $tables,
    'focus_tables' => $focus,
    'foreign_key_count' => count($foreignKeys),
    'foreign_keys' => $foreignKeys,
    'index_count' => count($indexes),
    'integrity_checks' => $checks,
    'known_phantom_tables_present' => array_values(array_intersect(
        ['student_payments', 'student_courses', 'announcement'],
        $tables
    )),
];

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

echo "WUCPortal exhibition live-schema audit\n";
echo "Database: {$database}; MariaDB/MySQL: {$db->server_info}; tables: " . count($tables) . "\n\n";
echo "Focus tables\n";
foreach ($focus as $table => $details) {
    if (!$details['exists']) {
        echo "  MISSING  {$table}\n";
        continue;
    }
    $columnNames = array_map(static fn(array $column): string => (string) $column['COLUMN_NAME'], $details['columns']);
    echo '  OK       ' . $table . ': ' . implode(', ', $columnNames) . "\n";
}

echo "\nReferential/integrity checks\n";
foreach ($checks as $check) {
    if (!$check['ok']) {
        echo '  ERROR    ' . $check['label'] . ': ' . $check['error'] . "\n";
        continue;
    }
    echo '  ' . str_pad((string) ($check['result']['count'] ?? 'OK'), 8) . ' ' . $check['label'] . "\n";
}

echo "\nForeign keys: " . count($foreignKeys) . '; indexes: ' . count($indexes) . "\n";
echo 'Known phantom tables unexpectedly present: ' . ($report['known_phantom_tables_present'] ? implode(', ', $report['known_phantom_tables_present']) : 'none') . "\n";
