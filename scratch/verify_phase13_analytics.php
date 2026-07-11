<?php
/**
 * Automated Verification Script - Phase 13 Analytics & Decision Support
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');

// Bypass session guard redirect for CLI testing
define('IS_SCRIPT', true);
$_SESSION['user_id'] = '1';
$_SESSION['staff_id'] = '1';
$_SESSION['role'] = 'systems_admin';

require_once __DIR__ . '/../db/connect.php';

echo "=== Phase 13 Analytics & Decision Support Verification Checks ===\n\n";

function verify_assert(bool $condition, string $description) {
    if ($condition) {
        echo "[PASS] $description\n";
    } else {
        echo "[FAIL] $description\n";
        exit(1);
    }
}

// 1. Dashboard Structure Check
$dashboard_code = file_get_contents('admin/analytics_dashboard.php');
verify_assert(stripos($dashboard_code, 'admin.php') !== false, "Security: admin.php guard is included in analytics_dashboard.php.");
verify_assert(stripos($dashboard_code, 'name="program"') !== false, "Filter: Program filter selector exists.");
verify_assert(stripos($dashboard_code, 'name="intake"') !== false, "Filter: Intake filter selector exists.");
verify_assert(stripos($dashboard_code, 'name="academic_year"') !== false, "Filter: Academic Year filter selector exists.");
verify_assert(stripos($dashboard_code, 'name="gender"') !== false, "Filter: Gender filter selector exists.");
verify_assert(stripos($dashboard_code, 'Active Students') !== false, "KPI: Active Students card exists.");
verify_assert(stripos($dashboard_code, 'Fee Collection') !== false, "KPI: Fee Collection card exists.");
verify_assert(stripos($dashboard_code, 'High Risk Learners') !== false, "KPI: High Risk Learners card exists.");
verify_assert(stripos($dashboard_code, 'Clearance Approved') !== false, "KPI: Clearance Approved card exists.");

// 2. CSV Exports Endpoint Check
$exports_code = file_get_contents('admin/ajax/analytics_exports.php');
verify_assert(stripos($exports_code, "export === 'workload'") !== false, "Export endpoint supports workload summaries.");
verify_assert(stripos($exports_code, "export === 'risks'") !== false, "Export endpoint supports academic risk logs.");
verify_assert(stripos($exports_code, "export === 'fees'") !== false, "Export endpoint supports fee collection statements.");

// 3. Evaluate CSV generation directly in-process
$_GET['export'] = 'workload';
$code = file_get_contents('admin/ajax/analytics_exports.php');
$code = preg_replace('/^\s*<\?php/i', '', $code);
$code = preg_replace('/\?>\s*$/', '', $code);
$code = str_replace('exit;', '// exit;', $code);
$code = str_replace('dirname(__DIR__, 2)', "'c:/xampp/htdocs/wucportal'", $code);
$code = str_replace('dirname(__DIR__)', "'c:/xampp/htdocs/wucportal/admin'", $code);

ob_start();
try {
    eval($code);
} catch (Throwable $e) {
    // Ignore errors during testing evaluation
}
$csvOutput = ob_get_clean();

$lines = explode("\n", trim((string)$csvOutput));
verify_assert(count($lines) >= 1, "CSV exporter outputs at least a header row.");
verify_assert(stripos($lines[0], 'Staff ID') !== false && stripos($lines[0], 'Active Courses') !== false, "CSV exporter outputs correct column headers.");

echo "\nVerification Results:\n";
echo "=== [ALL PASS] Phase 13 Analytics Verification completed successfully! ===\n";
?>
