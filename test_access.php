<?php
/**
 * Quick Access Test Script
 * Run this from command line: php test_access.php
 */

// Start session
session_start();

echo "=== ITC Portal Access Test ===\n\n";

// 1. Test Database Connection
echo "1. Database Connection Test:\n";
require_once __DIR__ . '/db/connect.php';
if (isset($db) && !$db->connect_error) {
    echo "   ✓ Database connected successfully\n";
    echo "   Host: " . $db->host_info . "\n";
} else {
    echo "   ✗ Database connection failed\n";
    exit(1);
}

// 2. Test Session Handler
echo "\n2. Session Handler Test:\n";
require_once __DIR__ . '/admissions/includes/session_handler.php';
echo "   ✓ Session handler loaded\n";
echo "   Session ID: " . session_id() . "\n";

// 3. Test Authentication Functions
echo "\n3. Authentication Functions Test:\n";
echo "   - isAdminAuthenticated(): " . (isAdminAuthenticated() ? 'true' : 'false') . "\n";
echo "   - isStudentAuthenticated(): " . (isStudentAuthenticated() ? 'true' : 'false') . "\n";
echo "   - isUserAuthenticated(): " . (isUserAuthenticated() ? 'true' : 'false') . "\n";

// 4. Test Critical Tables
echo "\n4. Database Tables Test:\n";
$tables = ['staff', 'students', 'user_credentials', 'access_right', 'staff_positions', 'positions'];
foreach ($tables as $table) {
    $result = $db->query("SHOW TABLES LIKE '$table'");
    $exists = $result && $result->num_rows > 0;
    echo "   " . ($exists ? '✓' : '✗') . " Table: $table\n";
}

// 5. Test students.php syntax
echo "\n5. Students.php File Test:\n";
$studentsFile = __DIR__ . '/admissions/students.php';
if (file_exists($studentsFile)) {
    echo "   ✓ File exists\n";
    
    // Check for WUC_PORTAL constant check (should be removed)
    $content = file_get_contents($studentsFile);
    if (strpos($content, "defined('WUC_PORTAL')") !== false) {
        echo "   ✗ WARNING: WUC_PORTAL check still present\n";
    } else {
        echo "   ✓ WUC_PORTAL check removed\n";
    }
    
    // Syntax check
    exec("php -l \"$studentsFile\" 2>&1", $output, $return);
    if ($return === 0) {
        echo "   ✓ Syntax valid\n";
    } else {
        echo "   ✗ Syntax errors:\n";
        echo "   " . implode("\n   ", $output) . "\n";
    }
} else {
    echo "   ✗ File not found\n";
}

// 6. Session Variables (if logged in)
echo "\n6. Session Variables:\n";
if (!empty($_SESSION)) {
    foreach ($_SESSION as $key => $value) {
        if (is_string($value) || is_numeric($value)) {
            echo "   - $key: " . (strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value) . "\n";
        } else {
            echo "   - $key: [" . gettype($value) . "]\n";
        }
    }
} else {
    echo "   (No active session - user not logged in)\n";
}

echo "\n=== Test Complete ===\n";
echo "Status: " . (isUserAuthenticated() ? "Authenticated ✓" : "Not authenticated (login required)") . "\n\n";

if (!isUserAuthenticated()) {
    echo "Note: To test authenticated access, please log in first at:\n";
    echo "http://localhost/wucportal/staff_login.php\n\n";
}
