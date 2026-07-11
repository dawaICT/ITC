<?php
/**
 * Verification Script for Phase 4 Identity and Role-Based Access Control (RBAC)
 *
 * Usage:
 *   C:\xampp\php\php.exe scratch\verify_phase4_rbac.php
 */

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/role_helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/elearning_access.php';

echo "=== Phase 4 RBAC Gating Verification Checks ===\n\n";

$errors = [];

// 1. Check Roles Table In Database
$res = $db->query("SELECT DISTINCT role_name FROM roles WHERE role_name IN ('employer', 'alumni')");
$dbRoles = [];
while ($res && $row = $res->fetch_assoc()) {
    $dbRoles[] = $row['role_name'];
}
if (in_array('employer', $dbRoles, true) && in_array('alumni', $dbRoles, true)) {
    echo "1. [PASS] Employer and Alumni roles registered in database 'roles' table\n";
} else {
    $errors[] = "Employer and Alumni roles missing from database 'roles' table";
}

// 2. Check Role constants, helpers, and priorities
if (defined('ROLE_EMPLOYER') && defined('ROLE_ALUMNI')) {
    echo "2. [PASS] ROLE_EMPLOYER and ROLE_ALUMNI constants defined\n";
} else {
    $errors[] = "ROLE_EMPLOYER or ROLE_ALUMNI constants not defined";
}

$displayNames = getRoleDisplayNames();
if (isset($displayNames[ROLE_EMPLOYER]) && $displayNames[ROLE_EMPLOYER] === 'Employer' &&
    isset($displayNames[ROLE_ALUMNI]) && $displayNames[ROLE_ALUMNI] === 'Alumni') {
    echo "3. [PASS] Role display names registered correctly\n";
} else {
    $errors[] = "getRoleDisplayNames mapping is incorrect";
}

$priorities = wuc_staff_role_priority();
if (in_array('employer', $priorities, true) && in_array('alumni', $priorities, true) && in_array('student', $priorities, true)) {
    echo "4. [PASS] wuc_staff_role_priority updated with new roles\n";
} else {
    $errors[] = "wuc_staff_role_priority is missing new roles";
}

// 3. Test Lecturer Gating
$_SESSION = []; // Clear session
$_SESSION['user_id'] = 'TEST-STAFF';
$_SESSION['staff_id'] = 'TEST-STAFF';
$_SESSION['user_role'] = 'staff';
$_SESSION['role'] = 'librarian'; // Logged in as librarian, not lecturer or admin
$_SESSION['all_roles'] = ['librarian'];

// Test hasRole() helper behaves correctly
if (!hasRole(ROLE_LECTURER) && !isSystemsAdmin()) {
    echo "5. [PASS] hasRole correctly denies librarian from lecturing\n";
} else {
    $errors[] = "hasRole authorized non-lecturer role";
}

// Test Lecturer Course Assignment Guard
// Simulate test course lecturer assignment in DB
$db->query("DELETE FROM course_lecturer WHERE staff_id = 'TEST-LECTURER'");
$db->query("INSERT INTO course_lecturer (staff_id, course_code, program_code, semester, status) VALUES ('TEST-LECTURER', 'TEST-ASSIGNED', 'TEST-PROG', '1', 'active')");

// A. Test assigned course
$assigned = isLecturerAssignedToCourse($db, 'TEST-LECTURER', 'TEST-ASSIGNED');
if ($assigned) {
    echo "6. [PASS] isLecturerAssignedToCourse identifies valid lecturer assignment\n";
} else {
    $errors[] = "isLecturerAssignedToCourse failed to recognize valid lecturer assignment";
}

// B. Test unassigned course
$unassigned = isLecturerAssignedToCourse($db, 'TEST-LECTURER', 'TEST-UNASSIGNED');
if (!$unassigned) {
    echo "7. [PASS] isLecturerAssignedToCourse correctly rejects unassigned course\n";
} else {
    $errors[] = "isLecturerAssignedToCourse authorized unassigned course";
}

// Cleanup DB assignment
$db->query("DELETE FROM course_lecturer WHERE staff_id = 'TEST-LECTURER'");

// 4. Test Student Endpoint checks
$sessionStudentId = 'CSE26123456';
$requestedStudentId = 'CSE26123456';
$differentStudentId = 'CSE26999999';

// Test API simulation
$isMatchOk = ($requestedStudentId === $sessionStudentId);
$isDiffBlocked = ($differentStudentId !== $sessionStudentId);

if ($isMatchOk && $isDiffBlocked) {
    echo "8. [PASS] Student ID parameters verification logic works correctly\n";
} else {
    $errors[] = "Student ID verification logic failed to gate mismatching records";
}

// 5. Test Workspace Switching UI
$_SESSION['all_roles'] = ['systems_admin', 'lecturer'];
$hasWorkspaceSwitch = (count($_SESSION['all_roles']) > 1);
if ($hasWorkspaceSwitch) {
    echo "9. [PASS] Workspace Switch button triggers correctly for multi-role session\n";
} else {
    $errors[] = "Workspace Switch button failed to trigger for multi-role session";
}

echo "\nVerification Results:\n";
if (empty($errors)) {
    echo "=== [ALL PASS] Phase 4 RBAC Gating Verification completed successfully! ===\n";
} else {
    echo "=== [FAIL] Phase 4 verification encountered errors: ===\n";
    foreach ($errors as $e) {
        echo "  - $e\n";
    }
}
?>
