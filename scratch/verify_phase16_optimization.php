<?php
/**
 * Automated Verification Script - Phase 16 Continuous Optimization
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
require_once __DIR__ . '/../includes/audit_helper.php';

echo "=== Phase 16 Continuous Optimization Verification Checks ===\n\n";

function verify_assert(bool $condition, string $description) {
    if ($condition) {
        echo "[PASS] $description\n";
    } else {
        echo "[FAIL] $description\n";
        exit(1);
    }
}

// 1. Audit logs checking
$testAction = 'TEST_MOCK_ACTION_16';
$testDetails = 'Simulated test audit details for verification';

// Log audit action
verify_assert(wuc_log_audit($db, $testAction, $testDetails), "Successfully logged test audit event.");

// Retrieve and verify audit event
$resAudit = $db->query("SELECT * FROM system_audit_logs WHERE action = '$testAction' ORDER BY id DESC LIMIT 1");
$audit = $resAudit->fetch_assoc();
verify_assert($audit !== null, "Audit record successfully retrieved from database.");
verify_assert($audit['details'] === $testDetails, "Logged details match the test audit message.");
verify_assert($audit['user_role'] === 'systems_admin', "User role is correctly captured in audit record.");

// Clean up test audit log
$db->query("DELETE FROM system_audit_logs WHERE action = '$testAction'");

// 2. Backup checking
$backupDir = 'C:\\xampp\\wucportal-var\\backups';
verify_assert(is_dir($backupDir), "Backups directory exists at: $backupDir");

$files = glob($backupDir . DIRECTORY_SEPARATOR . 'wucportal_backup_*.sql');
verify_assert(count($files) >= 1, "At least one SQL database backup file is present.");

// Inspect the latest backup file header
$latestFile = end($files);
$headerContent = file_get_contents($latestFile, false, null, 0, 100);
verify_assert(stripos($headerContent, 'ITC WUCPortal Automated Database Backup') !== false, "Backup file contains the correct ITC registry headers.");

echo "\nVerification Results:\n";
echo "=== [ALL PASS] Phase 16 Optimization Verification completed successfully! ===\n";
?>
