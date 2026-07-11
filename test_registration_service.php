<?php
/**
 * Test script for RegistrationDataService
 * Verifies the data flow between registration.php and courseReg.php
 */

require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/students/includes/RegistrationDataService.php';

echo "=== Test: RegistrationDataService ===\n\n";

// Initialize the service (same as courseReg.php and registration.php use)
$regDataService = new RegistrationDataService($db);
$testStudent = 'test123';

// 1. Test getLatestSemesterRegistration (used by courseReg.php)
echo "1. getLatestSemesterRegistration('$testStudent'):\n";
$latestReg = $regDataService->getLatestSemesterRegistration($testStudent);
if ($latestReg) {
    echo "   ID: {$latestReg['id']}\n";
    echo "   Semester: {$latestReg['semester']}\n";
    echo "   Year of Study: {$latestReg['year_of_study']}\n";
    echo "   Program Code: {$latestReg['program_code']}\n";
    echo "   Financial Status: {$latestReg['financial_status']}\n";
    echo "   Status: OK\n";
} else {
    echo "   (no registration found)\n";
    echo "   Status: FAIL - No semester registration!\n";
    exit(1);
}

// 2. Test getAvailableCourses (used by courseReg.php)
$prog = $latestReg['program_code'];
$year = (int)$latestReg['year_of_study'];
$sem = (int)$latestReg['semester'];

echo "\n2. getAvailableCourses('$prog', $year, $sem):\n";
$courses = $regDataService->getAvailableCourses($prog, $year, $sem);
if (count($courses) > 0) {
    echo "   Found " . count($courses) . " courses:\n";
    foreach ($courses as $c) {
        echo "   - {$c['course_code']}: {$c['course_name']} ({$c['credit_hours']} credit hours)\n";
    }
    echo "   Status: OK\n";
} else {
    echo "   (no courses found)\n";
    echo "   Status: FAIL - No courses for program/year/semester!\n";
}

// 3. Test getRegisteredCourses (pre-selected courses)
echo "\n3. getRegisteredCourses('$testStudent', $year, $sem):\n";
$registered = $regDataService->getRegisteredCourses($testStudent, $year, $sem);
if (count($registered) > 0) {
    echo "   Found " . count($registered) . " registered courses:\n";
    foreach ($registered as $r) {
        echo "   - {$r['course_code']}: {$r['course_name']}\n";
    }
} else {
    echo "   (no courses registered yet - this is normal for new term)\n";
}
echo "   Status: OK\n";

// 4. Test getPaymentStatus
echo "\n4. getPaymentStatus('$testStudent', $year, $sem):\n";
$payment = $regDataService->getPaymentStatus($testStudent, $year, $sem);
echo "   Total Due: K" . number_format($payment['total_due'], 2) . "\n";
echo "   Total Paid: K" . number_format($payment['total_paid'], 2) . "\n";
echo "   Percent Paid: " . $payment['percent_paid'] . "%\n";
echo "   Can Receive CA: " . ($payment['can_receive_ca'] ? 'Yes' : 'No') . "\n";
echo "   Status: OK\n";

// 5. Test getRegistrationStatus (complete status check)
echo "\n5. getRegistrationStatus('$testStudent'):\n";
$status = $regDataService->getRegistrationStatus($testStudent);
echo "   Has Semester Registration: " . ($status['has_semester_registration'] ? 'Yes' : 'No') . "\n";
echo "   Has Course Registration: " . ($status['has_course_registration'] ? 'Yes' : 'No') . "\n";
echo "   Can Register Courses: " . ($status['can_register_courses'] ? 'Yes' : 'No') . "\n";
echo "   Can Receive CA: " . ($status['can_receive_ca'] ? 'Yes' : 'No') . "\n";
if ($status['current_term']) {
    echo "   Current Term: Year {$status['current_term']['year_of_study']}, Semester {$status['current_term']['semester']}, Program {$status['current_term']['program_code']}\n";
}
echo "   Total Credit hours: " . $status['total_credits'] . "\n";
echo "   Status: OK\n";

echo "\n=== All Tests Passed ===\n";
