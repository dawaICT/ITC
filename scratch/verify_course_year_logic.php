<?php
declare(strict_types=1);

putenv('APP_ENV=development');
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/helpers/academic_period_helpers.php';
require_once __DIR__ . '/../students/includes/RegistrationDataService.php';

$files = [
    __DIR__ . '/../students/courseReg.php',
    __DIR__ . '/../includes/course_recommendation_engine.php',
    __DIR__ . '/../students/includes/StudentAcademicWorkflowService.php',
    __DIR__ . '/../students/includes/RegistrationDataService.php',
    __DIR__ . '/../students/continuousAssessment.php',
    __DIR__ . '/../students/ai_course_advisor.php',
];
foreach ($files as $file) {
    passthru('"' . PHP_BINARY . '" -l ' . escapeshellarg($file), $code);
    if ($code !== 0) {
        exit(1);
    }
}

$svc = new RegistrationDataService($db);
$sid = '';
if ($r = $db->query("SELECT SID FROM students WHERE status = 'active' OR status IS NULL OR status = '' LIMIT 1")) {
    if ($row = $r->fetch_assoc()) {
        $sid = (string)$row['SID'];
    }
    $r->free();
}

echo "Test student: {$sid}\n";
if ($sid === '') {
    echo "No student — syntax checks only.\n";
    exit(0);
}

$status = $svc->getRegistrationStatus($sid);
$term = $status['current_term'] ?? null;
if (!$term) {
    echo "No semester registration for test student.\n";
    exit(0);
}

$y = (int)$term['year_of_study'];
$s = (int)$term['semester'];
$prog = (string)$term['program_code'];
$ay = (string)($term['academic_year'] ?? '');

$yearCourses = $svc->getRegisteredCourses($sid, $y, $s, (int)($status['semester_registration_id'] ?? 0), $ay ?: null, 'year');
$periodCourses = $svc->getRegisteredCourses($sid, $y, $s, (int)($status['semester_registration_id'] ?? 0), $ay ?: null, 'period');
$available = $prog !== '' ? $svc->getAvailableCourses($prog, $y, $s) : [];
$yearCatalog = $prog !== '' ? getCoursesForProgramYearOfStudy($db, $prog, $y) : [];

echo "Year-scope registered: " . count($yearCourses) . "\n";
echo "Period-scope registered: " . count($periodCourses) . "\n";
echo "Available (year-wide): " . count($available) . "\n";
echo "Catalog year-wide: " . count($yearCatalog) . "\n";

if (count($yearCourses) < count($periodCourses)) {
    echo "FAIL: year scope should be >= period scope\n";
    exit(1);
}

echo "OK\n";
