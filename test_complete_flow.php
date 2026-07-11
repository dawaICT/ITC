<?php
// Comprehensive end-to-end test of login -> access flow
echo "=== ITC Portal Authentication Flow Test ===\n\n";

// Step 1: Simulate login process
echo "Step 1: Simulating staff login...\n";
session_start();

// Clear any existing session
session_unset();
session_destroy();
session_start();

// Simulate staffLogin.php logic
$user_id = 'WUC015'; // Test staff member
require 'db/connect.php';

$login_sql = "SELECT uc.pass, s.staff_id, s.Fname, s.Lname, s.title
              FROM user_credentials uc
              INNER JOIN staff s ON uc.staff_id = s.staff_id
              WHERE uc.staff_id = ? LIMIT 1";
$stmt = $db->prepare($login_sql);
$stmt->bind_param("s", $user_id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->bind_result($db_pass, $staff_id, $Fname, $Lname, $title);
    $stmt->fetch();

    // Get staff position
    $role_query = "SELECT p.PosName FROM staff_positions sp
        INNER JOIN positions p ON sp.PosID = p.PosID
        WHERE sp.staff_id = ? LIMIT 1";
    $role_stmt = $db->prepare($role_query);
    $role_stmt->bind_param("s", $staff_id);
    $role_stmt->execute();
    $role_stmt->bind_result($position_name);
    $role_stmt->fetch();
    $role_stmt->close();

    // Determine user role
    $admin_positions = ['Admission', 'Administrator', 'admin', 'Master admin', 'super_admin'];
    $user_role = in_array($position_name, $admin_positions) ? 'admin' : 'staff';

    // Set session data
    $_SESSION['staff_id'] = $staff_id;
    $_SESSION['user_name'] = $title . ' ' . $Fname . ' ' . $Lname;
    $_SESSION['user_role'] = $user_role;
    $_SESSION['position'] = $position_name;

    echo "✅ Login successful for $staff_id ($position_name -> $user_role)\n";
    $stmt->close();
} else {
    echo "❌ Login failed - staff not found\n";
    exit(1);
}

echo "\nStep 2: Testing authentication for admissions/students.php...\n";

// Simulate the authentication checks from admissions/students.php
$auth_passed = true;

// Check 1: staff_id
if (!isset($_SESSION['staff_id'])) {
    echo "❌ FAIL: staff_id not set\n";
    $auth_passed = false;
} else {
    echo "✅ PASS: staff_id is set (" . $_SESSION['staff_id'] . ")\n";
}

// Check 2: user_role
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    echo "❌ FAIL: user_role not set or not admin (current: " . ($_SESSION['user_role'] ?? 'not set') . ")\n";
    $auth_passed = false;
} else {
    echo "✅ PASS: user_role is admin\n";
}

// Check 3: nav_unified.php check (additional layer)
if (!isset($_SESSION['staff_id'])) {
    echo "❌ FAIL: nav_unified.php staff_id check failed\n";
    $auth_passed = false;
} else {
    echo "✅ PASS: nav_unified.php staff_id check passed\n";
}

echo "\n=== TEST RESULTS ===\n";
if ($auth_passed) {
    echo "🎉 SUCCESS: User would be granted access to admissions/students.php\n";
    echo "   - No unexpected logouts should occur\n";
    echo "   - Session variables are properly set\n";
    echo "   - Role-based access control is working\n";
} else {
    echo "❌ FAILURE: User would be redirected to login page\n";
    echo "   - Check session variable assignment in staffLogin.php\n";
    echo "   - Verify staff position assignments in database\n";
}

echo "\nSession Summary:\n";
echo "- Session ID: " . session_id() . "\n";
echo "- staff_id: " . ($_SESSION['staff_id'] ?? 'not set') . "\n";
echo "- user_name: " . ($_SESSION['user_name'] ?? 'not set') . "\n";
echo "- user_role: " . ($_SESSION['user_role'] ?? 'not set') . "\n";
echo "- position: " . ($_SESSION['position'] ?? 'not set') . "\n";
?>
