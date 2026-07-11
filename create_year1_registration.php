<?php
/**
 * Create a Year 1, Semester 1 registration for testing
 */
require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/students/includes/RegistrationDataService.php';

$testStudent = 'test123';
$regDataService = new RegistrationDataService($db);

// Check if already exists
$existing = $regDataService->getLatestSemesterRegistration($testStudent);
if ($existing) {
    echo "Already has registration: Year {$existing['year_of_study']}, Semester {$existing['semester']}\n";
    exit(0);
}

// Create Year 1, Semester 1 registration for BSCS (academic_year = year of study: 1)
$stmt = $db->prepare("INSERT INTO semester_registration (student_id, program_code, semester, year_of_study, academic_year, financial_status) VALUES (?, 'BSCS', '1', '1', '1', 'Pending')");
$stmt->bind_param("s", $testStudent);
$stmt->execute();

echo "Created semester registration for {$testStudent}:\n";
echo "  Year: 1\n";
echo "  Semester: 1\n";
echo "  Program: BSCS\n";

// Verify courses
$courses = $regDataService->getAvailableCourses('BSCS', 1, 1);
echo "\nAvailable courses:\n";
foreach ($courses as $c) {
    echo "  - {$c['course_code']}: {$c['course_name']}\n";
}
