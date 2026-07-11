<?php
/**
 * Reset and verify registration data
 */
require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/students/includes/RegistrationDataService.php';

echo "=== Reset Registration Data ===\n\n";

// 1. Clear test data
echo "1. Clearing test registrations...\n";
$db->query("DELETE FROM semester_registration WHERE student_id = 'test123'");
echo "   Deleted " . $db->affected_rows . " semester registrations\n";

$db->query("DELETE FROM course_registration WHERE Sid = 'test123'");
echo "   Deleted " . $db->affected_rows . " course registrations\n";

// 2. Verify clean state
echo "\n2. Verifying clean state...\n";
$regDataService = new RegistrationDataService($db);
$latestReg = $regDataService->getLatestSemesterRegistration('test123');

if ($latestReg === null) {
    echo "   OK - No semester registration exists for test123\n";
} else {
    echo "   WARNING - Still has registration: ID {$latestReg['id']}\n";
}

// 3. Show available courses
echo "\n3. Available courses in course_levels:\n";
$result = $db->query("SELECT program_code, year, semester, COUNT(*) as cnt FROM course_levels GROUP BY program_code, year, semester ORDER BY program_code, year, semester");
while ($row = $result->fetch_assoc()) {
    echo "   {$row['program_code']} Year {$row['year']} Sem {$row['semester']}: {$row['cnt']} course(s)\n";
}

echo "\n=== Reset Complete ===\n";
echo "\nStudent 'test123' can now:\n";
echo "  1. Visit registration.php to create a semester registration\n";
echo "  2. Visit courseReg.php to select courses based on that registration\n";
