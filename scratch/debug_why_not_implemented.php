<?php
declare(strict_types=1);

putenv('APP_ENV=development');
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../students/includes/RegistrationDataService.php';
require_once __DIR__ . '/../students/includes/StudentAcademicWorkflowService.php';
require_once __DIR__ . '/../includes/helpers/academic_period_helpers.php';

$sid = $argv[1] ?? 'CSE26456789';
$svc = new RegistrationDataService($db);
$wf = new StudentAcademicWorkflowService($db);

echo "=== Student: {$sid} ===\n\n";

$status = $svc->getRegistrationStatus($sid);
$term = $status['current_term'] ?? null;
echo "Has period reg: " . ($status['has_semester_registration'] ? 'yes' : 'no') . "\n";
echo "Has course reg: " . ($status['has_course_registration'] ? 'yes' : 'no') . "\n";
echo "Registered count (status): " . count($status['registered_courses'] ?? []) . "\n";

if ($term) {
    $y = (int)$term['year_of_study'];
    $s = (int)$term['semester'];
    $prog = (string)$term['program_code'];
    $ay = (string)($term['academic_year'] ?? '');
    $regId = (int)($status['semester_registration_id'] ?? 0);

    echo "\nTerm: Y{$y} period {$s} prog {$prog} ay {$ay} regId {$regId}\n";
    echo "Year registered: " . count($svc->getRegisteredCourses($sid, $y, $s, $regId, $ay ?: null, 'year')) . "\n";
    echo "Period registered: " . count($svc->getRegisteredCourses($sid, $y, $s, $regId, $ay ?: null, 'period')) . "\n";
    echo "Available catalogue: " . count($svc->getAvailableCourses($prog, $y, $s)) . "\n";
    echo "Year catalogue helper: " . count(getCoursesForProgramYearOfStudy($db, $prog, $y)) . "\n";

    $sel = $svc->getSelectableCoursesForTerm($sid, $term);
    echo "Selectable count: " . ($sel['count'] ?? 0) . " already_registered=" . (!empty($sel['already_registered']) ? 'yes' : 'no') . "\n";
}

$period = $wf->getActiveAcademicPeriod($sid);
echo "\nActive period ok: " . (!empty($period['ok']) ? 'yes' : 'no') . "\n";
if (!empty($period['ok'])) {
    echo "  program={$period['program_code']} yos={$period['year_of_study']} period={$period['period_number']} ay={$period['academic_year']}\n";
    $regCheck = $wf->checkStudentRegistration($sid, $period);
    echo "  checkStudentRegistration courses: " . count($regCheck['registered_courses']) . "\n";
}

// Raw course_registration rows
if ($stmt = $db->prepare('SELECT course_code, semester, Year, academic_year, semester_registration_id FROM course_registration WHERE Sid = ? ORDER BY course_code, semester')) {
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $res = $stmt->get_result();
    echo "\nRaw course_registration rows:\n";
    while ($row = $res->fetch_assoc()) {
        echo "  {$row['course_code']} sem={$row['semester']} yos={$row['Year']} ay={$row['academic_year']} sr={$row['semester_registration_id']}\n";
    }
    $stmt->close();
}

// semester_registration rows
if ($stmt = $db->prepare('SELECT id, semester, year_of_study, academic_year, program_code FROM semester_registration WHERE student_id = ? OR Sid = ? ORDER BY id')) {
    $stmt->bind_param('ss', $sid, $sid);
    $stmt->execute();
    $res = $stmt->get_result();
    echo "\nsemester_registration rows:\n";
    while ($row = $res->fetch_assoc()) {
        echo "  id={$row['id']} sem={$row['semester']} yos={$row['year_of_study']} ay={$row['academic_year']} prog={$row['program_code']}\n";
    }
    $stmt->close();
}

// Check courseReg.php is not redirect stub
$cr = file_get_contents(__DIR__ . '/../students/courseReg.php');
echo "\ncourseReg.php: " . (str_contains($cr, "header('Location: registration.php')") && !str_contains($cr, 'Course Enrolment') ? 'REDIRECT STUB' : 'FULL PAGE') . "\n";
