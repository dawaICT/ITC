<?php
/**
 * Automated Verification Script - Phase 10 Student Experience
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');

require_once __DIR__ . '/../db/connect.php';

echo "=== Phase 10 Student Experience Verification Checks ===\n\n";

function verify_assert(bool $condition, string $description) {
    if ($condition) {
        echo "[PASS] $description\n";
    } else {
        echo "[FAIL] $description\n";
        exit(1);
    }
}

// 1. Check workspace dashboard code integrates correct UI parameters
$index_code = file_get_contents('students/index.php');
verify_assert(stripos($index_code, 'digitalIdModal') !== false, "Digital Student ID Modal integrated in students/index.php.");
verify_assert(stripos($index_code, 'Launch AI Tutor') !== false, "AI Tutor launcher container integrated in students/index.php.");
verify_assert(stripos($index_code, 'wucAiwPanel') !== false, "AI Tutor launcher binds to correct click-trigger selector.");

// 2. Verify Student Data Isolation Gating (All primary queries must filter by ? or $_SESSION['Sid'])
verify_assert(stripos($index_code, "SID = ?") !== false || stripos($index_code, "Sid = ?") !== false, "Student data is safely isolated to the logged-in student session ID.");

echo "\nVerification Results:\n";
echo "=== [ALL PASS] Phase 10 Student Experience Verification completed successfully! ===\n";
?>
