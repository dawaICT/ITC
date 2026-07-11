<?php
/**
 * Simple script to show current lecturer-course assignments
 * and provide SQL commands to add more
 */

require_once __DIR__ . '/db/connect.php';

echo "===================================================\n";
echo "Lecturer Course Assignments - Current Status\n";
echo "===================================================\n\n";

// Check if lecturer_courses table has any data
$count = $db->query("SELECT COUNT(*) as total FROM lecturer_courses")->fetch_assoc()['total'];

echo "Current Assignments: $count\n\n";

if ($count > 0) {
    echo "Existing Assignments:\n";
    echo str_repeat("-", 70) . "\n";
    $result = $db->query("SELECT lecturer_id, course_code, assigned_date 
                          FROM lecturer_courses 
                          ORDER BY lecturer_id, course_code");
    while ($row = $result->fetch_assoc()) {
        echo sprintf("%-15s | %-15s | %s\n", 
            $row['lecturer_id'], 
            $row['course_code'], 
            $row['assigned_date']
        );
    }
    echo "\n";
}

// Show available staff
echo "Available Staff:\n";
echo str_repeat("-", 70) . "\n";
$staff = $db->query("SELECT staff_id, CONCAT(Fname, ' ', Lname) as name, title, email 
                     FROM staff 
                     ORDER BY Lname 
                     LIMIT 10");
while ($row = $staff->fetch_assoc()) {
    echo sprintf("%-15s | %-25s | %s\n", 
        $row['staff_id'], 
        $row['name'], 
        $row['title']
    );
}

// Show available courses
echo "\nAvailable Courses:\n";
echo str_repeat("-", 70) . "\n";
$courses = $db->query("SELECT course_code, course_name, status 
                       FROM courses 
                       WHERE status = 'active' 
                       ORDER BY course_code 
                       LIMIT 10");
while ($row = $courses->fetch_assoc()) {
    echo sprintf("%-15s | %-40s | %s\n", 
        $row['course_code'], 
        $row['course_name'], 
        $row['status']
    );
}

// Provide example SQL commands
echo "\n" . str_repeat("=", 70) . "\n";
echo "To assign courses to lecturers, run SQL commands like:\n\n";
echo "INSERT INTO lecturer_courses (lecturer_id, course_code) \n";
echo "VALUES ('STAFF_ID', 'COURSE_CODE');\n\n";
echo "Example:\n";
echo "INSERT INTO lecturer_courses (lecturer_id, course_code) \n";
echo "VALUES ('S001', 'CS101');\n\n";
echo "Or to assign all courses to a lecturer (for testing):\n";
echo "INSERT INTO lecturer_courses (lecturer_id, course_code)\n";
echo "SELECT 'S001', course_code FROM courses WHERE status = 'active';\n";
echo str_repeat("=", 70) . "\n";
