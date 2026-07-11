<?php
/**
 * Add sample courses for BSCS Year 2
 * This fixes the issue where students registered for year 2 have no courses to select
 */

require_once __DIR__ . '/db/connect.php';

echo "=== Adding BSCS Year 2 Courses ===\n\n";

// First, check what courses exist
echo "Existing courses in 'courses' table:\n";
$result = $db->query("SELECT course_code, course_name FROM courses LIMIT 20");
$existingCourses = [];
while ($row = $result->fetch_assoc()) {
    $existingCourses[$row['course_code']] = $row['course_name'];
    echo "  - {$row['course_code']}: {$row['course_name']}\n";
}

// Define BSCS Year 2 Semester 1 courses (sample CS courses)
$bscsYear2Sem1 = [
    ['code' => 'CSC201', 'name' => 'Data Structures', 'credits' => 3],
    ['code' => 'CSC202', 'name' => 'Object-Oriented Programming', 'credits' => 3],
    ['code' => 'CSC203', 'name' => 'Database Systems', 'credits' => 3],
    ['code' => 'CSC204', 'name' => 'Computer Networks', 'credits' => 3],
];

echo "\n\nAdding BSCS Year 2 Semester 1 courses...\n";

foreach ($bscsYear2Sem1 as $c) {
    // 1. Add to courses table if not exists
    if (!isset($existingCourses[$c['code']])) {
        $stmt = $db->prepare("INSERT INTO courses (course_code, course_name, credit_hours) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $c['code'], $c['name'], $c['credits']);
        if ($stmt->execute()) {
            echo "  Added course: {$c['code']} - {$c['name']}\n";
        } else {
            echo "  Error adding course {$c['code']}: " . $stmt->error . "\n";
        }
        $stmt->close();
    } else {
        echo "  Course exists: {$c['code']}\n";
    }
    
    // 2. Add to course_levels for BSCS, Year 2, Semester 1
    $checkStmt = $db->prepare("SELECT course_level_id FROM course_levels WHERE course_code = ? AND program_code = 'BSCS' AND year = 2 AND semester = 1");
    $checkStmt->bind_param("s", $c['code']);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows == 0) {
        $insertStmt = $db->prepare("INSERT INTO course_levels (course_code, program_code, semester, year) VALUES (?, 'BSCS', 1, 2)");
        $insertStmt->bind_param("s", $c['code']);
        if ($insertStmt->execute()) {
            echo "  Added to course_levels: {$c['code']} -> BSCS/Year 2/Sem 1\n";
        } else {
            echo "  Error adding to course_levels {$c['code']}: " . $insertStmt->error . "\n";
        }
        $insertStmt->close();
    } else {
        echo "  Already in course_levels: {$c['code']} for BSCS/Year 2/Sem 1\n";
    }
    $checkStmt->close();
}

echo "\n=== Done ===\n\n";

// Verify
echo "Verification - BSCS courses in course_levels:\n";
$result = $db->query("SELECT cl.year, cl.semester, cl.course_code, c.course_name 
                      FROM course_levels cl 
                      LEFT JOIN courses c ON cl.course_code = c.course_code 
                      WHERE cl.program_code = 'BSCS' 
                      ORDER BY cl.year, cl.semester, cl.course_code");
while ($row = $result->fetch_assoc()) {
    echo "  - Year {$row['year']}, Sem {$row['semester']}: {$row['course_code']} - {$row['course_name']}\n";
}
