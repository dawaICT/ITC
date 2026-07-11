<?php
/**
 * Helper script to assign lecturers to courses
 * This populates the lecturer_courses table for authorization
 */

require_once __DIR__ . '/db/connect.php';

echo "===================================================\n";
echo "Lecturer Course Assignment Tool\n";
echo "===================================================\n\n";

// Get all staff members (for manual assignment selection)
$lecturersQuery = "SELECT staff_id, Fname as firstname, Lname as lastname, email, title 
                   FROM staff 
                   ORDER BY Lname, Fname";
$lecturers = [];
$result = $db->query($lecturersQuery);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $lecturers[] = $row;
    }
}

// Get all active courses
$coursesQuery = "SELECT course_code, course_name, credits 
                 FROM courses 
                 WHERE status = 'active' 
                 ORDER BY course_code";
$courses = [];
$result = $db->query($coursesQuery);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
}

// Get existing assignments
$assignmentsQuery = "SELECT lc.lecturer_id, lc.course_code, 
                     s.Fname as firstname, s.Lname as lastname, c.course_name
                     FROM lecturer_courses lc
                     LEFT JOIN staff s ON lc.lecturer_id COLLATE utf8mb4_general_ci = s.staff_id COLLATE utf8mb4_general_ci
                     LEFT JOIN courses c ON lc.course_code COLLATE utf8mb4_general_ci = c.course_code COLLATE utf8mb4_general_ci
                     ORDER BY s.Lname, lc.course_code";
$assignments = [];
$result = $db->query($assignmentsQuery);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $assignments[] = $row;
    }
}

echo "Found " . count($lecturers) . " lecturers\n";
echo "Found " . count($courses) . " active courses\n";
echo "Found " . count($assignments) . " existing assignments\n\n";

if (empty($lecturers)) {
    echo "⚠ WARNING: No lecturers found in the system!\n";
    echo "Make sure staff records have role='lecturer'\n";
    exit(1);
}

if (empty($courses)) {
    echo "⚠ WARNING: No active courses found!\n";
    exit(1);
}

// Display current assignments
if (!empty($assignments)) {
    echo "===================================================\n";
    echo "Current Lecturer-Course Assignments:\n";
    echo "===================================================\n";
    foreach ($assignments as $a) {
        echo sprintf("%-15s %-25s -> %s (%s)\n", 
            $a['lecturer_id'], 
            $a['lastname'] . ', ' . $a['firstname'],
            $a['course_code'],
            $a['course_name'] ?? 'Unknown'
        );
    }
    echo "\n";
}

// Example: Auto-assign first lecturer to all courses (for testing)
if (isset($argv[1]) && $argv[1] === '--auto-assign-test') {
    echo "===================================================\n";
    echo "Auto-assigning first lecturer to all courses (TEST MODE)\n";
    echo "===================================================\n";
    
    if (empty($lecturers)) {
        echo "No lecturers to assign!\n";
        exit(1);
    }
    
    $testLecturer = $lecturers[0];
    $stmt = $db->prepare("INSERT IGNORE INTO lecturer_courses (lecturer_id, course_code) VALUES (?, ?)");
    
    foreach ($courses as $course) {
        $stmt->bind_param('ss', $testLecturer['staff_id'], $course['course_code']);
        if ($stmt->execute()) {
            echo "✓ Assigned {$testLecturer['staff_id']} to {$course['course_code']}\n";
        }
    }
    
    echo "\nTest assignments complete!\n";
    exit(0);
}

// Manual assignment instructions
echo "===================================================\n";
echo "To assign lecturers to courses:\n";
echo "===================================================\n";
echo "\nOption 1: Run SQL directly\n";
echo "----------\n";
echo "INSERT INTO lecturer_courses (lecturer_id, course_code) VALUES\n";
if (!empty($lecturers) && !empty($courses)) {
    $lecturer = $lecturers[0];
    $course = $courses[0];
    echo "  ('{$lecturer['staff_id']}', '{$course['course_code']}'),  -- {$lecturer['lastname']} -> {$course['course_name']}\n";
    if (count($lecturers) > 1 && count($courses) > 1) {
        $lecturer2 = $lecturers[1];
        $course2 = $courses[1];
        echo "  ('{$lecturer2['staff_id']}', '{$course2['course_code']}')   -- {$lecturer2['lastname']} -> {$course2['course_name']}\n";
    }
}
echo "ON DUPLICATE KEY UPDATE assigned_date=NOW();\n\n";

echo "Option 2: Use this script in test mode\n";
echo "----------\n";
echo "php " . basename(__FILE__) . " --auto-assign-test\n";
echo "(This will assign the first lecturer to ALL courses for testing)\n\n";

echo "Option 3: Use phpMyAdmin or similar tool\n";
echo "----------\n";
echo "Manually insert records into the lecturer_courses table\n\n";

echo "Available Lecturers:\n";
foreach (array_slice($lecturers, 0, 10) as $lec) {
    echo "  {$lec['staff_id']} - {$lec['lastname']}, {$lec['firstname']}\n";
}
if (count($lecturers) > 10) {
    echo "  ... and " . (count($lecturers) - 10) . " more\n";
}

echo "\nAvailable Courses:\n";
foreach (array_slice($courses, 0, 10) as $course) {
    echo "  {$course['course_code']} - {$course['course_name']}\n";
}
if (count($courses) > 10) {
    echo "  ... and " . (count($courses) - 10) . " more\n";
}

echo "\n===================================================\n";
