<?php
/**
 * Comprehensive Data Structure Debug Script
 * Checks database structure, integrity, and relationships
 */

echo "=== ITC Portal Data Structure Debug ===\n\n";

// Check 1: Database Connection
echo "1. Database Connection:\n";
try {
require 'db/connect.php';
    echo "   ✓ Connected to: " . $db->host_info . "\n";
    echo "   ✓ Database: " . $db->select_db('wucportal') ? 'wucportal' : 'unknown' . "\n";
    echo "   ✓ MySQL version: " . $db->server_info . "\n";
} catch (Exception $e) {
    echo "   ✗ Connection failed: " . $e->getMessage() . "\n";
    exit;
}

// Check 2: Get All Tables
echo "\n2. Database Tables:\n";
$tables_result = $db->query("SHOW TABLES");
$tables = [];
while ($row = $tables_result->fetch_row()) {
    $tables[] = $row[0];
}
sort($tables);

foreach ($tables as $table) {
    // Get row count for each table
    $count_result = $db->query("SELECT COUNT(*) as count FROM `$table`");
    $count = $count_result->fetch_assoc()['count'];
    echo "   - $table ($count records)\n";
}

// Check 3: Critical Tables Structure
echo "\n3. Critical Tables Structure:\n";
$critical_tables = [
    'staff' => ['staff_id', 'Fname', 'Lname', 'email', 'mobile', 'deptId'],
    'students' => ['SID', 'Fname', 'Lname', 'email', 'mobile', 'program_id'],
    'user_credentials' => ['staff_id', 'pass'],
    'student_login' => ['Sid', 'Password'],
    'access_right' => ['UserID', 'staff_id', 'assigned_access'],
    'departments' => ['DeptID', 'DeptName'],
    'courses' => ['course_id', 'course_code', 'course_name'],
    'security_policies' => ['id', 'min_length', 'require_uppercase', 'require_lowercase', 'require_digit', 'require_special'],
    'password_history' => ['id', 'staff_id', 'password_hash', 'created_at']
];

foreach ($critical_tables as $table => $expected_columns) {
    echo "\n   $table:\n";

    if (!in_array($table, $tables)) {
        echo "      ✗ TABLE MISSING\n";
        continue;
    }

    // Get actual columns
    $columns_result = $db->query("DESCRIBE `$table`");
    $actual_columns = [];
    while ($col = $columns_result->fetch_assoc()) {
        $actual_columns[] = $col['Field'];
    }

    // Check expected columns
    foreach ($expected_columns as $expected_col) {
        if (in_array($expected_col, $actual_columns)) {
            echo "      ✓ $expected_col\n";
        } else {
            echo "      ✗ $expected_col (MISSING)\n";
        }
    }

    // Check for unexpected columns
    $unexpected = array_diff($actual_columns, $expected_columns);
    if (!empty($unexpected)) {
        echo "      ⚠ Unexpected columns: " . implode(', ', $unexpected) . "\n";
    }
}

// Check 4: Data Integrity
echo "\n4. Data Integrity Checks:\n";

// Check staff-departments relationship
echo "\n   Staff-Departments Relationship:\n";
$staff_count = $db->query("SELECT COUNT(*) as count FROM staff")->fetch_assoc()['count'];
$dept_count = $db->query("SELECT COUNT(*) as count FROM departments")->fetch_assoc()['count'];
echo "   - Staff records: $staff_count\n";
echo "   - Department records: $dept_count\n";

