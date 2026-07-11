<?php
// Test script: fetch a student SID then retrieve registered courses via RegistrationDataService
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/includes/DatabaseConnection.php';
require_once __DIR__ . '/includes/RegistrationDataService.php';

echo "=== Test: Semester Registered Courses ===\n";

$dbConn = DatabaseConnection::getInstance();
$mysqli = $dbConn->getMysqli();
if (!$mysqli) {
    echo "✗ mysqli not available: " . ($dbConn->getLastError() ?? 'unknown') . "\n";
    exit(1);
}

// Pick one student SID from the students table
$sid = null;
$res = $mysqli->query("SELECT SID FROM students LIMIT 1");
if ($res && $row = $res->fetch_assoc()) {
    $sid = $row['SID'];
}

if (!$sid) {
    echo "✗ No student SID found to test.\n";
    exit(1);
}

echo "Using SID: $sid\n";

$regService = new RegistrationDataService($mysqli);
// Try year=1, semester=1 (common case) and also try retrieving via latest semester registration
$courses = $regService->getRegisteredCourses($sid, 1, 1);

if (empty($courses)) {
    echo "No registered courses found for SID=$sid (year=1, sem=1)\n";
} else {
    echo "Found " . count($courses) . " registered course(s):\n";
    foreach ($courses as $c) {
        echo " - " . ($c['course_code'] ?? $c['course_code']) . " : " . ($c['course_name'] ?? '') . " (credit hours: " . ($c['credit_hours'] ?? ($c['credits'] ?? 3)) . ")\n";
    }
}

// Also attempt to get semester registration id and use getSemesterRegisteredCourses from registration.php if available
if (file_exists(__DIR__ . '/registration.php')) {
    require_once __DIR__ . '/registration.php';
    // registration.php defines getSemesterRegisteredCourses()
    $semCourses = getSemesterRegisteredCourses($sid, 1, 1);
    echo "\ngetSemesterRegisteredCourses() returned " . count($semCourses) . " course(s).\n";
}

echo "=== Test complete ===\n";
