<?php
/**
 * Registration Data Flow Verification Script
 * Tests that registration.php, courseReg.php, and myCourses.php use consistent logic
 */
require_once __DIR__ . '/students/includes/Database.php';
require_once __DIR__ . '/students/includes/RegistrationDataService.php';
require_once __DIR__ . '/students/includes/StudentRegistrationSystem.php';

echo "=== REGISTRATION DATA FLOW VERIFICATION ===\n\n";

// Initialize services
try {
    $pdoDb = new Database();
    $mysqli = new mysqli('localhost', 'root', '', 'wucportal');
    
    if ($mysqli->connect_error) {
        die("MySQL Connection Failed: " . $mysqli->connect_error);
    }
    
    $regDataService = new RegistrationDataService($mysqli);
    $regSystem = new StudentRegistrationSystem($pdoDb);
    
    echo "[OK] Database connections established\n\n";
} catch (Exception $e) {
    die("Init Error: " . $e->getMessage());
}

// Test with a sample student
$testStudentId = 'test123';
$testProgram = 'BSCS';
$testYear = 1;
$testSemester = 1;

echo "=== TEST PARAMETERS ===\n";
echo "Student ID: $testStudentId\n";
echo "Program: $testProgram\n";
echo "Year: $testYear, Semester: $testSemester\n\n";

// Test 1: StudentRegistrationSystem.getRequiredCourses (used by registration.php)
echo "=== TEST 1: StudentRegistrationSystem.getRequiredCourses ===\n";
echo "(This is what registration.php uses via AJAX)\n";
try {
    $courses1 = $regSystem->getRequiredCourses($testYear, $testSemester, false, $testProgram);
    echo "Found " . count($courses1) . " courses:\n";
    foreach ($courses1 as $c) {
        echo "  - {$c['course_code']}: {$c['course_name']} ({$c['credits']} credit hours)\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Test 2: RegistrationDataService.getAvailableCourses (used by courseReg.php)
echo "\n=== TEST 2: RegistrationDataService.getAvailableCourses ===\n";
echo "(This is what courseReg.php uses)\n";
try {
    $courses2 = $regDataService->getAvailableCourses($testProgram, $testYear, $testSemester);
    echo "Found " . count($courses2) . " courses:\n";
    foreach ($courses2 as $c) {
        echo "  - {$c['course_code']}: {$c['course_name']} ({$c['credit_hours']} credit hours)\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Test 3: Compare results
echo "\n=== TEST 3: CONSISTENCY CHECK ===\n";
$codes1 = array_column($courses1, 'course_code');
$codes2 = array_column($courses2, 'course_code');
sort($codes1);
sort($codes2);

if ($codes1 === $codes2) {
    echo "[OK] Both services return the SAME courses!\n";
} else {
    echo "[WARN] Services return DIFFERENT courses:\n";
    $onlyIn1 = array_diff($codes1, $codes2);
    $onlyIn2 = array_diff($codes2, $codes1);
    if (!empty($onlyIn1)) echo "  Only in registration.php: " . implode(', ', $onlyIn1) . "\n";
    if (!empty($onlyIn2)) echo "  Only in courseReg.php: " . implode(', ', $onlyIn2) . "\n";
}

// Test 4: Check myCourses.php logic (course_registration query)
echo "\n=== TEST 4: myCourses.php Query Test ===\n";
$sql = "SELECT cr.course_code, c.course_name, COALESCE(c.credit_hours, c.credits, 3) as credits
        FROM course_registration cr
        JOIN courses c ON c.course_code = cr.course_code
        WHERE cr.Sid = '$testStudentId'
        ORDER BY cr.course_code";
$result = $mysqli->query($sql);
if ($result) {
    echo "Registered courses for student $testStudentId:\n";
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo "  - {$row['course_code']}: {$row['course_name']} ({$row['credits']} credit hours)\n";
        }
    } else {
        echo "  (No registrations found - student hasn't completed course registration yet)\n";
    }
    $result->free();
} else {
    echo "Error: " . $mysqli->error . "\n";
}

// Test 5: Latest semester registration
echo "\n=== TEST 5: Latest Semester Registration ===\n";
$latestReg = $regDataService->getLatestSemesterRegistration($testStudentId);
if ($latestReg) {
    echo "Found registration:\n";
    echo "  ID: {$latestReg['id']}\n";
    echo "  Year of Study: {$latestReg['year_of_study']}\n";
    echo "  Semester: {$latestReg['semester']}\n";
    echo "  Program: {$latestReg['program_code']}\n";
} else {
    echo "No semester registration found for $testStudentId\n";
}

echo "\n=== VERIFICATION COMPLETE ===\n";
$mysqli->close();
?>
