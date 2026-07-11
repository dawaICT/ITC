<?php
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/applicant_program_helpers.php';

echo "=== APPLICANT QUERY SCHEMA SWEEP ===\n\n";

foreach (['online_applicants', 'processed_applicants', 'programs', 'staff', 'students'] as $t) {
    echo "Table {$t}: ";
    $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($t) . "'");
    echo ($r && $r->num_rows > 0) ? "EXISTS\n" : "MISSING\n";
}

echo "\n--- programs columns ---\n";
$r = $db->query('DESCRIBE programs');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . "\n";
}

echo "\n--- processed_applicants columns ---\n";
$r = $db->query('DESCRIBE processed_applicants');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . "\n";
}

$progJoin = wuc_applicant_program_join_sql();

try {
    $db->query("SELECT oa.id, {$progJoin['select']} FROM online_applicants oa {$progJoin['join']} WHERE oa.status = 'pending' LIMIT 1");
    echo "\n[OK] handleGetApplicants query\n";
} catch (Throwable $e) {
    echo "\n[FAIL] handleGetApplicants: " . $e->getMessage() . "\n";
}

try {
    $db->query("SELECT program_code, program_name FROM programs WHERE COALESCE(is_active, 1) = 1 LIMIT 1");
    echo "[OK] online_services program dropdown query\n";
} catch (Throwable $e) {
    echo "[FAIL] online_services program dropdown: " . $e->getMessage() . "\n";
}

try {
    $sql = "SELECT COALESCE(p_code.program_name, p_name.program_name, oa.program) AS program_name
            FROM online_applicants oa
            LEFT JOIN programs p_code ON oa.program = p_code.program_code
            LEFT JOIN programs p_name ON oa.program = p_name.program_name
            WHERE oa.status = 'pending' LIMIT 1";
    $db->query($sql);
    echo "[OK] admin/applicants JOIN query\n";
} catch (Throwable $e) {
    echo "[FAIL] admin/applicants JOIN: " . $e->getMessage() . "\n";
}

try {
    $db->query("INSERT INTO processed_applicants 
        (applicant_id, title, Fname, Lname, sex, dob, email, mobile, country, nrc_pass, 
         h_addre, p_addre, sponsor, next_kin, next_kin_mobile, relat, program, program_code, mode, 
         intake, year, results, nrc_file, deposit_slip, dte_adm, status, processed_at, processed_by)
        SELECT 0, '', '', '', 'M', NULL, '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', NOW(), 'pending', NOW(), ''
        FROM DUAL WHERE 1=0");
    echo "[OK] processed_applicants INSERT column list\n";
} catch (Throwable $e) {
    echo "[FAIL] processed_applicants INSERT: " . $e->getMessage() . "\n";
}

try {
    $paProg = wuc_processed_applicant_program_join_sql();
    $db->query("SELECT pa.id, {$paProg['select']} FROM processed_applicants pa {$paProg['join']} LIMIT 1");
    echo "[OK] processedApp.php list query\n";
} catch (Throwable $e) {
    echo "[FAIL] processedApp.php: " . $e->getMessage() . "\n";
}

try {
    $paProg = wuc_processed_applicant_program_join_sql();
    $db->query("SELECT pa.id, {$paProg['select']} FROM processed_applicants pa {$paProg['join']} LIMIT 1");
    echo "[OK] processedApp.php list query\n";
} catch (Throwable $e) {
    echo "[FAIL] processedApp.php: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";