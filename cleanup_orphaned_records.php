<?php
/**
 * Clean up orphaned database records
 * This script identifies and optionally removes orphaned records
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once 'db/connect.php';

echo "=== ORPHANED RECORDS CLEANUP SCRIPT ===\n\n";

// 1. Check orphaned exam records
echo "1. Checking orphaned exam records...\n";
$result = $db->query("SELECT e.* FROM exams e LEFT JOIN students s ON e.Sid = s.SID WHERE s.SID IS NULL");
if ($result && $result->num_rows > 0) {
    echo "   Found {$result->num_rows} orphaned exam records:\n";
    while ($row = $result->fetch_assoc()) {
        echo "   - Exam ID: {$row['id']}, Sid: {$row['Sid']}, Course: {$row['Course_Code']}\n";
    }
    
    echo "\n   Do you want to delete these orphaned records? (yes/no): ";
    echo "\n   For safety, run this manually or uncomment the delete line below\n";
    // Uncomment to delete:
    // $db->query("DELETE e FROM exams e LEFT JOIN students s ON e.Sid = s.SID WHERE s.SID IS NULL");
    // echo "   ✅ Deleted orphaned exam records\n";
} else {
    echo "   ✅ No orphaned exam records found\n";
}

// 2. Check orphaned semester_assessment records
echo "\n2. Checking orphaned semester_assessment records...\n";
$result = $db->query("SELECT COUNT(*) as count, GROUP_CONCAT(DISTINCT sa.Sid) as sids FROM semester_assessment sa LEFT JOIN students s ON sa.Sid = s.SID WHERE s.SID IS NULL LIMIT 10");
if ($result && $row = $result->fetch_assoc()) {
    if ($row['count'] > 0) {
        echo "   Found {$row['count']} orphaned semester_assessment records\n";
        echo "   Sample Sids: " . substr($row['sids'], 0, 100) . "...\n";
        
        // Show a few examples
        $examples = $db->query("SELECT * FROM semester_assessment sa LEFT JOIN students s ON sa.Sid = s.SID WHERE s.SID IS NULL LIMIT 5");
        if ($examples) {
            echo "   Sample records:\n";
            while ($ex = $examples->fetch_assoc()) {
                echo "     - ID: {$ex['id']}, Sid: {$ex['Sid']}, Course: {$ex['Course_Code']}, Year: {$ex['Year']}\n";
            }
        }
        
        echo "\n   ⚠️  WARNING: This is a large number of orphaned records!\n";
        echo "   These records reference student IDs that don't exist in the students table.\n";
        echo "   Possible causes:\n";
        echo "     - Students were deleted from students table\n";
        echo "     - Data migration issue\n";
        echo "     - Student ID format changed\n\n";
        
        echo "   To delete these orphaned records, uncomment the delete line in this script\n";
        // Uncomment to delete:
        // $db->query("DELETE sa FROM semester_assessment sa LEFT JOIN students s ON sa.Sid = s.SID WHERE s.SID IS NULL");
        // echo "   ✅ Deleted orphaned semester_assessment records\n";
    } else {
        echo "   ✅ No orphaned semester_assessment records found\n";
    }
}

// 3. Check for students in semester_assessment not in exams
echo "\n3. Cross-checking students with assessments but no exams...\n";
$result = $db->query("
    SELECT DISTINCT sa.Sid, s.Fname, s.Lname, COUNT(*) as assessment_count
    FROM semester_assessment sa
    INNER JOIN students s ON sa.Sid = s.SID
    LEFT JOIN exams e ON sa.Sid = e.Sid AND sa.semester = e.semester AND sa.Year = e.Year
    WHERE e.id IS NULL
    GROUP BY sa.Sid
    LIMIT 10
");

if ($result && $result->num_rows > 0) {
    echo "   Found students with assessments but no exam records:\n";
    while ($row = $result->fetch_assoc()) {
        echo "   - Sid: {$row['Sid']}, Name: {$row['Fname']} {$row['Lname']}, Assessments: {$row['assessment_count']}\n";
    }
    echo "   ℹ️  Note: These students have CA marks but no final exam marks\n";
} else {
    echo "   ✅ All students with assessments also have exam records\n";
}

// 4. Check for duplicate records
echo "\n4. Checking for duplicate exam records...\n";
$result = $db->query("
    SELECT Sid, Course_Code, semester, Year, COUNT(*) as count
    FROM exams
    GROUP BY Sid, Course_Code, semester, Year
    HAVING count > 1
");

if ($result && $result->num_rows > 0) {
    echo "   Found duplicate exam records:\n";
    while ($row = $result->fetch_assoc()) {
        echo "   - Sid: {$row['Sid']}, Course: {$row['Course_Code']}, Semester: {$row['semester']}, Year: {$row['Year']}, Count: {$row['count']}\n";
    }
} else {
    echo "   ✅ No duplicate exam records found\n";
}

// 5. Check Course_Code mismatches
echo "\n5. Checking for Course_Code case sensitivity issues...\n";
$result = $db->query("
    SELECT e.Course_Code as exam_code, c.course_code as course_code
    FROM exams e
    LEFT JOIN courses c ON BINARY e.Course_Code = c.course_code
    WHERE c.course_code IS NULL
    LIMIT 5
");

if ($result && $result->num_rows > 0) {
    echo "   Found Course_Code mismatches:\n";
    while ($row = $result->fetch_assoc()) {
        echo "   - Exam Course_Code: '{$row['exam_code']}' not found in courses table\n";
        
        // Try to find similar course codes
        $similar = $db->query("SELECT course_code FROM courses WHERE course_code LIKE '%" . $db->real_escape_string($row['exam_code']) . "%' LIMIT 3");
        if ($similar && $similar->num_rows > 0) {
            echo "     Similar courses: ";
            $codes = [];
            while ($s = $similar->fetch_assoc()) {
                $codes[] = $s['course_code'];
            }
            echo implode(", ", $codes) . "\n";
        }
    }
} else {
    echo "   ✅ All exam Course_Codes match courses table\n";
}

// 6. Suggest fixes
echo "\n=== RECOMMENDED ACTIONS ===\n";
echo "1. Review orphaned records and determine if they should be deleted\n";
echo "2. Add missing exam records for students who have CA marks\n";
echo "3. Ensure consistent Course_Code formatting across tables\n";
echo "4. Run 'add_test_fees.php' or similar to populate test data if needed\n";
echo "\nTo automatically clean orphaned records, edit this file and uncomment the DELETE statements.\n";

echo "\n=== CLEANUP SCRIPT COMPLETE ===\n";
