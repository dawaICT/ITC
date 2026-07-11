<?php
require dirname(__DIR__) . '/db/connect.php';
require dirname(__DIR__) . '/students/includes/RegistrationDataService.php';
require dirname(__DIR__) . '/students/includes/AcademicSessionService.php';
require dirname(__DIR__) . '/students/includes/StudentDataService.php';
require dirname(__DIR__) . '/students/includes/period_mode_helper.php';

$sid = 'CSE26456789';
$mysqli = $db;
$regDataService = new RegistrationDataService($mysqli);
$sessionService = new AcademicSessionService($mysqli);
$sds = new StudentDataService($mysqli);

$studentDetails = $sds->getStudentWithProgram($sid);
$studentDetails['year_of_study'] = max(1, $sds->getStudentYearOfStudy($sid));
$periodMode = getStudentProgramPeriodMode($mysqli, $sid);
$session = $sessionService->getCurrentSession($periodMode);
$ay = (string)($session['academic_year'] ?? '2026');
$sem = (string)($session['semester_term'] ?? '1');

// Minimal checkSemesterRegistration
$cols = [];
if ($meta = $mysqli->query('SHOW COLUMNS FROM semester_registration')) {
    while ($c = $meta->fetch_assoc()) { $cols[strtolower($c['Field'])] = $c['Field']; }
    $meta->free();
}
$sidCols = array_values(array_intersect_key($cols, array_flip(['student_id','sid'])));
$semCol = $cols['semester'] ?? 'semester';
$periodCol = $cols['period_type'] ?? null;
$ayCol = $cols['academic_year'] ?? null;
$sidSql = implode(' OR ', array_map(fn($c) => "`$c` = ?", $sidCols));
$sql = "SELECT * FROM semester_registration WHERE ($sidSql) AND `$semCol` = ?";
$types = str_repeat('s', count($sidCols)) . 's';
$params = array_merge(array_fill(0, count($sidCols), $sid), [$sem]);
if ($periodCol) { $sql .= " AND `$periodCol` = ?"; $types .= 's'; $params[] = wuc_legacy_period_type($periodMode); }
if ($ayCol) { $sql .= " AND (`$ayCol` = ? OR `$ayCol` LIKE ? OR ? LIKE CONCAT(`$ayCol`, '%'))"; $types .= 'sss'; $params[] = $ay; $params[] = substr($ay,0,4).'%'; $params[] = $ay; }
$sql .= ' ORDER BY id DESC LIMIT 1';
$stmt = $mysqli->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$semesterRegistration = $stmt->get_result()->fetch_assoc();
$stmt->close();

$isSemesterRegistered = !empty($semesterRegistration);
$hasCourseReg = false;
if ($isSemesterRegistered) {
    $regId = (int)$semesterRegistration['id'];
    $yos = (int)($semesterRegistration['year_of_study'] ?? 1);
    $registeredCourses = $regDataService->getRegisteredCourses($sid, $yos, (int)$sem, $regId);
    $hasCourseReg = !empty($registeredCourses);
}
$currentStep = $isSemesterRegistered ? ($hasCourseReg ? 3 : 2) : 1;

echo "registration.php would show:\n";
echo "  Step: $currentStep\n";
echo "  Sem registered: " . ($isSemesterRegistered ? 'yes id='.$semesterRegistration['id'].' prog='.($semesterRegistration['program_code']??'') : 'no') . "\n";
echo "  Has courses: " . ($hasCourseReg ? 'yes ('.count($registeredCourses).')' : 'no') . "\n";
echo "  courseReg link: courseReg.php?id=" . ($semesterRegistration['id'] ?? '') . "\n";

echo "\ncourseReg.php WITHOUT id would use:\n";
$latest = $regDataService->getLatestSemesterRegistration($sid);
echo "  semReg id={$latest['id']} sem={$latest['semester']} prog={$latest['program_code']}\n";
echo "  MISMATCH: " . ($latest['id'] != ($semesterRegistration['id'] ?? 0) ? 'YES - BUG' : 'no') . "\n";

echo "\nStudent assigned program: {$studentDetails['program_code']}\n";
echo "Sem reg program: " . ($semesterRegistration['program_code'] ?? 'n/a') . "\n";
echo "PROGRAM MISMATCH: " . (($semesterRegistration['program_code'] ?? '') !== $regDataService->getBestStudentProgramCode($sid) ? 'YES' : 'no') . "\n";

echo "\nDONE\n";
