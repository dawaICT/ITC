<?php
putenv('APP_ENV=development');
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../students/includes/RegistrationDataService.php';
require_once __DIR__ . '/../students/includes/StudentAcademicWorkflowService.php';

$sid = 'CSE26456789';
$wf = new StudentAcademicWorkflowService($db);
$svc = new RegistrationDataService($db);

$before = count($svc->getRegistrationStatus($sid)['registered_courses'] ?? []);
$synced = $wf->ensureYearCoursesEnrolled($sid);
$after = count($svc->getRegistrationStatus($sid)['registered_courses'] ?? []);

echo "Synced inserts: {$synced}\n";
echo "Registered before: {$before} after: {$after}\n";

$term = $svc->getRegistrationStatus($sid)['current_term'];
$sel = $svc->getSelectableCoursesForTerm($sid, $term);
$enrolled = count(array_filter($sel['courses'] ?? [], static fn($c) => !empty($c['is_enrolled'])));
echo "Catalogue: {$sel['count']} enrolled in list: {$enrolled}\n";
