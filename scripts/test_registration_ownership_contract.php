<?php
declare(strict_types=1);

$api = file_get_contents(dirname(__DIR__) . '/api/registration.php');
$service = file_get_contents(dirname(__DIR__) . '/includes/RegistrationService.php');
$checks = [
    'create identity is forced from session' => strpos($api, '$data[\'student_id\'] = (string) $_SESSION[\'Sid\'];') !== false,
    'history identity is forced from session' => strpos($api, '$studentId = (string)$_SESSION[\'Sid\'];') !== false,
    'course changes pass session owner' => strpos($api, 'registerCourses((int)$registrationId, $courses, (string)$_SESSION[\'Sid\'])') !== false,
    'registration lookup constrains owner' => strpos($service, 'WHERE id = ? AND student_id = ?') !== false,
    'details lookup constrains owner' => strpos($service, 'WHERE sr.id = ? AND sr.student_id = ?') !== false,
    'drop lookup constrains owner' => strpos($service, 'WHERE cr.id = ? AND sr.student_id = ?') !== false,
];
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ": {$label}\n";
    if (!$passed) {
        exit(1);
    }
}
