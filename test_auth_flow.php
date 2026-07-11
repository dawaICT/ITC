<?php
// Test script to simulate accessing admissions/students.php after login
session_start();

// Simulate the session data that would be set by staffLogin.php
$_SESSION['staff_id'] = 'WUC015';
$_SESSION['user_name'] = 'Mss. Wenndy Katongo';
$_SESSION['user_role'] = 'admin';
$_SESSION['position'] = 'Admission';

echo "Simulated session data:\n";
echo "- staff_id: " . ($_SESSION['staff_id'] ?? 'not set') . "\n";
echo "- user_name: " . ($_SESSION['user_name'] ?? 'not set') . "\n";
echo "- user_role: " . ($_SESSION['user_role'] ?? 'not set') . "\n";
echo "- position: " . ($_SESSION['position'] ?? 'not set') . "\n\n";

// Now simulate the authentication checks from admissions/students.php
echo "Testing authentication checks:\n";

// Check 1: staff_id
if (!isset($_SESSION['staff_id'])) {
    echo "❌ FAIL: staff_id not set - would redirect to login\n";
} else {
    echo "✅ PASS: staff_id is set (" . $_SESSION['staff_id'] . ")\n";
}

// Check 2: user_role
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    echo "❌ FAIL: user_role not set or not admin - would redirect to login\n";
} else {
    echo "✅ PASS: user_role is admin\n";
}

echo "\nAuthentication test: ";
if (isset($_SESSION['staff_id']) && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
    echo "✅ SUCCESS - User would be allowed access to students.php\n";
} else {
    echo "❌ FAILED - User would be redirected to login\n";
}
?>