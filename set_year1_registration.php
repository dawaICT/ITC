<?php
/**
 * Set test student to Year 1, Semester 1 by removing the Year 2 registration
 */
require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/students/includes/RegistrationDataService.php';

// Delete the Year 2 registration so Year 1 becomes the latest
$db->query("DELETE FROM semester_registration WHERE id = 12");
echo "Deleted registration ID 12 (Year 2, Semester 1)\n";

// Verify
$regDataService = new RegistrationDataService($db);
$latestReg = $regDataService->getLatestSemesterRegistration('test123');

echo "\ntest123 latest registration now:\n";
echo "  ID: {$latestReg['id']}\n";
echo "  Year: {$latestReg['year_of_study']}\n";
echo "  Semester: {$latestReg['semester']}\n";
echo "  Program: {$latestReg['program_code']}\n";

echo "\nCourses that will show:\n";
$courses = $regDataService->getAvailableCourses(
    $latestReg['program_code'],
    (int)$latestReg['year_of_study'],
    (int)$latestReg['semester']
);
foreach ($courses as $c) {
    echo "  - {$c['course_code']}: {$c['course_name']}\n";
}
echo "\nTotal: " . count($courses) . " course(s)\n";
