<?php
// CLI Debug Script - Run from command line
define('IS_SCRIPT', true);

require_once(__DIR__ . "/db/connect.php");

echo "==========================================\n";
echo "ADMIN ACCESS & DATABASE DEBUG\n";
echo "==========================================\n\n";

// 1. Check courses table
echo "1. COURSES TABLE\n";
echo "----------------------------------------\n";
$tableCheck = $db->query("SHOW TABLES LIKE 'courses'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    echo "✓ Courses table exists\n";
    
    $countResult = $db->query("SELECT COUNT(*) as total FROM courses");
    $count = $countResult->fetch_assoc();
    echo "Total courses: " . $count['total'] . "\n";
    
    if ($count['total'] > 0) {
        echo "\nSample courses:\n";
        $sampleResult = $db->query("SELECT course_code, course_name FROM courses LIMIT 5");
        while ($row = $sampleResult->fetch_assoc()) {
            echo "  - " . $row['course_code'] . ": " . $row['course_name'] . "\n";
        }
    } else {
        echo "⚠️  WARNING: Courses table is empty!\n";
    }
} else {
    echo "✗ Courses table does NOT exist!\n";
}

echo "\n2. PROGRAMS TABLE\n";
echo "----------------------------------------\n";
$tableCheck = $db->query("SHOW TABLES LIKE 'programs'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    echo "✓ Programs table exists\n";
    
    $countResult = $db->query("SELECT COUNT(*) as total FROM programs");
    $count = $countResult->fetch_assoc();
    echo "Total programs: " . $count['total'] . "\n";
    
    if ($count['total'] > 0) {
        echo "\nSample programs:\n";
        $sampleResult = $db->query("SELECT program_code, program_name FROM programs LIMIT 5");
        while ($row = $sampleResult->fetch_assoc()) {
            echo "  - " . $row['program_code'] . ": " . $row['program_name'] . "\n";
        }
    } else {
        echo "⚠️  WARNING: Programs table is empty!\n";
    }
} else {
    echo "✗ Programs table does NOT exist!\n";
}

echo "\n3. ACCESS_RIGHT TABLE (Admin Users)\n";
echo "----------------------------------------\n";
$query = "SELECT staff_id, UserID, assigned_access FROM access_right WHERE LOWER(assigned_access) LIKE '%admin%' OR assigned_access = 'Systems Admin'";
$result = $db->query($query);
if ($result && $result->num_rows > 0) {
    echo "Admin users found:\n";
    while ($row = $result->fetch_assoc()) {
        echo "  - Staff ID: " . $row['staff_id'] . " | UserID: " . $row['UserID'] . " | Role: " . $row['assigned_access'] . "\n";
    }
} else {
    echo "⚠️  WARNING: No admin users found!\n";
}

echo "\n4. STAFF TABLE\n";
echo "----------------------------------------\n";
$countResult = $db->query("SELECT COUNT(*) as total FROM staff");
$count = $countResult->fetch_assoc();
echo "Total staff: " . $count['total'] . "\n";

echo "\n5. STUDENTS TABLE\n";
echo "----------------------------------------\n";
$countResult = $db->query("SELECT COUNT(*) as total FROM students");
$count = $countResult->fetch_assoc();
echo "Total students: " . $count['total'] . "\n";

echo "\n6. ROLE MAPPING CHECK\n";
echo "----------------------------------------\n";
echo "Testing role normalization for admin:\n";
$testRoles = ['Systems Admin', 'Admin', 'admin', 'systems admin', 'SYSTEMS ADMIN'];
$map = [
    'systems admin' => 'systems_admin',
    'admin' => 'systems_admin',
];

foreach ($testRoles as $testRole) {
    $roleNorm = strtolower(trim($testRole));
    $roleCanon = $map[$roleNorm] ?? $roleNorm;
    $isAdmin = ($roleCanon === 'systems_admin') ? 'YES' : 'NO';
    echo "  '$testRole' => '$roleCanon' (Admin: $isAdmin)\n";
}

echo "\n7. RECOMMENDATIONS\n";
echo "----------------------------------------\n";
$issues = [];

// Check courses
$countResult = $db->query("SELECT COUNT(*) as total FROM courses");
$count = $countResult->fetch_assoc();
if ($count['total'] == 0) {
    $issues[] = "Add courses to the database";
}

// Check programs
$countResult = $db->query("SELECT COUNT(*) as total FROM programs");
$count = $countResult->fetch_assoc();
if ($count['total'] == 0) {
    $issues[] = "Add programs to the database";
}

// Check admin users
$result = $db->query("SELECT COUNT(*) as total FROM access_right WHERE LOWER(assigned_access) LIKE '%admin%' OR assigned_access = 'Systems Admin'");
$count = $result->fetch_assoc();
if ($count['total'] == 0) {
    $issues[] = "Create at least one admin user in access_right table";
}

if (empty($issues)) {
    echo "✓ Database looks good!\n";
    echo "\nIf admin still can't access courses:\n";
    echo "1. Clear browser cookies\n";
    echo "2. Logout and login again\n";
    echo "3. Check browser console for errors\n";
} else {
    echo "Issues found:\n";
    foreach ($issues as $i => $issue) {
        echo ($i + 1) . ". " . $issue . "\n";
    }
}

echo "\n==========================================\n";
echo "Debug complete!\n";
echo "==========================================\n";
