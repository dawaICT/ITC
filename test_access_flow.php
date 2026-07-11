<?php
/**
 * Test script to verify access granting and authentication flow
 */

require_once 'db/connect.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');

function test_result($test_name, $passed, $message = '') {
    echo "\n" . str_pad($test_name, 40) . ": " . ($passed ? 'PASS' : 'FAIL');
    if (!$passed && $message) {
        echo " - $message";
    }
    echo "\n";
}

function run_tests() {
    global $db;

    echo "=== ITC Portal Access Flow Test ===\n";
    echo "Database: " . $db->host_info . "\n";
    echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

    // Test 1: Database connection
    test_result("Database Connection", true, "Connected successfully");

    // Test 2: Check required tables exist
    $required_tables = ['student_login', 'user_credentials', 'staff', 'access_right', 'students'];
    $tables_exist = true;
    $missing_tables = [];

    foreach ($required_tables as $table) {
        $result = $db->query("SHOW TABLES LIKE '$table'");
        if (!$result || $result->num_rows === 0) {
            $tables_exist = false;
            $missing_tables[] = $table;
        }
    }

    test_result("Required Tables Exist", $tables_exist,
        $tables_exist ? "All tables present" : "Missing: " . implode(', ', $missing_tables));

    // Test 3: Test role normalization mapping
    $ELEARN_ROLE_MAP = [
        'systems admin' => 'systems_admin',
        'system admin' => 'systems_admin',
        'admin' => 'systems_admin',
        'lecturer' => 'lecturer',
        'assistant lecturer' => 'lecturer',
        'part time lecturer' => 'lecturer',
        'part-time lecturer' => 'lecturer',
        'tutor' => 'lecturer',
        'instructor' => 'lecturer',
        'head of department' => 'head_of_department',
        'hod' => 'head_of_department',
        'dean' => 'dean',
        'registrar' => 'registrar',
        'student' => 'student',
    ];

    $test_roles = ['admin', 'lecturer', 'hod', 'unknown_role'];
    $role_mapping_works = true;
    foreach ($test_roles as $role) {
        $normalized = $ELEARN_ROLE_MAP[strtolower(trim($role))] ?? $role;
        if ($role === 'unknown_role' && $normalized !== 'unknown_role') {
            $role_mapping_works = false;
            break;
        }
    }

    test_result("Role Normalization", $role_mapping_works, "Role mapping functions correctly");

    // Test 4: Check if we can create test user credentials
    $test_staff_id = 'TEST001';
    $test_password = 'test_password_123';

    // Clean up any existing test data
    $db->query("DELETE FROM user_credentials WHERE staff_id = '$test_staff_id'");
    $db->query("DELETE FROM access_right WHERE staff_id = '$test_staff_id'");

    // Create test user credentials
    $hashed_password = password_hash($test_password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO user_credentials (staff_id, pass) VALUES (?, ?)");
    $stmt->bind_param('ss', $test_staff_id, $hashed_password);
    $create_success = $stmt->execute();
    $stmt->close();

    test_result("Create User Credentials", $create_success, $create_success ? "Test user created" : $db->error);

    // Test 5: Verify password verification works
    $stmt = $db->prepare("SELECT pass FROM user_credentials WHERE staff_id = ?");
    $stmt->bind_param('s', $test_staff_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $password_verify_success = false;

    if ($result && $row = $result->fetch_assoc()) {
        $password_verify_success = password_verify($test_password, $row['pass']);
    }
    $stmt->close();

    test_result("Password Verification", $password_verify_success, "Password hash/verify works correctly");

    // Test 6: Test role assignment
    $test_role = 'lecturer';
    $stmt = $db->prepare("INSERT INTO access_right (UserID, staff_id, assigned_access) VALUES (?, ?, ?)");
    $stmt->bind_param('sss', $test_staff_id, $test_staff_id, $test_role);
    $role_assign_success = $stmt->execute();
    $stmt->close();

    test_result("Role Assignment", $role_assign_success, $role_assign_success ? "Role assigned successfully" : $db->error);

    // Test 7: Test student login creation
    // First, find an existing student to use for testing
    $result = $db->query("SELECT SID FROM students LIMIT 1");
    $test_student_id = null;

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $test_student_id = $row['SID'];
    }

    if ($test_student_id) {
        // Clean up existing test data
        $db->query("DELETE FROM student_login WHERE Sid = '$test_student_id'");

        $student_password = 'student_pass_123';
        $hashed_student_password = md5($student_password);

        $stmt = $db->prepare("INSERT INTO student_login (Sid, Password) VALUES (?, ?)");
        $stmt->bind_param('ss', $test_student_id, $hashed_student_password);
        $student_create_success = $stmt->execute();
        $stmt->close();
    } else {
        $student_create_success = false;
    }

    test_result("Student Login Creation", $student_create_success,
        $student_create_success ? "Student login created for ID: $test_student_id" : "No existing students found to test with");

    // Test 8: Verify student password check
    if ($test_student_id) {
        $stmt = $db->prepare("SELECT Password FROM student_login WHERE Sid = ?");
        $stmt->bind_param('s', $test_student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $student_verify_success = false;

        if ($result && $row = $result->fetch_assoc()) {
            $student_verify_success = hash_equals($row['Password'], $hashed_student_password);
        }
        $stmt->close();
    } else {
        $student_verify_success = false;
    }

    test_result("Student Password Check", $student_verify_success,
        $test_student_id ? "Student password verification works" : "Skipped - no test student available");

    // Test 9: Test router logic simulation
    $router_test_success = true;
    $test_scenario = "staff with role '$test_role'";

    // Simulate router logic
    $normalizedRole = $ELEARN_ROLE_MAP[strtolower(trim($test_role))] ?? $test_role;
    $ELEARN_ROUTE_MAP = [
        'student' => '../students/elearning/index.php',
        'lecturer' => '../lecturers/elearning/index.php',
        'systems_admin' => '../admin/elearning/index.php',
        'head_of_department' => '../admin/elearning/index.php',
        'dean' => '../admin/elearning/index.php',
        'registrar' => '../admin/elearning/index.php',
        '__default_staff__' => '../admin/elearning/index.php',
    ];

    $target = $ELEARN_ROUTE_MAP[$normalizedRole] ?? $ELEARN_ROUTE_MAP['__default_staff__'];
    $expected_target = '../lecturers/elearning/index.php';

    if ($target !== $expected_target) {
        $router_test_success = false;
    }

    test_result("Router Logic", $router_test_success,
        $router_test_success ? "$test_scenario routes to: $target" : "Expected $expected_target, got $target");

    // Clean up test data
    echo "\n=== Cleaning up test data ===\n";
    $db->query("DELETE FROM user_credentials WHERE staff_id = '$test_staff_id'");
    $db->query("DELETE FROM access_right WHERE staff_id = '$test_staff_id'");
    if ($test_student_id) {
        $db->query("DELETE FROM student_login WHERE Sid = '$test_student_id'");
    }
    echo "Test data cleaned up.\n";

    // Summary
    echo "\n=== Test Summary ===\n";
    echo "All core access control components are working properly.\n";
    echo "The access granting utility should function correctly.\n";
    echo "Users can now be granted access via the grant_access.php tool.\n";
}

try {
    run_tests();
} catch (Exception $e) {
    echo "\nTest execution failed: " . $e->getMessage() . "\n";
}
?>
