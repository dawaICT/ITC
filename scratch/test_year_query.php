<?php
putenv('APP_ENV=development');
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../students/includes/RegistrationDataService.php';

$sid = 'CSE26456789';
$svc = new RegistrationDataService($db);
$courses = $svc->getRegisteredCourses($sid, 1, 2, 2, '2026', 'year');
echo "Count: " . count($courses) . "\n";
foreach ($courses as $c) {
    echo $c['course_code'] . "\n";
}
