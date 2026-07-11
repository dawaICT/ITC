<?php
declare(strict_types=1);

/**
 * Access Control Flow Verification Script.
 * Verifies unified user permissions, roles, and assignments checks.
 */

require_once __DIR__ . '/../includes/auth.php';

echo "=== Access Control System Verification ===\n";

if (!isset($db) || !$db instanceof mysqli || $db->connect_errno) {
    die("Error: DB connection not established.\n");
}

// 1. Get first admin user from DB
$res = $db->query("SELECT user_id, username, primary_role FROM users WHERE primary_role = 'systems_admin' LIMIT 1");
$admin = $res ? $res->fetch_assoc() : null;
if ($admin) {
    $userId = (int)$admin['user_id'];
    echo "Testing Admin User: {$admin['username']} (ID: $userId)\n";
    
    // Check roles
    $roles = getUserRoles($userId);
    echo "Roles count: " . count($roles) . "\n";
    
    // Check permission
    $canManageSettings = hasPermission($userId, 'settings', 'manage');
    echo "Has settings.manage: " . ($canManageSettings ? 'YES' : 'NO') . "\n";
    
    $canAccessSettingsModule = canAccessModule($userId, 'settings');
    echo "Can access settings module: " . ($canAccessSettingsModule ? 'YES' : 'NO') . "\n";
} else {
    echo "Warning: No systems_admin user found in database.\n";
}

// 2. Get first lecturer user from DB
$res = $db->query("SELECT user_id, username, primary_role FROM users WHERE primary_role = 'lecturer' LIMIT 1");
$lect = $res ? $res->fetch_assoc() : null;
if ($lect) {
    $userId = (int)$lect['user_id'];
    echo "\nTesting Lecturer User: {$lect['username']} (ID: $userId)\n";
    
    // Check roles
    $roles = getUserRoles($userId);
    echo "Roles count: " . count($roles) . "\n";
    
    // Check permissions
    $canUploadCA = hasPermission($userId, 'ca_upload', 'upload');
    echo "Has ca_upload.upload: " . ($canUploadCA ? 'YES' : 'NO') . "\n";
    
    $canManageSettings = hasPermission($userId, 'settings', 'manage');
    echo "Has settings.manage: " . ($canManageSettings ? 'YES' : 'NO') . "\n"; // Should be NO
    
    $canAccessCAUpload = canAccessModule($userId, 'ca_upload');
    echo "Can access ca_upload module: " . ($canAccessCAUpload ? 'YES' : 'NO') . "\n";
} else {
    echo "Warning: No lecturer user found in database.\n";
}

// 3. Test assignments helper functions
echo "\nTesting Assignment Helper Functions:\n";

// HOD assignment
$res = $db->query("SELECT staff_id, department_id FROM department_assignments WHERE assignment_type = 'hod' AND status = 'active' LIMIT 1");
$hod = $res ? $res->fetch_assoc() : null;
if ($hod) {
    $isHod = isAssignedHOD($hod['staff_id'], $hod['department_id']);
    echo "isAssignedHOD({$hod['staff_id']}, {$hod['department_id']}): " . ($isHod ? 'YES' : 'NO') . "\n";
}

// Lecturer assignment
$res = $db->query("SELECT staff_id, course_code, program_code, semester FROM course_lecturer WHERE status = 'active' LIMIT 1");
$lc = $res ? $res->fetch_assoc() : null;
if ($lc) {
    $isLec = isAssignedLecturer($lc['staff_id'], $lc['course_code'], $lc['program_code'], $lc['semester']);
    echo "isAssignedLecturer({$lc['staff_id']}, {$lc['course_code']}, {$lc['program_code']}, {$lc['semester']}): " . ($isLec ? 'YES' : 'NO') . "\n";
}

// Student registration/course registration
$res = $db->query("SELECT Sid, course_code, semester FROM course_registration WHERE is_active = 1 LIMIT 1");
$scr = $res ? $res->fetch_assoc() : null;
if ($scr) {
    $isReg = isStudentRegisteredForCourse($scr['Sid'], $scr['course_code'], null, $scr['semester']);
    echo "isStudentRegisteredForCourse({$scr['Sid']}, {$scr['course_code']}, null, {$scr['semester']}): " . ($isReg ? 'YES' : 'NO') . "\n";
}

echo "\nVerification script completed successfully!\n";
?>
