<?php
/**
 * Fee Eligibility Test Script
 * 
 * Quick test to verify the 50% payment rule is working correctly
 * Run via CLI: php test_fee_eligibility.php
 */

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/finance_guard.php';

if (!isset($db) || !($db instanceof mysqli)) {
    die("❌ Database connection failed.\n");
}

echo str_repeat("=", 70) . "\n";
echo "FEE ELIGIBILITY TEST - CA UPLOAD MODULE\n";
echo str_repeat("=", 70) . "\n\n";

// Test 1: Check if table exists
echo "Test 1: Checking course_registration table... ";
if (wuc_table_exists($db, 'course_registration')) {
    echo "✅ PASS\n";
} else {
    echo "❌ FAIL - Table does not exist. Run ensure_course_registration_table.php first.\n";
    exit(1);
}

// Test 2: Check required columns
echo "Test 2: Checking required columns... ";
$required_cols = ['Sid', 'course_code', 'semester', 'Year', 'tuition_total', 'amount_paid', 'is_active'];
$missing = [];
foreach ($required_cols as $col) {
    if (!wuc_column_exists($db, 'course_registration', $col)) {
        $missing[] = $col;
    }
}
if (empty($missing)) {
    echo "✅ PASS\n";
} else {
    echo "❌ FAIL - Missing columns: " . implode(', ', $missing) . "\n";
    exit(1);
}

// Test 3: Check for sample data
echo "Test 3: Checking for registration data... ";
$res = $db->query("SELECT COUNT(*) as cnt FROM course_registration WHERE is_active = 1");
$count = $res->fetch_assoc()['cnt'];
$res->free();

if ($count > 0) {
    echo "✅ PASS ($count active registrations found)\n";
} else {
    echo "⚠️  WARNING - No registrations found. Run sample_course_registration_data.php to create test data.\n";
}

// Test 4: Test eligibility function with different scenarios
echo "\nTest 4: Testing fee eligibility calculation...\n";
echo str_repeat("-", 70) . "\n";

// Create test registrations
$test_cases = [
    ['sid' => 'TEST001', 'paid' => 0.00,   'total' => 1000.00, 'expected' => false, 'label' => '0% payment'],
    ['sid' => 'TEST002', 'paid' => 250.00, 'total' => 1000.00, 'expected' => false, 'label' => '25% payment'],
    ['sid' => 'TEST003', 'paid' => 499.99, 'total' => 1000.00, 'expected' => false, 'label' => '49.99% payment'],
    ['sid' => 'TEST004', 'paid' => 500.00, 'total' => 1000.00, 'expected' => true,  'label' => '50% payment (minimum)'],
    ['sid' => 'TEST005', 'paid' => 750.00, 'total' => 1000.00, 'expected' => true,  'label' => '75% payment'],
    ['sid' => 'TEST006', 'paid' => 1000.00,'total' => 1000.00, 'expected' => true,  'label' => '100% payment'],
];

// Clear any existing test data
$db->query("DELETE FROM course_registration WHERE Sid LIKE 'TEST%'");

// Insert test cases
$stmt = $db->prepare("INSERT INTO course_registration 
    (Sid, course_code, semester, Year, program_type, tuition_total, amount_paid, is_active)
    VALUES (?, 'TEST101', '1', '1', 'semester', ?, ?, 1)");

foreach ($test_cases as $tc) {
    $stmt->bind_param('sdd', $tc['sid'], $tc['total'], $tc['paid']);
    $stmt->execute();
}
$stmt->close();

// Test each case
$passed = 0;
$failed = 0;

foreach ($test_cases as $tc) {
    $result = is_student_allowed_ca($db, $tc['sid'], '1', '1');
    $actual = $result['allowed'];
    $percent = $result['percent'];
    
    $status = ($actual === $tc['expected']) ? '✅ PASS' : '❌ FAIL';
    $expected_text = $tc['expected'] ? 'ALLOW' : 'BLOCK';
    $actual_text = $actual ? 'ALLOW' : 'BLOCK';
    
    printf("%-20s | %.1f%% | Expected: %-5s | Actual: %-5s | %s\n", 
        $tc['label'], 
        $percent, 
        $expected_text, 
        $actual_text, 
        $status
    );
    
    if ($actual === $tc['expected']) {
        $passed++;
    } else {
        $failed++;
    }
}

// Clean up test data
$db->query("DELETE FROM course_registration WHERE Sid LIKE 'TEST%'");

echo str_repeat("-", 70) . "\n";
echo "Results: $passed passed, $failed failed\n";

// Test 5: Check enforcement settings
echo "\nTest 5: Checking enforcement settings...\n";
$enforce = wuc_get_setting($db, 'enforce_ca_payment', '1');
$min_percent = wuc_get_setting($db, 'min_ca_paid_percent', '50');

echo "  • Enforcement enabled: " . ($enforce === '1' ? '✅ YES' : '⚠️  NO (enforcement disabled)') . "\n";
echo "  • Minimum payment required: {$min_percent}%\n";

// Final summary
echo "\n" . str_repeat("=", 70) . "\n";
if ($failed === 0) {
    echo "✅ ALL TESTS PASSED - Fee eligibility validation is working correctly!\n";
} else {
    echo "❌ SOME TESTS FAILED - Please review the output above.\n";
}
echo str_repeat("=", 70) . "\n";

echo "\nNext Steps:\n";
echo "1. Navigate to upload_ca.php\n";
echo "2. Try uploading CA marks for students with different payment levels\n";
echo "3. Verify that students with <50% payment are blocked\n";
echo "4. Check view_course_registrations.php for fee status overview\n";

$db->close();
?>
