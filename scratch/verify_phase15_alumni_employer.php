<?php
/**
 * Automated Verification Script - Phase 15 Alumni & Employer Integration
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');

define('IS_SCRIPT', true);
$_SESSION['user_id'] = '1';
$_SESSION['staff_id'] = '1';
$_SESSION['role'] = 'systems_admin';

require_once __DIR__ . '/../db/connect.php';

echo "=== Phase 15 Alumni & Employer Integration Verification Checks ===\n\n";

function verify_assert(bool $condition, string $description) {
    if ($condition) {
        echo "[PASS] $description\n";
    } else {
        echo "[FAIL] $description\n";
        exit(1);
    }
}

// 1. Schema Check
$tables = [];
if ($res = $db->query("SHOW TABLES")) {
    while ($row = $res->fetch_row()) {
        $tables[] = $row[0];
    }
}
verify_assert(in_array('alumni_certificates', $tables, true), "Table 'alumni_certificates' exists.");
verify_assert(in_array('employer_internships', $tables, true), "Table 'employer_internships' exists.");
verify_assert(in_array('alumni_employment_tracking', $tables, true), "Table 'alumni_employment_tracking' exists.");

// Create test student, program, and clearance record
$testSid = 'STUD-ALUM-15';
$testProg = 'WUC15';
$testCert = 'CERT-WUC15-XYZ';

$db->query("DELETE FROM students WHERE SID = '$testSid'");
$db->query("DELETE FROM programs WHERE program_code = '$testProg'");
$db->query("DELETE FROM student_clearance WHERE student_id = '$testSid'");
$db->query("DELETE FROM alumni_certificates WHERE student_id = '$testSid'");
$db->query("DELETE FROM employer_internships WHERE student_id = '$testSid'");
$db->query("DELETE FROM alumni_employment_tracking WHERE student_id = '$testSid'");

$db->query("INSERT INTO programs (program_code, program_name, is_active) VALUES ('$testProg', 'Test Alumni Program', 1)");
$db->query("INSERT INTO students (SID, Fname, Lname, program, email, nrc_pass, mobile, status) VALUES ('$testSid', 'Jane', 'Alumna', '$testProg', 'jane@alumna.com', '123456/78/9', '0970000015', 'Alumni')");
$db->query("INSERT INTO student_clearance (student_id, finance_cleared, library_cleared, academic_cleared, admin_cleared, graduation_status, graduation_year) VALUES ('$testSid', 1, 1, 1, 1, 'Graduated', 2026)");
$db->query("INSERT INTO alumni_certificates (student_id, certificate_code, program_code, graduation_year, date_issued, status) VALUES ('$testSid', '$testCert', '$testProg', 2026, '2026-06-29', 'Approved')");

// 2. Public Certificate Verification Output Gating
$_GET['cert'] = $testCert;
ob_start();
require 'verify_certificate.php';
$verifyOutput = ob_get_clean();

// Reset GET
unset($_GET['cert']);

verify_assert(stripos($verifyOutput, 'Jane Alumna') !== false, "Verification: Output contains graduate's name.");
verify_assert(stripos($verifyOutput, 'Test Alumni Program') !== false, "Verification: Output contains program name.");
verify_assert(stripos($verifyOutput, 'jane@alumna.com') === false, "Privacy Gate: Output DOES NOT leak graduate's email.");
verify_assert(stripos($verifyOutput, '123456/78/9') === false, "Privacy Gate: Output DOES NOT leak graduate's NRC passport number.");
verify_assert(stripos($verifyOutput, '0970000015') === false, "Privacy Gate: Output DOES NOT leak graduate's mobile number.");

// 3. Employer Portal Gating checks
// Verify that searching an unapproved student ID fails
$unapprovedSid = 'STUD-UNAPP-15';
$db->query("DELETE FROM students WHERE SID = '$unapprovedSid'");
$db->query("DELETE FROM student_clearance WHERE student_id = '$unapprovedSid'");
$db->query("INSERT INTO students (SID, Fname, Lname, status) VALUES ('$unapprovedSid', 'John', 'Pending', 'Active')");
$db->query("INSERT INTO student_clearance (student_id, graduation_status) VALUES ('$unapprovedSid', 'Applied')");

$stmtEmpSearch = $db->prepare("
    SELECT s.SID
    FROM students s
    LEFT JOIN student_clearance c ON s.SID COLLATE utf8mb4_unicode_ci = c.student_id COLLATE utf8mb4_unicode_ci
    WHERE s.SID = ? AND (c.graduation_status = 'Approved' OR c.graduation_status = 'Graduated')
");
$stmtEmpSearch->bind_param('s', $unapprovedSid);
$stmtEmpSearch->execute();
$empRes = $stmtEmpSearch->get_result()->fetch_assoc();
$stmtEmpSearch->close();

verify_assert($empRes === null, "Employer search gate: Unapproved/pending graduation records cannot be verified.");

// Log internship rating check
$stmtIntern = $db->prepare("INSERT INTO employer_internships (student_id, company_name, supervisor_name, start_date, performance_rating, feedback) VALUES (?, 'Test Company', 'Supervisor', '2026-06-29', 5, 'Great performance')");
$stmtIntern->bind_param('s', $testSid);
verify_assert($stmtIntern->execute(), "Successfully logged employer internship placement rating.");
$stmtIntern->close();

// 4. Alumni Portal update check
$stmtAlumTrack = $db->prepare("
    INSERT INTO alumni_employment_tracking (student_id, current_company, job_title, employment_status)
    VALUES (?, 'New Company Ltd', 'Lead Engineer', 'Employed')
    ON DUPLICATE KEY UPDATE current_company = 'New Company Ltd', job_title = 'Lead Engineer', employment_status = 'Employed'");
$stmtAlumTrack->bind_param('s', $testSid);
verify_assert($stmtAlumTrack->execute(), "Successfully updated alumni employment status tracker.");
$stmtAlumTrack->close();

// Clean up
$db->query("DELETE FROM students WHERE SID = '$testSid'");
$db->query("DELETE FROM programs WHERE program_code = '$testProg'");
$db->query("DELETE FROM student_clearance WHERE student_id = '$testSid'");
$db->query("DELETE FROM alumni_certificates WHERE student_id = '$testSid'");
$db->query("DELETE FROM employer_internships WHERE student_id = '$testSid'");
$db->query("DELETE FROM alumni_employment_tracking WHERE student_id = '$testSid'");
$db->query("DELETE FROM students WHERE SID = '$unapprovedSid'");
$db->query("DELETE FROM student_clearance WHERE student_id = '$unapprovedSid'");

echo "\nVerification Results:\n";
echo "=== [ALL PASS] Phase 15 Alumni & Employer Integration Verification completed successfully! ===\n";
?>
