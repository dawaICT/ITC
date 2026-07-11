<?php
require 'db/connect.php';

echo "=== CHECKING JOIN RELATIONSHIPS ===\n\n";

// Check what course codes exist in program_courses
echo "Course codes in program_courses:\n";
$result = $db->query('SELECT DISTINCT course_code FROM program_courses');
$program_course_codes = [];
while($row = $result->fetch_assoc()) {
    $program_course_codes[] = $row['course_code'];
    echo "- " . $row['course_code'] . "\n";
}

echo "\nCourse codes in courses table:\n";
$result = $db->query('SELECT course_code, course_name FROM courses WHERE course_code IN ("' . implode('","', $program_course_codes) . '")');
while($row = $result->fetch_assoc()) {
    echo "- " . $row['course_code'] . " -> " . $row['course_name'] . "\n";
}

// Test the JOIN query
echo "\n=== TESTING JOIN QUERY ===\n";
$sql = "SELECT pc.id, pc.program_code, pc.course_code, pc.semester, pc.credits,
               p.program_name, c.course_name
        FROM program_courses pc
        LEFT JOIN programs p ON pc.program_code = p.program_code
        LEFT JOIN courses c ON pc.course_code = c.course_code
        LIMIT 5";

$result = $db->query($sql);
if ($result) {
    echo "JOIN results:\n";
    while($row = $result->fetch_assoc()) {
        echo "ID: {$row['id']}, Program: {$row['program_code']} ({$row['program_name']}), Course: {$row['course_code']} ({$row['course_name']}), Semester: {$row['semester']}, Credits: {$row['credits']}\n";
    }
} else {
    echo "JOIN query failed: " . $db->error . "\n";
}
?>
