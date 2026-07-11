<?php
require dirname(__DIR__) . '/db/connect.php';
require dirname(__DIR__) . '/students/includes/RegistrationDataService.php';
require dirname(__DIR__) . '/students/includes/AcademicSessionService.php';
require dirname(__DIR__) . '/students/includes/period_mode_helper.php';

$sid = $argv[1] ?? 'CSE26456789';

echo "=== semester_registration rows ===\n";
$stmt = $db->prepare('SELECT * FROM semester_registration WHERE student_id = ? OR Sid = ? ORDER BY id DESC');
$stmt->bind_param('ss', $sid, $sid);
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
echo "\nCurrent session: AY={$ay}, term={$sem}, periodMode={$periodMode}\n";

$termContext = $svc->resolveRegistrationTermContext($sid, null, $ay, $sem, $periodMode, false);
echo "Term context: " . json_encode($termContext) . "\n";
$selectable = $svc->getSelectableCoursesForTerm($sid, $termContext);
echo "already_registered: " . ($selectable['already_registered'] ? 'yes' : 'no') . "\n";
echo "count: {$selectable['count']}\n";
foreach ($selectable['courses'] as $c) {
    echo "  {$c['course_code']} => {$c['course_name']}\n";
}

// Try semester 1 context
echo "\n=== Try term 1 context ===\n";
$term1 = $svc->resolveRegistrationTermContext($sid, null, $ay, 1, $periodMode, false);
echo "Term1 context: " . json_encode($term1) . "\n";
if ($term1) {
    $reg1 = $svc->getRegisteredCourses($sid, (int)$term1['year_of_study'], 1, (int)$term1['id']);
    echo "Registered term1 (" . count($reg1) . "):\n";
    foreach ($reg1 as $c) echo "  {$c['course_code']} => {$c['course_name']}\n";
    $sel1 = $svc->getSelectableCoursesForTerm($sid, $term1);
    echo "Selectable term1 count: {$sel1['count']}, already_registered: " . ($sel1['already_registered'] ? 'yes' : 'no') . "\n";
}

// Active course_registration only
echo "\n=== Active course_registration ===\n";
$stmt = $db->prepare('SELECT course_code, semester, Year, academic_year, semester_registration_id, is_active FROM course_registration WHERE Sid = ? AND is_active = 1');
$stmt->bind_param('s', $sid);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    echo json_encode($row) . "\n";
}
$stmt->close();

echo "\n=== CA query simulation term 1 ===\n";
$aySim = '2026';
$period = '1';
$semRegId = 5;
$yos = '1';
$sql = "SELECT DISTINCT cr.course_code, COALESCE(c.course_name, cr.course_code) AS course_name
        FROM course_registration cr
        LEFT JOIN courses c ON c.course_code COLLATE utf8mb4_general_ci = cr.course_code COLLATE utf8mb4_general_ci
        WHERE cr.Sid = ? AND cr.semester = ?
          AND (cr.semester_registration_id = ? OR cr.Year = ? OR cr.Year = ?)
          AND COALESCE(cr.is_active, 1) = 1 ORDER BY cr.course_code";
$stmt = $db->prepare($sql);
$stmt->bind_param('ssiss', $sid, $period, $semRegId, $yos, $aySim);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) echo "  {$row['course_code']} => {$row['course_name']}\n";
$stmt->close();

echo "\n=== CA query simulation term 2 ===\n";
$period = '2';
$semRegId = 2;
$stmt = $db->prepare($sql);
$stmt->bind_param('ssiss', $sid, $period, $semRegId, $yos, $aySim);
$stmt->execute();
$res = $stmt->get_result();
$found = false;
while ($row = $res->fetch_assoc()) { echo "  {$row['course_code']} => {$row['course_name']}\n"; $found = true; }
if (!$found) echo "  (no rows)\n";
$stmt->close();
