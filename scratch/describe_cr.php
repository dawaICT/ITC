<?php
require dirname(__DIR__) . '/db/connect.php';
require dirname(__DIR__) . '/students/includes/RegistrationDataService.php';
require dirname(__DIR__) . '/students/includes/AcademicSessionService.php';
require dirname(__DIR__) . '/students/includes/period_mode_helper.php';

$sid = $argv[1] ?? 'CSE26456789';
echo "=== course_registration schema ===\n";
$r = $db->query('DESCRIBE course_registration');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' ' . $row['Type'] . "\n";
}

echo "\n=== course_registration rows for {$sid} ===\n";
$stmt = $db->prepare('SELECT * FROM course_registration WHERE Sid = ? LIMIT 20');
$stmt->bind_param('s', $sid);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    echo json_encode($row) . "\n";
}
$stmt->close();

$svc = new RegistrationDataService($db);
$sessSvc = new AcademicSessionService($db);
$periodMode = getStudentProgramPeriodMode($db, $sid);
$session = $sessSvc->getCurrentSession($periodMode);
$ay = (string)($session['academic_year'] ?? '');
$sem = (int)($session['semester_term'] ?? 1);
$termContext = $svc->resolveRegistrationTermContext($sid, null, $ay, $sem, $periodMode, false);
echo "\nTerm context: " . json_encode($termContext) . "\n";
$registered = $svc->getRegisteredCourses(
    $sid,
    (int)$termContext['year_of_study'],
    (int)$termContext['semester'],
    (int)$termContext['id']
);
echo "RegistrationDataService registered (" . count($registered) . "):\n";
foreach ($registered as $c) {
    echo "  {$c['course_code']} => {$c['course_name']}\n";
}
