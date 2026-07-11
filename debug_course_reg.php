<?php
/**
 * Debug script for courseReg.php issues
 */
require_once __DIR__ . '/db/connect.php';

echo "=== DEBUG: Course Registration Issues ===\n\n";

// 1. Check semester_registration data
echo "1. SEMESTER_REGISTRATION (latest 5):\n";
$res = $db->query("SELECT id, student_id, program_code, semester, year_of_study, academic_year, created_at FROM semester_registration ORDER BY id DESC LIMIT 5");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        echo "   ID={$r['id']}, SID={$r['student_id']}, Prog={$r['program_code']}, Sem={$r['semester']}, YOS={$r['year_of_study']}, AY={$r['academic_year']}\n";
    }
    $res->free();
} else {
    echo "   ERROR: " . $db->error . "\n";
}

// 2. Check course_levels data
echo "\n2. COURSE_LEVELS sample:\n";
$res = $db->query("SELECT * FROM course_levels LIMIT 10");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        echo "   " . json_encode($r) . "\n";
    }
    $res->free();
} else {
    echo "   ERROR: " . $db->error . "\n";
}

// 3. Check column names in semester_registration
echo "\n3. SEMESTER_REGISTRATION columns:\n";
$res = $db->query("SHOW COLUMNS FROM semester_registration");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        echo "   {$r['Field']} ({$r['Type']})\n";
    }
    $res->free();
}

// 4. Check column names in course_levels
echo "\n4. COURSE_LEVELS columns:\n";
$res = $db->query("SHOW COLUMNS FROM course_levels");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        echo "   {$r['Field']} ({$r['Type']})\n";
    }
    $res->free();
}

// 5. Test specific query used in courseReg.php
echo "\n5. Test courseReg.php query logic:\n";
// Simulate getting latest registration for a test student
$testSid = null;
$res = $db->query("SELECT student_id FROM semester_registration ORDER BY id DESC LIMIT 1");
if ($res && $r = $res->fetch_assoc()) {
    $testSid = $r['student_id'];
    $res->free();
}

if ($testSid) {
    echo "   Testing with student: $testSid\n";
    
    // Get their semester registration
    $sql = "SELECT semester, year_of_study AS Year, program_code FROM semester_registration WHERE student_id='" . $db->real_escape_string($testSid) . "' ORDER BY id DESC LIMIT 1";
    echo "   Query: $sql\n";
    $res = $db->query($sql);
    if ($res && $r = $res->fetch_assoc()) {
        $semester = $r['semester'];
        $Year = $r['Year'];
        $program = $r['program_code'];
        echo "   Result: Semester=$semester, Year=$Year, Program=$program\n";
        
        // Now try to get courses
        echo "\n   Looking for courses with: program=$program, semester=$semester, year=$Year\n";
        
        // Check what's in course_levels for this combo
        $sql2 = "SELECT COUNT(*) as cnt FROM course_levels WHERE program_code='" . $db->real_escape_string($program) . "' AND semester='" . $db->real_escape_string($semester) . "' AND year='" . $db->real_escape_string($Year) . "'";
        echo "   Query: $sql2\n";
        $res2 = $db->query($sql2);
        if ($res2 && $r2 = $res2->fetch_assoc()) {
            echo "   Found: {$r2['cnt']} courses\n";
        }
        
        // Check what programs/semesters/years exist in course_levels
        echo "\n   Available combinations in course_levels:\n";
        $res3 = $db->query("SELECT DISTINCT program_code, semester, year FROM course_levels ORDER BY program_code, year, semester LIMIT 20");
        if ($res3) {
            while ($r3 = $res3->fetch_assoc()) {
                echo "      Prog={$r3['program_code']}, Sem={$r3['semester']}, Year={$r3['year']}\n";
            }
            $res3->free();
        }
        
        $res->free();
    } else {
        echo "   No semester registration found for student\n";
    }
} else {
    echo "   No students in semester_registration table\n";
}

echo "\nDone.\n";