// Check for staff without departments
$orphaned_staff = $db->query("
    SELECT COUNT(*) as count FROM staff s
    LEFT JOIN departments d ON s.deptId = d.DeptID
    WHERE d.DeptID IS NULL
")->fetch_assoc()['count'];
echo "   - Staff without departments: $orphaned_staff\n";

// Check credentials-staff relationship
echo "\n   User Credentials Integrity:\n";
$credentials_count = $db->query("SELECT COUNT(*) as count FROM user_credentials")->fetch_assoc()['count'];
$valid_credentials = $db->query("
    SELECT COUNT(*) as count FROM user_credentials uc
    INNER JOIN staff s ON uc.staff_id = s.staff_id
")->fetch_assoc()['count'];
echo "   - Total credentials: $credentials_count\n";
echo "   - Valid credentials (linked to staff): $valid_credentials\n";
echo "   - Orphaned credentials: " . ($credentials_count - $valid_credentials) . "\n";

// Check student login integrity
echo "\n   Student Login Integrity:\n";
$student_login_count = $db->query("SELECT COUNT(*) as count FROM student_login")->fetch_assoc()['count'];
$valid_student_logins = $db->query("
    SELECT COUNT(*) as count FROM student_login sl
    INNER JOIN students s ON sl.Sid = s.SID
")->fetch_assoc()['count'];
echo "   - Total student logins: $student_login_count\n";
echo "   - Valid student logins (linked to students): $valid_student_logins\n";
echo "   - Orphaned student logins: " . ($student_login_count - $valid_student_logins) . "\n";

// Check 5: Permission System
echo "\n5. Permission System:\n";
$permissions_count = $db->query("SELECT COUNT(*) as count FROM role_permissions")->fetch_assoc()['count'];
echo "   - Total permissions: $permissions_count\n";

// Check eLearning permissions
$elearning_perms = $db->query("SELECT permission_name FROM role_permissions WHERE permission_name LIKE 'elearn%'");
echo "   - eLearning permissions:\n";
while ($perm = $elearning_perms->fetch_assoc()) {
    echo "     • " . $perm['permission_name'] . "\n";
}

// Check admin permissions
$admin_perms = $db->query("SELECT permission_name FROM role_permissions WHERE permission_name LIKE 'admin%'");
echo "   - Admin permissions:\n";
while ($perm = $admin_perms->fetch_assoc()) {
    echo "     • " . $perm['permission_name'] . "\n";
}

// Check 6: Security Tables
echo "\n6. Security Tables:\n";

// Security policies
$security_policies = $db->query("SELECT COUNT(*) as count FROM security_policies")->fetch_assoc()['count'];
echo "   - Security policies: $security_policies\n";

if ($security_policies > 0) {
    $policy = $db->query("SELECT * FROM security_policies WHERE id=1")->fetch_assoc();
    echo "   - Current policy: " . $policy['min_length'] . " chars, " . $policy['history_count'] . " history\n";
}

// Password history
$history_count = $db->query("SELECT COUNT(*) as count FROM password_history")->fetch_assoc()['count'];
echo "   - Password history records: $history_count\n";

// Check 7: Course and Program Data
echo "\n7. Academic Structure:\n";

$courses_count = $db->query("SELECT COUNT(*) as count FROM courses")->fetch_assoc()['count'];
echo "   - Courses: $courses_count\n";

$programs_count = $db->query("SELECT COUNT(*) as count FROM programs")->fetch_assoc()['count'];
echo "   - Programs: $programs_count\n";

$departments_count = $db->query("SELECT COUNT(*) as count FROM departments")->fetch_assoc()['count'];
echo "   - Departments: $departments_count\n";

// Check 8: eLearning Tables
echo "\n8. eLearning Structure:\n";

$elearning_tables = ['el_course_modules', 'el_contents', 'el_live_sessions', 'el_quizzes', 'el_attendance'];
foreach ($elearning_tables as $table) {
    if (in_array($table, $tables)) {
        $count = $db->query("SELECT COUNT(*) as count FROM `$table`")->fetch_assoc()['count'];
        echo "   - $table: $count records\n";
    } else {
        echo "   - $table: MISSING\n";
    }
}

// Check 9: Recent Activity
echo "\n9. System Activity:\n";

// Recent logins
$recent_logins = $db->query("SELECT COUNT(*) as count FROM login_audit WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['count'];
echo "   - Logins (last 7 days): $recent_logins\n";

// Recent password changes
$recent_passwords = $db->query("SELECT COUNT(*) as count FROM password_history WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['count'];
echo "   - Password changes (last 7 days): $recent_passwords\n";

// Check 10: Database Size and Performance
echo "\n10. Database Health:\n";

// Get database size
$db_size = $db->query("
    SELECT
        ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb
    FROM information_schema.tables
    WHERE table_schema = 'wucportal'
")->fetch_assoc()['size_mb'];
echo "   - Database size: {$db_size} MB\n";

// Check for any table issues
echo "\n11. Table Issues Check:\n";
foreach ($tables as $table) {
    // Check for auto-increment issues
    $auto_inc_result = $db->query("SHOW TABLE STATUS LIKE '$table'");
    $status = $auto_inc_result->fetch_assoc();

    if ($status['Auto_increment'] === null) {
        echo "   - $table: No auto-increment\n";
    }
}

// Summary
echo "\n=== DEBUG SUMMARY ===\n";
echo "Database connection: ✓ Working\n";
echo "Total tables: " . count($tables) . "\n";
echo "Critical tables status: " . (in_array('staff', $tables) && in_array('students', $tables) && in_array('user_credentials', $tables) ? '✓ All present' : '⚠ Issues found') . "\n";
echo "Security system: " . (in_array('security_policies', $tables) && in_array('password_history', $tables) ? '✓ Working' : '⚠ Issues found') . "\n";
echo "eLearning system: " . (in_array('el_course_modules', $tables) ? '✓ Working' : '⚠ Issues found') . "\n";
echo "Data integrity: " . ($orphaned_staff == 0 ? '✓ Good' : '⚠ Issues found') . "\n";

echo "\n=== RECOMMENDATIONS ===\n";
if (!in_array('security_policies', $tables)) {
    echo "• Run security table creation script\n";
}
if ($orphaned_staff > 0) {
    echo "• Fix orphaned staff records\n";
}
if ($credentials_count > $valid_credentials) {
    echo "• Clean up orphaned credentials\n";
}

echo "\nDebug complete! 🎯\n";
?>
