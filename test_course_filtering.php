<?php
/**
 * Test that courses filter correctly by year and semester
 */
require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/students/includes/RegistrationDataService.php';

$regDataService = new RegistrationDataService($db);

echo "=== Test: Course Filtering by Year/Semester ===\n\n";

// Show all BSCS courses in course_levels
echo "1. All BSCS courses in course_levels:\n";
$result = $db->query("SELECT year, semester, course_code FROM course_levels WHERE program_code = 'BSCS' ORDER BY year, semester");
while ($row = $result->fetch_assoc()) {
    echo "   Year {$row['year']}, Sem {$row['semester']}: {$row['course_code']}\n";
}

// Test Year 1 Semester 1
echo "\n2. getAvailableCourses('BSCS', 1, 1) - Year 1, Semester 1:\n";
$courses = $regDataService->getAvailableCourses('BSCS', 1, 1);
if (count($courses) > 0) {
    foreach ($courses as $c) {
        echo "   - {$c['course_code']}: {$c['course_name']}\n";
    }
} else {
    echo "   (no courses found)\n";
}

// Test Year 2 Semester 1
echo "\n3. getAvailableCourses('BSCS', 2, 1) - Year 2, Semester 1:\n";
$courses = $regDataService->getAvailableCourses('BSCS', 2, 1);
if (count($courses) > 0) {
    foreach ($courses as $c) {
        echo "   - {$c['course_code']}: {$c['course_name']}\n";
    }
} else {
    echo "   (no courses found)\n";
}

// Now check what ID 3 registration shows (Year 1, Semester 1)
echo "\n4. Checking registration ID 3 (Year 1, Semester 1):\n";
$result = $db->query("SELECT * FROM semester_registration WHERE id = 3");
$reg3 = $result->fetch_assoc();
echo "   Student: {$reg3['student_id']}, Program: {$reg3['program_code']}, Year: {$reg3['year_of_study']}, Semester: {$reg3['semester']}\n";

// Get courses for this registration
echo "\n5. Courses for Year 1, Semester 1 registration:\n";
$courses = $regDataService->getAvailableCourses(
    $reg3['program_code'],
    (int)$reg3['year_of_study'],
    (int)$reg3['semester']
);
if (count($courses) > 0) {
    foreach ($courses as $c) {
        echo "   - {$c['course_code']}: {$c['course_name']} ({$c['credit_hours']} credit hours)\n";
    }
    echo "   Total: " . count($courses) . " course(s)\n";
} else {
    echo "   (no courses found)\n";
}

// Check what ID 12 registration shows (Year 2, Semester 1)
echo "\n6. Checking registration ID 12 (Year 2, Semester 1):\n";
$result = $db->query("SELECT * FROM semester_registration WHERE id = 12");
$reg12 = $result->fetch_assoc();
echo "   Student: {$reg12['student_id']}, Program: {$reg12['program_code']}, Year: {$reg12['year_of_study']}, Semester: {$reg12['semester']}\n";

// Get courses for this registration
echo "\n7. Courses for Year 2, Semester 1 registration:\n";
$courses = $regDataService->getAvailableCourses(
    $reg12['program_code'],
    (int)$reg12['year_of_study'],
    (int)$reg12['semester']
);
if (count($courses) > 0) {
    foreach ($courses as $c) {
        echo "   - {$c['course_code']}: {$c['course_name']} ({$c['credit_hours']} credit hours)\n";
    }
    echo "   Total: " . count($courses) . " course(s)\n";
} else {
    echo "   (no courses found)\n";
}

echo "\n=== Done ===\n";
