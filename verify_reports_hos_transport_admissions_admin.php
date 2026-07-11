<?php
/**
 * Read-only verifier for HOS, Transport, Admissions, and Admin report coverage.
 *
 * Usage:
 *   E:\xampp\php\php.exe verify_reports_hos_transport_admissions_admin.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This verifier must be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/includes/hos_section_helpers.php';
require_once __DIR__ . '/admin/includes/admitted_report_helpers.php';

$failures = [];

function verify_pass(string $message): void
{
    echo "[PASS] {$message}\n";
}

function verify_fail(array &$failures, string $message): void
{
    $failures[] = $message;
    echo "[FAIL] {$message}\n";
}

function verify_assert(array &$failures, bool $condition, string $message): void
{
    if ($condition) {
        verify_pass($message);
        return;
    }
    verify_fail($failures, $message);
}

function verify_file_contains(array &$failures, string $file, string $needle, string $message): void
{
    $path = __DIR__ . '/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
    if (!is_file($path)) {
        verify_fail($failures, "{$message} ({$file} missing)");
        return;
    }
    $contents = file_get_contents($path);
    verify_assert($failures, $contents !== false && strpos($contents, $needle) !== false, $message);
}

function verify_table_exists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function verify_columns(mysqli $db, string $table): array
{
    $columns = [];
    if (!verify_table_exists($db, $table)) {
        return $columns;
    }
    $result = $db->query("SHOW COLUMNS FROM `{$table}`");
    while ($result && ($row = $result->fetch_assoc())) {
        $columns[strtolower((string)$row['Field'])] = (string)$row['Field'];
    }
    if ($result) {
        $result->free();
    }
    return $columns;
}

function verify_first_col(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        $key = strtolower($candidate);
        if (isset($columns[$key])) {
            return $columns[$key];
        }
    }
    return null;
}

function verify_prepare(mysqli $db, array &$failures, string $label, string $sql): void
{
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->close();
        verify_pass("SQL prepares: {$label}");
        return;
    }
    verify_fail($failures, "SQL prepare failed for {$label}: " . $db->error);
}

if (!isset($db) || !($db instanceof mysqli)) {
    verify_fail($failures, 'Database connection is not available.');
    exit(1);
}

echo "Report coverage verifier\n";
echo "Database: " . ($db->query('SELECT DATABASE() AS db_name')->fetch_assoc()['db_name'] ?? 'unknown') . "\n\n";

// Navigation and page coverage.
verify_file_contains($failures, 'hod/includes/nav.php', '/wucportal/transport/reports.php', 'HOS Transport sidebar links to Transport Reports');
verify_file_contains($failures, 'hod/includes/nav.php', "array('href' => 'reports.php'", 'HOS ICT/Engineering sidebar links to Department Reports');
verify_file_contains($failures, 'hod/index.php', '../transport/reports.php', 'HOS Transport dashboard cards link to Transport Reports');
verify_file_contains($failures, 'transport/includes/nav.php', 'reports.php', 'Transport module sidebar includes Reports & Summaries');
verify_file_contains($failures, 'admissions/includes/nav.php', "array('href' => 'reports.php'", 'Admissions sidebar includes Reports');
verify_file_contains($failures, 'admin/includes/nav.php', "\$transportBase . 'reports.php'", 'Admin sidebar includes Transport Reports');
verify_file_contains($failures, 'admin/includes/nav.php', 'admittedStud_report.php', 'Admin sidebar includes Admitted Students report');
verify_file_contains($failures, 'admin/includes/nav.php', 'reportStudy_mode.php', 'Admin sidebar includes Study Mode report');
verify_file_contains($failures, 'admin/includes/nav.php', 'report_year_intake.php', 'Admin sidebar includes Sponsorship report');
verify_file_contains($failures, 'admin/includes/nav.php', 'student_progression_report.php', 'Admin sidebar includes Progression Alerts report');

// HOS section split.
$sections = [];
if (verify_table_exists($db, 'sections')) {
    $result = $db->query("SELECT section_id, section_name, section_type, department_id FROM sections WHERE status = 'active'");
    while ($result && ($row = $result->fetch_assoc())) {
        $sections[(string)$row['section_id']] = $row;
    }
    if ($result) {
        $result->free();
    }
}
verify_assert($failures, isset($sections['TRANSPORT']) && hos_is_transport_section($sections['TRANSPORT']), 'Active TRANSPORT HOS section resolves as transport');
verify_assert($failures, isset($sections['ENGICT']) && !hos_is_transport_section($sections['ENGICT']) && hos_normalize_section_type((string)$sections['ENGICT']['section_type'], (string)$sections['ENGICT']['section_name'], (string)$sections['ENGICT']['section_id']) === 'academic', 'Active ENGICT HOS section resolves as academic ICT/Engineering');

// Core schema required by the requested reports.
$requiredTables = [
    'students',
    'student_program',
    'programs',
    'sections',
    'transport_programs',
    'transport_campuses',
    'transport_cohorts',
    'transport_enrollments',
    'transport_instructors',
    'transport_vehicles',
    'transport_sessions',
    'transport_preuse_checks',
    'transport_maintenance_logs',
    'transport_fuel_logs',
    'transport_corporate_clients',
    'transport_incident_reports',
];
foreach ($requiredTables as $table) {
    verify_assert($failures, verify_table_exists($db, $table), "Required table exists: {$table}");
}

// Admissions reports must include academic and transport programs in one report.
if (verify_table_exists($db, 'students') && verify_table_exists($db, 'student_program') && verify_table_exists($db, 'programs') && verify_table_exists($db, 'transport_programs')) {
    $admissionsSql = "
        SELECT
            s.SID AS Sid,
            s.Fname,
            s.Lname,
            s.sex,
            COALESCE(p.program_name, CONCAT(tp.program_name, ' (Transport)'), sp.program_code) AS program_name,
            sp.intake,
            sp.mode,
            sp.startYear,
            sp.term,
            sp.status AS enrollment_status
        FROM students s
        INNER JOIN student_program sp ON TRIM(UPPER(s.SID)) = TRIM(UPPER(sp.Sid))
        LEFT JOIN programs p ON TRIM(UPPER(sp.program_code)) = TRIM(UPPER(p.program_code))
        LEFT JOIN transport_programs tp ON TRIM(UPPER(tp.program_code)) = TRIM(UPPER(sp.program_code))
        WHERE LOWER(COALESCE(sp.status, '')) = 'active'
          AND LOWER(COALESCE(s.status, '')) <> 'deleted'
          AND REPLACE(REPLACE(LOWER(COALESCE(sp.mode, '')), '-', ''), ' ', '') = ?
        ORDER BY program_name, s.Lname, s.Fname
        LIMIT 1";
    verify_prepare($db, $failures, 'Admissions active student report with transport programs', $admissionsSql);
}

// Admin admitted-students helper should consider current schema valid.
if (function_exists('admitted_report_missing_schema')) {
    $missingAdminSchema = admitted_report_missing_schema($db);
    verify_assert($failures, empty($missingAdminSchema), 'Admin admitted-students report schema requirements are satisfied' . (!empty($missingAdminSchema) ? ': ' . implode(', ', $missingAdminSchema) : ''));
}

// HOS ICT/Engineering report base query should prepare with current student/program schema.
$studentCols = verify_columns($db, 'students');
$studentProgramCols = verify_columns($db, 'student_program');
$programCols = verify_columns($db, 'programs');
$studentIdCol = verify_first_col($studentCols, ['SID', 'Sid', 'student_id']);
$spSidCol = verify_first_col($studentProgramCols, ['Sid', 'SID', 'student_id']);
$spProgramCol = verify_first_col($studentProgramCols, ['program_code']);
$programCodeCol = verify_first_col($programCols, ['program_code', 'programId', 'program']);
$programNameCol = verify_first_col($programCols, ['program_name', 'name']);

if ($studentIdCol && $spSidCol && $spProgramCol) {
    $programJoin = ($programCodeCol && $programNameCol)
        ? "LEFT JOIN programs p ON p.`{$programCodeCol}` = sp.`{$spProgramCol}`"
        : "";
    $programNameExpr = ($programCodeCol && $programNameCol)
        ? "COALESCE(p.`{$programNameCol}`, sp.`{$spProgramCol}`)"
        : "sp.`{$spProgramCol}`";
    $hodSql = "
        SELECT st.`{$studentIdCol}` AS sid,
               sp.`{$spProgramCol}` AS program_code,
               {$programNameExpr} AS program_name
        FROM students st
        INNER JOIN student_program sp ON sp.`{$spSidCol}` = st.`{$studentIdCol}`
        {$programJoin}
        WHERE 1 = 1
        ORDER BY program_name, sid
        LIMIT 1";
    verify_prepare($db, $failures, 'HOS ICT/Engineering department report base query', $hodSql);
} else {
    verify_fail($failures, 'HOS ICT/Engineering report cannot resolve student/student_program linkage columns.');
}

// Transport report registry dependencies. These mirror transport/reports.php builders.
$transportSql = [
    'transport enrolment summary' => "
        SELECT ca.campus_name, p.program_code, c.cohort_name,
               COUNT(e.id) AS enrolled,
               SUM(e.status = 'completed') AS completed,
               SUM(e.status = 'withdrawn') AS withdrawn,
               COALESCE(SUM(e.fee_amount), 0) AS fee_billed,
               COALESCE(SUM(e.amount_paid), 0) AS amount_paid,
               COALESCE(SUM(e.fee_amount - e.amount_paid), 0) AS outstanding
        FROM transport_enrollments e
        INNER JOIN transport_cohorts c ON c.id = e.cohort_id
        INNER JOIN transport_programs p ON p.id = c.program_id
        INNER JOIN transport_campuses ca ON ca.id = c.campus_id
        WHERE e.enrollment_date BETWEEN ? AND ?
        GROUP BY c.id
        LIMIT 1",
    'transport session delivery' => "
        SELECT ca.campus_name, c.cohort_name, p.program_code, i.full_name AS instructor,
               COUNT(s.id) AS sessions,
               SUM(s.status = 'completed') AS completed,
               SUM(s.status = 'cancelled') AS cancelled,
               COALESCE(SUM(CASE WHEN s.status <> 'cancelled' THEN s.contact_hours ELSE 0 END), 0) AS contact_hours
        FROM transport_sessions s
        INNER JOIN transport_cohorts c ON c.id = s.cohort_id
        INNER JOIN transport_programs p ON p.id = c.program_id
        INNER JOIN transport_campuses ca ON ca.id = c.campus_id
        INNER JOIN transport_instructors i ON i.id = s.instructor_id
        WHERE s.session_date BETWEEN ? AND ?
        GROUP BY c.id, i.id
        LIMIT 1",
    'transport instructor compliance' => "
        SELECT ca.campus_name, i.full_name, i.status, i.license_classes,
               i.rtsa_expiry, i.teveta_expiry,
               CASE
                 WHEN (i.rtsa_expiry IS NOT NULL AND i.rtsa_expiry < CURDATE())
                   OR (i.teveta_expiry IS NOT NULL AND i.teveta_expiry < CURDATE()) THEN 'Expired'
                 WHEN (i.rtsa_expiry IS NOT NULL AND i.rtsa_expiry <= DATE_ADD(CURDATE(), INTERVAL 60 DAY))
                   OR (i.teveta_expiry IS NOT NULL AND i.teveta_expiry <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)) THEN 'Due soon'
                 ELSE 'Valid'
               END AS accreditation
        FROM transport_instructors i
        INNER JOIN transport_campuses ca ON ca.id = i.campus_id
        LIMIT 1",
    'transport fleet status' => "
        SELECT ca.campus_name, v.registration_no, v.vehicle_type, v.status, v.current_mileage,
               v.fitness_expiry, v.insurance_expiry, v.next_service_due
        FROM transport_vehicles v
        INNER JOIN transport_campuses ca ON ca.id = v.campus_id
        LIMIT 1",
    'transport pre-use summary' => "
        SELECT ca.campus_name, v.registration_no,
               COUNT(pc.id) AS checks,
               SUM(pc.overall_status = 'fit') AS fit,
               SUM(pc.overall_status = 'defect_reported') AS defects,
               SUM(pc.overall_status = 'unfit') AS unfit,
               MAX(pc.checklist_date) AS last_check
        FROM transport_preuse_checks pc
        INNER JOIN transport_vehicles v ON v.id = pc.vehicle_id
        INNER JOIN transport_campuses ca ON ca.id = v.campus_id
        WHERE pc.checklist_date BETWEEN ? AND ?
        GROUP BY v.id
        LIMIT 1",
    'transport maintenance and fuel' => "
        SELECT ca.campus_name, v.registration_no,
               COALESCE(m.service_count, 0) AS services,
               COALESCE(f.fuel_events, 0) AS fuel_events,
               COALESCE(f.fuel_added, 0) AS fuel_added,
               COALESCE(f.consumption, 0) AS consumption
        FROM transport_vehicles v
        INNER JOIN transport_campuses ca ON ca.id = v.campus_id
        LEFT JOIN (
            SELECT vehicle_id, COUNT(*) AS service_count
            FROM transport_maintenance_logs
            WHERE service_date BETWEEN ? AND ?
            GROUP BY vehicle_id
        ) m ON m.vehicle_id = v.id
        LEFT JOIN (
            SELECT vehicle_id, COUNT(*) AS fuel_events,
                   SUM(amount_added) AS fuel_added,
                   SUM(consumption) AS consumption
            FROM transport_fuel_logs
            WHERE fuel_date BETWEEN ? AND ?
            GROUP BY vehicle_id
        ) f ON f.vehicle_id = v.id
        HAVING services > 0 OR fuel_events > 0
        LIMIT 1",
    'transport corporate billing' => "
        SELECT cc.client_name, cc.status,
               COALESCE(ce.cnt, 0) AS active_enrollments,
               COALESCE(ce.billed, 0) AS total_billed,
               COALESCE(ce.paid, 0) AS total_paid,
               COALESCE(ce.balance, 0) AS outstanding
        FROM transport_corporate_clients cc
        LEFT JOIN (
            SELECT corporate_client_id,
                   COUNT(*) AS cnt,
                   SUM(fee_amount) AS billed,
                   SUM(amount_paid) AS paid,
                   SUM(fee_amount - amount_paid) AS balance
            FROM transport_enrollments
            WHERE corporate_client_id IS NOT NULL
            GROUP BY corporate_client_id
        ) ce ON ce.corporate_client_id = cc.id
        LIMIT 1",
    'transport incident summary' => "
        SELECT ca.campus_name, v.registration_no, ir.incident_time, ir.location,
               COALESCE(i.full_name, '') AS instructor, ir.status
        FROM transport_incident_reports ir
        INNER JOIN transport_vehicles v ON v.id = ir.vehicle_id
        INNER JOIN transport_campuses ca ON ca.id = v.campus_id
        LEFT JOIN transport_instructors i ON i.id = ir.instructor_id
        WHERE ir.incident_time >= ? AND ir.incident_time < DATE_ADD(?, INTERVAL 1 DAY)
        LIMIT 1",
];

foreach ($transportSql as $label => $sql) {
    verify_prepare($db, $failures, $label, $sql);
}

echo "\n";
if (!empty($failures)) {
    echo "Verification failed with " . count($failures) . " issue(s).\n";
    exit(1);
}

echo "All report coverage checks passed.\n";
exit(0);
