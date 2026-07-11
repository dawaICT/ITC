<?php
require dirname(__DIR__) . '/db/connect.php';
require dirname(__DIR__) . '/students/includes/RegistrationDataService.php';
require dirname(__DIR__) . '/students/includes/AcademicSessionService.php';
require dirname(__DIR__) . '/students/includes/period_mode_helper.php';
require dirname(__DIR__) . '/includes/course_recommendation_engine.php';

$svc = new RegistrationDataService($db);
$sessSvc = new AcademicSessionService($db);
$sid = $argv[1] ?? 'CSE26456789';

$periodMode = getStudentProgramPeriodMode($db, $sid);
$session = $sessSvc->getCurrentSession($periodMode);
$ay = (string)($session['academic_year'] ?? '');
$sem = (int)($session['semester_term'] ?? 1);

$termContext = $svc->resolveRegistrationTermContext($sid, null, $ay, $sem, $periodMode, false);
$selectable = $svc->getSelectableCoursesForTerm($sid, $termContext);

$recs = wuc_course_recommendations($db, $sid);
$selectableCodes = array_flip(array_map(
    static fn(array $course): string => (string)($course['course_code'] ?? ''),
    $selectable['courses'] ?? []
));
if ($selectable['count'] > 0) {
    $recs['missing'] = array_values(array_filter(
        $recs['missing'] ?? [],
        static fn(array $item): bool => isset($selectableCodes[(string)($item['course_code'] ?? '')])
    ));
    $recs['retake'] = array_values(array_filter(
        $recs['retake'] ?? [],
        static fn(array $item): bool => isset($selectableCodes[(string)($item['course_code'] ?? '')])
    ));
}

$recCount = count($recs['missing']) + count($recs['retake']);
echo "SID={$sid}\n";
echo "registration.php selectable count: {$selectable['count']}\n";
echo "registration.php filtered recommendation count: {$recCount}\n";
echo "courseReg.php would show: {$selectable['count']}\n";
echo ($selectable['count'] === $recCount || $recCount <= $selectable['count'] ? "OK aligned\n" : "MISMATCH\n");
echo "Codes: " . implode(', ', array_column($selectable['courses'], 'course_code')) . "\n";
