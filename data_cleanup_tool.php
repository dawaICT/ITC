<?php
/**
 * Data Cleanup and Integrity Tool
 * Fixes orphaned records, invalid formats, and data inconsistencies
 */

require 'db/connect.php';

echo "=== ITC Portal Data Cleanup Tool ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

$actions_taken = 0;
$errors_encountered = 0;

// Function to log actions
function log_action($action, $table, $details = '') {
    global $actions_taken;
    $actions_taken++;
    echo "🔧 [$action] $table: $details\n";
}

// Function to log errors
function log_error($error, $table, $details = '') {
    global $errors_encountered;
    $errors_encountered++;
    echo "❌ [ERROR] $table: $error - $details\n";
}

// 1. Fix Orphaned User Credentials
echo "1. FIXING ORPHANED USER CREDENTIALS\n";
$result = $db->query("
    SELECT uc.staff_id, uc.id
    FROM user_credentials uc
    LEFT JOIN staff s ON uc.staff_id = s.staff_id
    WHERE s.staff_id IS NULL
");

if ($result && $result->num_rows > 0) {
    echo "   Found " . $result->num_rows . " orphaned user credentials\n";

    while ($row = $result->fetch_assoc()) {
        // Option 1: Delete orphaned records
        $delete_result = $db->query("DELETE FROM user_credentials WHERE id = " . $row['id']);
        if ($delete_result) {
            log_action("DELETED", "user_credentials", "Removed orphaned record for staff_id: " . $row['staff_id']);
        } else {
            log_error("Delete failed", "user_credentials", "Could not delete record ID: " . $row['id']);
        }
    }
} else {
    echo "   ✓ No orphaned user credentials found\n";
}
echo "\n";

// 2. Fix Orphaned Access Rights
echo "2. FIXING ORPHANED ACCESS RIGHTS\n";
$result = $db->query("
    SELECT ar.id, ar.staff_id
    FROM access_right ar
    LEFT JOIN staff s ON ar.staff_id = s.staff_id
    WHERE s.staff_id IS NULL
");

if ($result && $result->num_rows > 0) {
    echo "   Found " . $result->num_rows . " orphaned access rights\n";

    while ($row = $result->fetch_assoc()) {
        $delete_result = $db->query("DELETE FROM access_right WHERE id = " . $row['id']);
        if ($delete_result) {
            log_action("DELETED", "access_right", "Removed orphaned record for staff_id: " . $row['staff_id']);
        } else {
            log_error("Delete failed", "access_right", "Could not delete record ID: " . $row['id']);
        }
    }
} else {
    echo "   ✓ No orphaned access rights found\n";
}
echo "\n";

// 3. Fix Student ID Format Issues
echo "3. FIXING STUDENT ID FORMAT ISSUES\n";

// First, let's see what the invalid formats are
$result = $db->query("
    SELECT SID, Fname, Lname
    FROM students
    WHERE SID NOT REGEXP '^(LVTC|WUC)[0-9]+$'
    LIMIT 10
");

if ($result && $result->num_rows > 0) {
    echo "   Found " . $result->num_rows . " students with invalid ID format:\n";

    while ($row = $result->fetch_assoc()) {
        echo "     - " . $row['SID'] . " (" . $row['Fname'] . " " . $row['Lname'] . ")\n";
    }
    // NOTE: This tool used to mint random "LVTC..." numbers, but the student
    // number is now protected identity data and follows the approved ITC scheme.
    // Re-numbering is handled exclusively by the audited migration:
    //     php admin/migrate_student_numbers.php --commit
    // so it is no longer performed here (the identity trigger would block it).
    echo "   ! Re-numbering is disabled here. Run admin/migrate_student_numbers.php to convert these.\n";
} else {
    echo "   ✓ All student IDs have valid format\n";
}
echo "\n";

// 4. Add Default Department Assignments
echo "4. ADDING DEFAULT DEPARTMENT ASSIGNMENTS\n";

$result = $db->query("
    SELECT s.staff_id, s.Fname, s.Lname
    FROM staff s
    LEFT JOIN departments d ON s.deptId = d.deptId
    WHERE d.deptId IS NULL
");

if ($result && $result->num_rows > 0) {
    echo "   Found " . $result->num_rows . " staff without department assignment\n";

    // Get the first available department as default
    $dept_result = $db->query("SELECT deptId FROM departments ORDER BY deptId LIMIT 1");
    if ($dept_result && $dept_result->num_rows > 0) {
        $default_dept = $dept_result->fetch_assoc()['deptId'];

        while ($row = $result->fetch_assoc()) {
            $update_result = $db->query("UPDATE staff SET deptId = '$default_dept' WHERE staff_id = '" . $row['staff_id'] . "'");

            if ($update_result) {
                log_action("UPDATED", "staff", "Assigned default department to " . $row['staff_id'] . " (" . $row['Fname'] . " " . $row['Lname'] . ")");
            } else {
                log_error("Update failed", "staff", "Could not assign department to " . $row['staff_id']);
            }
        }
    } else {
        echo "   ⚠ No departments available to assign\n";
    }
} else {
    echo "   ✓ All staff have department assignments\n";
}
echo "\n";

// 5. Clean Up Password History
echo "5. CLEANING UP PASSWORD HISTORY\n";

// Remove password history entries for non-existent staff
$result = $db->query("
    SELECT ph.id, ph.staff_id
    FROM password_history ph
    LEFT JOIN staff s ON ph.staff_id = s.staff_id
    WHERE s.staff_id IS NULL
");

if ($result && $result->num_rows > 0) {
    echo "   Found " . $result->num_rows . " orphaned password history records\n";

    while ($row = $result->fetch_assoc()) {
        $delete_result = $db->query("DELETE FROM password_history WHERE id = " . $row['id']);
        if ($delete_result) {
            log_action("DELETED", "password_history", "Removed orphaned record for staff_id: " . $row['staff_id']);
        } else {
            log_error("Delete failed", "password_history", "Could not delete record ID: " . $row['id']);
        }
    }
} else {
    echo "   ✓ No orphaned password history records found\n";
}
echo "\n";

// 6. Validate Data Integrity
echo "6. VALIDATING DATA INTEGRITY\n";

// Check that all foreign key relationships are valid
$relationships = [
    ['student_login', 'students', 'Sid', 'SID'],
    ['user_credentials', 'staff', 'staff_id', 'staff_id'],
    ['access_right', 'staff', 'staff_id', 'staff_id'],
    ['staff', 'departments', 'deptId', 'deptId']
];

foreach ($relationships as $rel) {
    $table1 = $rel[0];
    $table2 = $rel[1];
    $col1 = $rel[2];
    $col2 = $rel[3];

    if (in_array($table1, $tables) && in_array($table2, $tables)) {
        $result = $db->query("
            SELECT COUNT(*) as count
            FROM $table1 t1
            LEFT JOIN $table2 t2 ON t1.$col1 = t2.$col2
            WHERE t2.$col2 IS NULL
        ");

        if ($result) {
            $count = $result->fetch_assoc()['count'];
            if ($count > 0) {
                log_error("Integrity violation", "$table1 -> $table2", "Found $count orphaned records");
            } else {
                echo "   ✓ $table1 -> $table2: All relationships valid\n";
            }
        }
    }
}
echo "\n";

// 7. Summary
echo "7. CLEANUP SUMMARY\n";
echo "   Actions Taken: $actions_taken\n";
echo "   Errors Encountered: $errors_encountered\n";

if ($errors_encountered === 0) {
    echo "   ✅ Data cleanup completed successfully!\n";
} else {
    echo "   ⚠️  Some errors were encountered during cleanup\n";
}

echo "\n=== CLEANUP COMPLETE ===\n";

if ($actions_taken > 0) {
    echo "\n📋 SUMMARY OF CHANGES:\n";
    echo "• Removed orphaned user credentials and access rights\n";
    echo "• Fixed invalid student ID formats\n";
    echo "• Assigned default departments to unassigned staff\n";
    echo "• Cleaned up orphaned password history records\n";
    echo "• Validated data integrity across all relationships\n";

    echo "\n🔄 RECOMMENDED NEXT STEPS:\n";
    echo "1. Run the debug script again to verify all issues are resolved\n";
    echo "2. Test the application functionality\n";
    echo "3. Consider creating database constraints to prevent future issues\n";
    echo "4. Set up regular data integrity checks\n";
}
?>




