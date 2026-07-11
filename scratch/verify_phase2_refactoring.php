<?php
/**
 * Verification Script for Phase 2 Refactoring
 *
 * Usage:
 *   C:\xampp\php\php.exe scratch\verify_phase2_refactoring.php
 */

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');
require_once __DIR__ . '/../db/connect.php';

echo "=== Phase 2 Verification Checks ===\n";

// 1. Check academic settings helper
require_once __DIR__ . '/../includes/academic_settings_helper.php';
$maxMonthsSetting = wuc_get_academic_setting($db, 'short_course_max_duration_months', 'fallback');
echo "1. Academic setting 'short_course_max_duration_months': " . $maxMonthsSetting . " (Expected: 6)\n";
if ($maxMonthsSetting === '6') {
    echo "   [PASS] Academic settings helper loaded correctly.\n";
} else {
    echo "   [FAIL] Academic settings helper did not return expected value.\n";
}

// 2. Check short course validation
require_once __DIR__ . '/../includes/short_course_db.php';
$validDuration = sc_is_short_course_days(90); // 3 months
$invalidDuration = sc_is_short_course_days(200); // ~6.6 months
echo "2. Short course duration check (90 days): " . ($validDuration ? "Valid" : "Invalid") . " (Expected: Valid)\n";
echo "   Short course duration check (200 days): " . ($invalidDuration ? "Valid" : "Invalid") . " (Expected: Invalid)\n";
if ($validDuration && !$invalidDuration) {
    echo "   [PASS] Short course duration validator behaves dynamically.\n";
} else {
    echo "   [FAIL] Short course duration validator failed verification.\n";
}

// 3. Verify BEFORE INSERT triggers on semester_registration
echo "3. Trigger Validation:\n";
// Let's create a temporary unique registration to test insertion
$testSid = 'CSE26456789';
$testProgCodeTerm = 'AUTO-001'; // Term-based program
$testProgCodeSem = 'DTL'; // Semester-based program
$testYear = '2026';
$testPeriod = '3';

// Delete previous test registrations to avoid unique constraints
$db->query("DELETE FROM semester_registration WHERE SID = '{$testSid}' AND academic_year = '{$testYear}' AND (program_code = '{$testProgCodeTerm}' OR program_code = '{$testProgCodeSem}')");

// Insert term-based program registration (not specifying period_type)
$db->query("INSERT INTO semester_registration (program_code, SID, semester, academic_year, registration_date, created_at) VALUES ('{$testProgCodeTerm}', '{$testSid}', '{$testPeriod}', '{$testYear}', NOW(), NOW())");
$termReg = $db->query("SELECT period_type FROM semester_registration WHERE SID = '{$testSid}' AND program_code = '{$testProgCodeTerm}' AND academic_year = '{$testYear}' LIMIT 1")->fetch_assoc();

// Insert semester-based program registration (not specifying period_type)
$db->query("INSERT INTO semester_registration (program_code, SID, semester, academic_year, registration_date, created_at) VALUES ('{$testProgCodeSem}', '{$testSid}', '1', '{$testYear}', NOW(), NOW())");
$semReg = $db->query("SELECT period_type FROM semester_registration WHERE SID = '{$testSid}' AND program_code = '{$testProgCodeSem}' AND academic_year = '{$testYear}' LIMIT 1")->fetch_assoc();

echo "   Term program registration period_type: " . ($termReg['period_type'] ?? 'NULL') . " (Expected: term)\n";
echo "   Semester program registration period_type: " . ($semReg['period_type'] ?? 'NULL') . " (Expected: semester)\n";

if (($termReg['period_type'] ?? '') === 'term' && ($semReg['period_type'] ?? '') === 'semester') {
    echo "   [PASS] BEFORE INSERT database triggers synchronized period_type perfectly.\n";
} else {
    echo "   [FAIL] Database triggers failed to synchronize period_type.\n";
}

// Cleanup
$db->query("DELETE FROM semester_registration WHERE SID = '{$testSid}' AND academic_year = '{$testYear}' AND (program_code = '{$testProgCodeTerm}' OR program_code = '{$testProgCodeSem}')");

echo "=== Verification Complete ===\n";
?>
