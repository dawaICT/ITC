<?php
require dirname(__DIR__) . '/db/connect.php';
mysqli_report(MYSQLI_REPORT_STRICT);

echo "=== ALL course_registration for CSE26456789 ===\n";
$r = $db->query("SELECT id,Sid,course_code,Year,semester,semester_registration_id,is_active FROM course_registration WHERE Sid='CSE26456789' ORDER BY id");
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

echo "\n=== Active only ===\n";
$r = $db->query("SELECT COUNT(*) c FROM course_registration WHERE Sid='CSE26456789' AND COALESCE(is_active,1)=1");
echo $r->fetch_assoc()['c'] . " active rows\n";

echo "\n=== DCSE courses credits ===\n";
$r = $db->query("SELECT course_code, credits FROM courses WHERE course_code LIKE 'DCSE%'");
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

echo "\n=== Simulate registration.php step check ===\n";
require dirname(__DIR__) . '/students/includes/RegistrationDataService.php';
require dirname(__DIR__) . '/students/includes/StudentDataService.php';
require dirname(__DIR__) . '/students/includes/AcademicSessionService.php';

$sid = 'CSE26456789';
$svc = new RegistrationDataService($db);
$sds = new StudentDataService($db);
$sessionSvc = new AcademicSessionService($db);

$student = $sds->getStudentWithProgram($sid);
echo "Student program: " . ($student['program_code'] ?? 'NONE') . "\n";

$periodMode = 'term'; // from getStudentProgramPeriodMode
$session = $sessionSvc->getCurrentSession($periodMode);
echo "Current session: " . json_encode($session) . "\n";

$latestReg = $svc->getLatestSemesterRegistration($sid);
echo "Latest sem reg: " . json_encode($latestReg) . "\n";

if ($latestReg) {
    $regId = (int)$latestReg['id'];
    $yos = (int)($latestReg['year_of_study'] ?? 1);
    $sem = (int)($latestReg['semester'] ?? 1);
    $courses = $svc->getRegisteredCourses($sid, $yos, $sem, $regId);
    echo "Courses for latest reg ($regId): " . count($courses) . "\n";
    foreach ($courses as $c) {
        echo "  " . json_encode($c) . "\n";
    }
    $available = $svc->getAvailableCourses($svc->getBestStudentProgramCode($sid), $yos, $sem);
    echo "Available courses: " . count($available) . "\n";
}

echo "\n=== Students blocked at step 1 (no sem reg) ===\n";
$r = $db->query("SELECT s.SID, sp.program_code FROM students s INNER JOIN student_program sp ON s.SID=sp.Sid LEFT JOIN semester_registration sr ON sr.student_id=s.SID WHERE sr.id IS NULL AND s.status='active' LIMIT 10");
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

echo "\n=== Academic session config ===\n";
$r = $db->query("SELECT * FROM academic_sessions ORDER BY id DESC LIMIT 3");
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

echo "\nDONE\n";
