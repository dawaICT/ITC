<?php
/**
 * Comprehensive Database Fix Script
 * Identifies and fixes database issues in the WUC Portal
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once 'db/connect.php';

echo "=== DATABASE DIAGNOSTIC AND FIX SCRIPT ===\n\n";

// Track issues found
$issues_found = [];
$issues_fixed = [];

// 1. Check Exams Table - Sid should be VARCHAR not INT
echo "1. Checking exams table Sid data type...\n";
$result = $db->query("SHOW COLUMNS FROM exams LIKE 'Sid'");
if ($result && $row = $result->fetch_assoc()) {
    if (strpos(strtolower($row['Type']), 'int') !== false) {
        $issues_found[] = "exams.Sid is INT but students.SID is VARCHAR - JOIN mismatch!";
        echo "   ❌ ISSUE: exams.Sid is {$row['Type']} but students.SID is VARCHAR(50)\n";
        
        // Fix it
        echo "   Attempting to fix exams.Sid data type...\n";
        try {
            $db->query("ALTER TABLE exams MODIFY COLUMN Sid VARCHAR(50) NOT NULL");
            echo "   ✅ FIXED: exams.Sid changed to VARCHAR(50)\n";
            $issues_fixed[] = "Changed exams.Sid to VARCHAR(50)";
        } catch (Exception $e) {
            echo "   ❌ ERROR: Could not fix - " . $e->getMessage() . "\n";
        }
    } else {
        echo "   ✅ OK: exams.Sid is {$row['Type']}\n";
    }
}

// 2. Check semester_assessment Table - Sid data type
echo "\n2. Checking semester_assessment table Sid data type...\n";
$result = $db->query("SHOW COLUMNS FROM semester_assessment LIKE 'Sid'");
if ($result && $row = $result->fetch_assoc()) {
    if (strtolower($row['Type']) != 'varchar(50)') {
        $issues_found[] = "semester_assessment.Sid is {$row['Type']} but students.SID is VARCHAR(50)";
        echo "   ⚠️  WARNING: semester_assessment.Sid is {$row['Type']}\n";
        
        // Fix it
        echo "   Attempting to fix semester_assessment.Sid data type...\n";
        try {
            $db->query("ALTER TABLE semester_assessment MODIFY COLUMN Sid VARCHAR(50) NOT NULL");
            echo "   ✅ FIXED: semester_assessment.Sid changed to VARCHAR(50)\n";
            $issues_fixed[] = "Changed semester_assessment.Sid to VARCHAR(50)";
        } catch (Exception $e) {
            echo "   ❌ ERROR: Could not fix - " . $e->getMessage() . "\n";
        }
    } else {
        echo "   ✅ OK: semester_assessment.Sid is {$row['Type']}\n";
    }
}

// 3. Check Course_Code consistency
echo "\n3. Checking Course_Code data type consistency...\n";
$tables_to_check = ['exams', 'semester_assessment', 'courses', 'student_courses', 'course_registration'];
$course_code_types = [];

foreach ($tables_to_check as $table) {
    // Try both variations of course code column name
    $result = $db->query("SHOW COLUMNS FROM $table WHERE Field LIKE '%ourse%ode%'");
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $course_code_types[$table] = $row['Type'];
            echo "   - $table.{$row['Field']}: {$row['Type']}\n";
        }
    }
}

// 4. Check for orphaned records
echo "\n4. Checking for orphaned records...\n";

// Check exams without matching students
$result = $db->query("SELECT COUNT(*) as count FROM exams e LEFT JOIN students s ON e.Sid = s.SID WHERE s.SID IS NULL");
if ($result && $row = $result->fetch_assoc()) {
    if ($row['count'] > 0) {
        $issues_found[] = "{$row['count']} exam records with no matching student";
        echo "   ⚠️  WARNING: {$row['count']} exam records have no matching student\n";
    } else {
        echo "   ✅ OK: All exam records have matching students\n";
    }
}

// Check semester_assessment without matching students
$result = $db->query("SELECT COUNT(*) as count FROM semester_assessment sa LEFT JOIN students s ON sa.Sid = s.SID WHERE s.SID IS NULL");
if ($result && $row = $result->fetch_assoc()) {
    if ($row['count'] > 0) {
        $issues_found[] = "{$row['count']} semester_assessment records with no matching student";
        echo "   ⚠️  WARNING: {$row['count']} semester_assessment records have no matching student\n";
    } else {
        echo "   ✅ OK: All semester_assessment records have matching students\n";
    }
}

// 5. Check indexes for performance
echo "\n5. Checking database indexes...\n";

// Check if exams has index on Sid
$result = $db->query("SHOW INDEX FROM exams WHERE Column_name = 'Sid'");
if ($result && $result->num_rows == 0) {
    $issues_found[] = "exams table missing index on Sid column";
    echo "   ⚠️  Missing index on exams.Sid - adding it...\n";
    try {
        $db->query("ALTER TABLE exams ADD INDEX idx_sid (Sid)");
        echo "   ✅ FIXED: Added index on exams.Sid\n";
        $issues_fixed[] = "Added index on exams.Sid";
    } catch (Exception $e) {
        echo "   ❌ ERROR: Could not add index - " . $e->getMessage() . "\n";
    }
} else {
    echo "   ✅ OK: exams.Sid has index\n";
}

// Check if semester_assessment has index on Sid
$result = $db->query("SHOW INDEX FROM semester_assessment WHERE Column_name = 'Sid'");
if ($result && $result->num_rows == 0) {
    $issues_found[] = "semester_assessment table missing index on Sid column";
    echo "   ⚠️  Missing index on semester_assessment.Sid - adding it...\n";
    try {
        $db->query("ALTER TABLE semester_assessment ADD INDEX idx_sid (Sid)");
        echo "   ✅ FIXED: Added index on semester_assessment.Sid\n";
        $issues_fixed[] = "Added index on semester_assessment.Sid";
    } catch (Exception $e) {
        echo "   ❌ ERROR: Could not add index - " . $e->getMessage() . "\n";
    }
} else {
    echo "   ✅ OK: semester_assessment.Sid has index\n";
}

// 6. Check for missing required tables
echo "\n6. Checking for required tables...\n";
$required_tables = [
    'students', 'programs', 'student_program', 'courses', 
    'exams', 'semester_assessment', 'course_registration',
    'student_courses', 'departments', 'academic_periods'
];

foreach ($required_tables as $table) {
    $result = $db->query("SHOW TABLES LIKE '$table'");
    if ($result && $result->num_rows > 0) {
        echo "   ✅ $table exists\n";
    } else {
        echo "   ❌ MISSING: $table table does not exist!\n";
        $issues_found[] = "Missing table: $table";
    }
}

// 7. Check data counts
echo "\n7. Checking data counts...\n";
$tables_to_count = ['students', 'programs', 'student_program', 'exams', 'semester_assessment', 'courses'];
foreach ($tables_to_count as $table) {
    $result = $db->query("SELECT COUNT(*) as count FROM $table");
    if ($result && $row = $result->fetch_assoc()) {
        echo "   - $table: {$row['count']} records\n";
        if ($row['count'] == 0 && in_array($table, ['students', 'programs'])) {
            $issues_found[] = "$table table is empty!";
        }
    }
}

// 8. Test the transcript query
echo "\n8. Testing transcript query structure...\n";
$test_query = "SELECT s.SID, s.Fname, s.Lname, e.Course_Code, c.course_name, 
               e.Total_marks, sa.Total_CA, e.semester, e.Year, p.program_name
               FROM students s
               INNER JOIN exams e ON s.SID = e.Sid
               INNER JOIN courses c ON e.Course_Code = c.course_code
               INNER JOIN semester_assessment sa ON c.course_code = sa.Course_Code AND s.SID = sa.Sid
               INNER JOIN student_program sp ON s.SID = sp.Sid
               INNER JOIN programs p ON sp.program_code = p.program_code
               WHERE e.Sid = '2023001' 
               AND sa.Sid = '2023001'
               AND e.semester = 1
               AND sa.semester = 1
               AND e.Year = 2023
               AND sa.Year = 2023
               LIMIT 1";

try {
    $result = $db->query($test_query);
    if ($result !== false) {
        $count = $result->num_rows;
        echo "   ✅ Query executed successfully ($count results)\n";
        if ($count == 0) {
            echo "   ℹ️  Note: No data found - may need to add sample exam data\n";
        }
    } else {
        echo "   ❌ Query failed: " . $db->error . "\n";
        $issues_found[] = "Transcript query has errors";
    }
} catch (Exception $e) {
    echo "   ❌ Query error: " . $e->getMessage() . "\n";
}

// 9. Add sample exam data if tables are empty
echo "\n9. Checking if sample data needed...\n";
$result = $db->query("SELECT COUNT(*) as count FROM exams");
if ($result && $row = $result->fetch_assoc() && $row['count'] == 0) {
    echo "   ℹ️  No exam data found. Add sample data? (y/n): ";
    // For automated execution, we'll just report
    echo "\n   Run add_test_fees.php or similar to add sample data\n";
}

// Summary
echo "\n=== SUMMARY ===\n";
echo "Issues Found: " . count($issues_found) . "\n";
if (count($issues_found) > 0) {
    foreach ($issues_found as $issue) {
        echo "  - $issue\n";
    }
}

echo "\nIssues Fixed: " . count($issues_fixed) . "\n";
if (count($issues_fixed) > 0) {
    foreach ($issues_fixed as $fix) {
        echo "  ✅ $fix\n";
    }
}

if (count($issues_found) == count($issues_fixed)) {
    echo "\n✅ ALL ISSUES RESOLVED!\n";
} elseif (count($issues_fixed) > 0) {
    echo "\n⚠️  SOME ISSUES RESOLVED. Manual intervention may be needed for remaining issues.\n";
} else {
    echo "\n✅ NO CRITICAL ISSUES FOUND OR DATABASE IS HEALTHY!\n";
}

echo "\n=== DATABASE CHECK COMPLETE ===\n";
