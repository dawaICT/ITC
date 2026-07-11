<?php
/**
 * Debug User Roles and Access Rights
 * Run from CLI: php debug_roles.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit; // CLI diagnostic only — never serve schema/role dumps over the web.
}
require_once 'db/connect.php';

echo "\n=== USER ROLES AND ACCESS DEBUG ===\n\n";

// 1. Check access_right table structure
echo "1. ACCESS_RIGHT TABLE SCHEMA:\n";
echo str_repeat("-", 70) . "\n";
$result = $db->query("DESCRIBE access_right");
if ($result) {
    printf("%-20s | %-20s | %-5s | %-10s\n", "Field", "Type", "Null", "Key");
    echo str_repeat("-", 70) . "\n";
    while ($row = $result->fetch_assoc()) {
        printf("%-20s | %-20s | %-5s | %-10s\n", 
            $row['Field'], 
            $row['Type'], 
            $row['Null'], 
            $row['Key']
        );
    }
} else {
    echo "ERROR: Could not describe table - " . $db->error . "\n";
}

// 2. Show all access_right records
echo "\n2. ACCESS_RIGHT RECORDS:\n";
echo str_repeat("-", 90) . "\n";
$result = $db->query("SELECT id, staff_id, assigned_access, UserID, pass FROM access_right ORDER BY id");
if ($result && $result->num_rows > 0) {
    printf("%-5s | %-12s | %-25s | %-15s | %-10s\n", "ID", "staff_id", "assigned_access", "UserID", "pass?");
    echo str_repeat("-", 90) . "\n";
    while ($row = $result->fetch_assoc()) {
        printf("%-5s | %-12s | %-25s | %-15s | %-10s\n", 
            $row['id'], 
            $row['staff_id'], 
            $row['assigned_access'],
            $row['UserID'] ?: '(empty)',
            !empty($row['pass']) ? 'yes' : 'no'
        );
    }
} else {
    echo "No records found in access_right table.\n";
}

// 3. Show unique assigned_access values
echo "\n3. UNIQUE ROLE TYPES:\n";
echo str_repeat("-", 50) . "\n";
$result = $db->query("SELECT DISTINCT assigned_access, COUNT(*) as cnt FROM access_right GROUP BY assigned_access ORDER BY cnt DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        printf("  %s (%d users)\n", $row['assigned_access'], $row['cnt']);
    }
}

// 4. Check for data integrity issues
echo "\n4. DATA INTEGRITY ISSUES:\n";
echo str_repeat("-", 50) . "\n";

// Check empty UserID
$result = $db->query("SELECT COUNT(*) as cnt FROM access_right WHERE UserID = '' OR UserID IS NULL");
$row = $result->fetch_assoc();
echo "  - Records with empty UserID: " . $row['cnt'] . "\n";

// Check empty staff_id
$result = $db->query("SELECT COUNT(*) as cnt FROM access_right WHERE staff_id = '' OR staff_id IS NULL");
$row = $result->fetch_assoc();
echo "  - Records with empty staff_id: " . $row['cnt'] . "\n";

// Check staff_id not in staff table
$result = $db->query("SELECT ar.staff_id FROM access_right ar LEFT JOIN staff s ON ar.staff_id = s.staff_id WHERE s.staff_id IS NULL");
if ($result && $result->num_rows > 0) {
    echo "  - Orphaned staff_ids (not in staff table):\n";
    while ($row = $result->fetch_assoc()) {
        echo "      " . $row['staff_id'] . "\n";
    }
} else {
    echo "  - No orphaned staff_ids found.\n";
}

// 5. Show staff with no access_right entry
echo "\n5. STAFF WITHOUT ACCESS_RIGHT ENTRY:\n";
echo str_repeat("-", 70) . "\n";
$result = $db->query("SELECT s.staff_id, s.Fname, s.Lname FROM staff s LEFT JOIN access_right ar ON s.staff_id = ar.staff_id WHERE ar.id IS NULL LIMIT 10");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        printf("  %s - %s %s\n", $row['staff_id'], $row['Fname'], $row['Lname']);
    }
    // Check total count
    $countRes = $db->query("SELECT COUNT(*) as cnt FROM staff s LEFT JOIN access_right ar ON s.staff_id = ar.staff_id WHERE ar.id IS NULL");
    $count = $countRes->fetch_assoc()['cnt'];
    if ($count > 10) {
        echo "  ... and " . ($count - 10) . " more\n";
    }
} else {
    echo "  All staff members have access_right entries.\n";
}

// 6. Check role mapping in admin.php
echo "\n6. ROLE MAPPING (from admin/includes/admin.php):\n";
echo str_repeat("-", 50) . "\n";
$roleMap = [
    'systems admin' => 'systems_admin',
    'system administrator' => 'systems_admin',
    'superadmin' => 'systems_admin',
    'super admin' => 'systems_admin',
    'admin' => 'systems_admin',
    'administrator' => 'systems_admin',
    'lecturer' => 'lecturer',
    'assistant lecturer' => 'lecturer',
    'part time lecturer' => 'lecturer',
    'part-time lecturer' => 'lecturer',
    'tutor' => 'lecturer',
    'instructor' => 'lecturer',
    'head of department' => 'head_of_department',
    'dean' => 'dean',
    'registrar' => 'registrar',
    'admission officer' => 'admission_officer',
    'accountant' => 'accountant',
];
foreach ($roleMap as $db_role => $session_role) {
    printf("  '%s' => '%s'\n", $db_role, $session_role);
}

// 7. Cross-check: Do existing assigned_access values match role map?
echo "\n7. UNMAPPED ROLES (roles in DB not in role map):\n";
echo str_repeat("-", 50) . "\n";
$result = $db->query("SELECT DISTINCT LOWER(TRIM(assigned_access)) as role FROM access_right");
$unmapped = [];
while ($row = $result->fetch_assoc()) {
    if (!isset($roleMap[$row['role']]) && !empty($row['role'])) {
        $unmapped[] = $row['role'];
    }
}
if (count($unmapped) > 0) {
    foreach ($unmapped as $role) {
        echo "  WARNING: '$role' is not in role map!\n";
    }
} else {
    echo "  All roles are properly mapped.\n";
}

// 8. Test login flow simulation
echo "\n8. SIMULATING LOGIN FLOW:\n";
echo str_repeat("-", 70) . "\n";
$result = $db->query("SELECT ar.*, s.Fname, s.Lname FROM access_right ar JOIN staff s ON ar.staff_id = s.staff_id LIMIT 5");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $roleNorm = strtolower(trim($row['assigned_access']));
        $mappedRole = $roleMap[$roleNorm] ?? $roleNorm;
        $isAdmin = in_array($roleNorm, ['systems admin', 'admin']);
        
        printf("  Staff: %s (%s %s)\n", $row['staff_id'], $row['Fname'], $row['Lname']);
        printf("    - DB assigned_access: '%s'\n", $row['assigned_access']);
        printf("    - Normalized: '%s'\n", $roleNorm);
        printf("    - Mapped to: '%s'\n", $mappedRole);
        printf("    - isAdmin(): %s\n", $isAdmin ? 'YES' : 'NO');
        printf("    - UserID: '%s', Has password: %s\n", $row['UserID'], !empty($row['pass']) ? 'yes' : 'no');
        echo "\n";
    }
}

// 9. Check accessRight.php routing
echo "\n9. ACCESS ROUTING (from accessRight.php):\n";
echo str_repeat("-", 50) . "\n";
$routes = [
    'Lecturer' => 'lecturers/',
    'Systems Admin' => 'admin/',
    'Admission Officer' => 'admissions/',
    'Accountant' => 'accounts/',
    'Head of Section' => 'hod/',
    'Registrar' => 'registrar/',
    'Dean' => 'dean/',
];
foreach ($routes as $role => $path) {
    echo "  '$role' => $path\n";
}

echo "\n=== DEBUG COMPLETE ===\n";
