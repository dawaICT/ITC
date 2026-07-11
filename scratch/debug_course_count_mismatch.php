<?php
require dirname(__DIR__) . '/db/connect.php';
require dirname(__DIR__) . '/students/includes/RegistrationDataService.php';
require dirname(__DIR__) . '/students/includes/StudentDataService.php';
require dirname(__DIR__) . '/students/includes/AcademicSessionService.php';
require dirname(__DIR__) . '/students/includes/period_mode_helper.php';
require dirname(__DIR__) . '/includes/course_recommendation_engine.php';
require dirname(__DIR__) . '/students/includes/EligibilityService.php';

$svc = new RegistrationDataService($db);
$studentSvc = new StudentDataService($db);
$sessSvc = new AcademicSessionService($db);

function count_course_reg_list(string $sid, array $foundReg, RegistrationDataService $svc, mysqli $db): array
{
    $semester = (string)($foundReg['semester'] ?? '');
    $Year = (string)($foundReg['year_of_study'] ?? '');
    $program = (string)($foundReg['program_code'] ?? '');
    $semesterRegId = isset($foundReg['id']) ? (int)$foundReg['id'] : null;
    $assignedProgram = $svc->getBestStudentProgramCode($sid);
    if ($assignedProgram !== '') {
        $program = $assignedProgram;
    }

    $yearInt = (int)$Year;
    $semInt = (int)$semester;
    $registeredCourses = $svc->getRegisteredCourses($sid, $yearInt, $semInt, $semesterRegId);
    $alreadyRegisteredCourses = !empty($registeredCourses);

    if ($alreadyRegisteredCourses) {
        $availableCourses = $registeredCourses;
    } else {
        $availableCourses = $svc->getAvailableCourses($program, $yearInt, $semInt);
    }

    $recordsSet = [];
    foreach ($availableCourses as $ac) {
        $recordsSet[(string)$ac['course_code']] = true;
    }

    // carry-over failed courses (courseReg.php logic)
    $failed = EligibilityService::getFailedCourses($db, $sid);
    $failedCodes = array_values(array_unique(array_map(static fn($x) => (string)$x['course_code'], $failed)));
    if (!$alreadyRegisteredCourses && !empty($failedCodes)) {
        $sourceTable = null;
        if ($chk = $db->query("SHOW TABLES LIKE 'course_levels'")) {
            if ($chk->num_rows > 0) {
                $sourceTable = 'course_levels';
            }
            $chk->free();
        }
        if ($sourceTable === null) {
            if ($chk = $db->query("SHOW TABLES LIKE 'program_courses'")) {
                if ($chk->num_rows > 0) {
                    $sourceTable = 'program_courses';
                }
                $chk->free();
            }
        }
        if ($sourceTable !== null) {
            $in = implode(',', array_map(static fn($c) => "'" . $db->real_escape_string($c) . "'", $failedCodes));
            $sqlCarry = "SELECT DISTINCT course_code FROM `{$sourceTable}` WHERE program_code = '" . $db->real_escape_string($program) . "' AND course_code IN ({$in}) AND semester = '" . $db->real_escape_string($semester) . "'";
            if ($qrf = $db->query($sqlCarry)) {
                while ($rf = $qrf->fetch_assoc()) {
                    $recordsSet[(string)$rf['course_code']] = true;
                }
                $qrf->free();
            }
        }
    }

    return [
        'program' => $program,
        'year' => $yearInt,
        'semester' => $semInt,
        'registered_count' => count($registeredCourses),
        'available_count' => count($availableCourses),
        'display_count' => count($recordsSet),
        'codes' => array_keys($recordsSet),
    ];
}

$sid = $argv[1] ?? 'CSE26456789';
$periodMode = getStudentProgramPeriodMode($db, $sid);
$session = $sessSvc->getCurrentSession($periodMode);
$ay = (string)($session['academic_year'] ?? '');
$sem = (int)($session['semester_term'] ?? 1);

echo "=== Course count analysis for {$sid} ===\n";
echo "Current session: ay={$ay} sem={$sem} mode={$periodMode}\n";
echo "Best program: " . $svc->getBestStudentProgramCode($sid) . "\n";
echo "Student YOS (StudentDataService): " . $studentSvc->getStudentYearOfStudy($sid) . "\n\n";

$currentReg = $svc->getLatestSemesterRegistration($sid, $ay, (string)$sem, $periodMode);
$latestAny = $svc->getLatestSemesterRegistration($sid);

if ($currentReg) {
    echo "--- registration.php context (current period reg) ---\n";
    $yos = (int)($currentReg['year_of_study'] ?? 1);
    $regCourses = $svc->getRegisteredCourses($sid, $yos, $sem, (int)$currentReg['id'], $ay);
    echo "hasCourseReg: " . (count($regCourses) > 0 ? 'yes' : 'no') . " count=" . count($regCourses) . "\n";
    $prog = $svc->getBestStudentProgramCode($sid) ?: (string)($currentReg['program_code'] ?? '');
    $avail = $svc->getAvailableCourses($prog, $yos, $sem);
    echo "Available (prog={$prog}, yos={$yos}, sem={$sem}): " . count($avail) . "\n";
    echo "Reg row program: " . ($currentReg['program_code'] ?? '') . " mapped_course_count: " . ($currentReg['mapped_course_count'] ?? 'n/a') . "\n\n";
}

echo "--- courseReg.php with ?id= (specific reg) ---\n";
if ($currentReg) {
    $cr = count_course_reg_list($sid, $currentReg, $svc, $db);
    echo json_encode($cr, JSON_PRETTY_PRINT) . "\n\n";
}

echo "--- courseReg.php fallback (latest any) ---\n";
if ($latestAny) {
    $cr2 = count_course_reg_list($sid, $latestAny, $svc, $db);
    echo json_encode($cr2, JSON_PRETTY_PRINT) . "\n\n";
}

echo "--- course recommendations (registration.php card) ---\n";
$recs = wuc_course_recommendations($db, $sid);
echo "retake=" . count($recs['retake']) . " missing=" . count($recs['missing']) . " total=" . (count($recs['retake']) + count($recs['missing'])) . "\n";
echo "rec program=" . ($recs['program_code'] ?? '') . " yos=" . ($recs['year_of_study'] ?? '') . "\n";
