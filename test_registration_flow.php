<?php
/**
 * Test the complete registration flow
 * Simulates: registration.php -> courseReg.php
 */
require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/students/includes/RegistrationDataService.php';

$regDataService = new RegistrationDataService($db);
$testStudent = 'test123';

echo "=== Complete Registration Flow Test ===\n\n";

// Step 1: Check initial state (should be empty after reset)
echo "STEP 1: Check initial state\n";
$latestReg = $regDataService->getLatestSemesterRegistration($testStudent);
if ($latestReg === null) {
    echo "  OK - No existing semester registration\n";
    echo "  -> Student should see 'Complete semester registration' message on registration.php\n";
} else {
    echo "  Has registration: Year {$latestReg['year_of_study']}, Semester {$latestReg['semester']}\n";
}

// Step 2: Simulate semester registration (what registration.php does)
echo "\nSTEP 2: Create semester registration (Year 1, Semester 1, BSCS)\n";
$stmt = $db->prepare("INSERT INTO semester_registration (student_id, program_code, semester, year_of_study, academic_year, financial_status) VALUES (?, 'BSCS', '1', '1', '1', 'Pending')");
$stmt->bind_param("s", $testStudent);
$stmt->execute();
$newId = $db->insert_id;
echo "  Created semester_registration ID: {$newId}\n";

// Step 3: Verify semester registration exists
echo "\nSTEP 3: Verify semester registration\n";
$latestReg = $regDataService->getLatestSemesterRegistration($testStudent);
if ($latestReg) {
    echo "  OK - Registration found:\n";
    echo "    ID: {$latestReg['id']}\n";
    echo "    Year: {$latestReg['year_of_study']}\n";
    echo "    Semester: {$latestReg['semester']}\n";
    echo "    Program: {$latestReg['program_code']}\n";
} else {
    echo "  FAIL - No registration found!\n";
    exit(1);
}

// Step 4: Check available courses (what courseReg.php does)
echo "\nSTEP 4: Get available courses for Year {$latestReg['year_of_study']}, Semester {$latestReg['semester']}\n";
$courses = $regDataService->getAvailableCourses(
    $latestReg['program_code'],
    (int)$latestReg['year_of_study'],
    (int)$latestReg['semester']
);
if (count($courses) > 0) {
    echo "  Found " . count($courses) . " course(s):\n";
    foreach ($courses as $c) {
        echo "    - {$c['course_code']}: {$c['course_name']} ({$c['credit_hours']} credit hours)\n";
    }
} else {
    echo "  WARNING - No courses found for this combination!\n";
}

// Step 5: Test switching to Year 2
echo "\nSTEP 5: Test Year 2 (update registration)\n";
$db->query("UPDATE semester_registration SET year_of_study = '2' WHERE id = {$newId}");
$latestReg = $regDataService->getLatestSemesterRegistration($testStudent);
echo "  Updated to Year: {$latestReg['year_of_study']}\n";

$courses = $regDataService->getAvailableCourses(
    $latestReg['program_code'],
    (int)$latestReg['year_of_study'],
    (int)$latestReg['semester']
);
echo "  Courses for Year 2, Semester 1:\n";
foreach ($courses as $c) {
    echo "    - {$c['course_code']}: {$c['course_name']}\n";
}

echo "\n=== Flow Test Complete ===\n";
echo "\nSummary:\n";
echo "  - registration.php reads existing semester_registration\n";
echo "  - If none exists, student selects Year/Semester\n";
echo "  - If exists, dropdowns are disabled (already registered)\n";
echo "  - courseReg.php reads the same registration and shows matching courses\n";
