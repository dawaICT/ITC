<?php
/**
 * Quick script to check program codes in various tables
 */
require_once 'db/connect.php';

echo "=== PROGRAM CODES ANALYSIS ===\n\n";

// 0. Show semester_registration structure
echo "0. semester_registration table structure:\n";
$result = $db->query("DESCRIBE semester_registration");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "   - {$row['Field']} ({$row['Type']})\n";
    }
}

// 1. Programs table
echo "1. Programs table:\n";
$result = $db->query("SELECT program_code, program_name FROM programs LIMIT 20");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "   - {$row['program_code']}: {$row['program_name']}\n";
    }
} else {
    echo "   (no programs found)\n";
}

// 2. course_levels program codes
echo "\n2. Distinct program_codes in course_levels:\n";
$result = $db->query("SELECT DISTINCT program_code FROM course_levels");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "   - {$row['program_code']}\n";
    }
} else {
    echo "   (no program codes found)\n";
}

// 3. semester_registration program codes
echo "\n3. Distinct program_codes in semester_registration:\n";
$result = $db->query("SELECT DISTINCT program_code FROM semester_registration");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "   - {$row['program_code']}\n";
    }
} else {
    echo "   (no program codes found)\n";
}

// 4. Check for mismatches
echo "\n4. Mismatches (students registered with program_code not in course_levels):\n";
$result = $db->query("
    SELECT DISTINCT sr.program_code, sr.student_id 
    FROM semester_registration sr
    WHERE sr.program_code NOT IN (SELECT DISTINCT program_code FROM course_levels)
");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "   - Student {$row['student_id']} has program_code '{$row['program_code']}' which has NO courses!\n";
    }
} else {
    echo "   (no mismatches - all good!)\n";
}

// 5. Show course_levels structure
echo "\n5. course_levels table structure:\n";
$result = $db->query("DESCRIBE course_levels");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "   - {$row['Field']} ({$row['Type']})\n";
    }
}

// 6. Sample course_levels data
echo "\n6. Sample course_levels data:\n";
$result = $db->query("SELECT * FROM course_levels LIMIT 10");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "   - " . json_encode($row) . "\n";
    }
} else {
    echo "   (no course_levels data found)\n";
}

// 7. Check BSCS courses specifically
echo "\n7. BSCS courses in course_levels:\n";
$result = $db->query("SELECT year, semester, course_code FROM course_levels WHERE program_code = 'BSCS' ORDER BY year, semester");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "   - Year {$row['year']}, Sem {$row['semester']}: {$row['course_code']}\n";
    }
} else {
    echo "   (no BSCS courses found - need to add some!)\n";
}

// 8. Check test student's semester_registration
echo "\n8. test123 semester registrations:\n";
$result = $db->query("SELECT * FROM semester_registration WHERE student_id = 'test123'");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "   - ID {$row['id']}: program={$row['program_code']}, semester={$row['semester']}, year=" . ($row['year_of_study'] ?? $row['year'] ?? 'N/A') . "\n";
    }
} else {
    echo "   (no registrations found)\n";
}

echo "\n=== END ===\n";
