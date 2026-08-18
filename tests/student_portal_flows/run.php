<?php
declare(strict_types=1);

/**
 * CLI regression harness for the 2026-08 student-portal repair pass.
 *
 * NOTE: StudentDataService reads through a separate PDO connection, so rows
 * it must observe are committed and then removed again in finally blocks.
 * Everything else runs in mysqli transactions rolled back at the end.
 */

define('IS_SCRIPT', true);
require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/students/includes/StudentDataService.php';
require_once dirname(__DIR__, 2) . '/students/includes/StudentAcademicWorkflowService.php';
require_once dirname(__DIR__, 2) . '/includes/ca_helpers.php';

$pass = 0;
$fail = 0;
function spf_check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

function spf_cleanup_cse_2027(mysqli $db): void
{
    $db->query("DELETE FROM semester_registration WHERE student_id = 'CSE26456789' AND academic_year = '2027'");
}

function spf_cleanup_flow_student(mysqli $db, string $sid): void
{
    $spIds = [];
    if ($res = $db->query("SELECT id FROM student_program WHERE Sid = '{$sid}'")) {
        while ($r = $res->fetch_assoc()) {
            $spIds[] = (int)$r['id'];
        }
        $res->free();
    }
    foreach ($spIds as $spId) {
        $db->query("DELETE FROM student_course_registrations WHERE student_programme_id = {$spId}");
    }
    $db->query("DELETE FROM course_registration WHERE Sid = '{$sid}'");
    $db->query("DELETE FROM semester_registration WHERE student_id = '{$sid}'");
    $db->query("DELETE FROM student_program WHERE Sid = '{$sid}'");
    $db->query("DELETE FROM students WHERE SID = '{$sid}'");
}

// ---------------------------------------------------------------------
// 1. Year-of-study resolution is scoped to the academic year requested.
// ---------------------------------------------------------------------
$studentData = new StudentDataService($db);
spf_check('getStudentYearOfStudy(CSE26456789, 2026) == 1', $studentData->getStudentYearOfStudy('CSE26456789', '2026') === 1);

spf_cleanup_cse_2027($db);
$db->query("INSERT INTO semester_registration
    (student_id, SID, program_code, semester, period_type, year_of_study, `Year`, academic_year, registration_status, fee_status)
    VALUES ('CSE26456789','CSE26456789','ICT-002','1','term',2,2,'2027','pending','unknown')");
try {
    spf_check(
        'YoS still 1 for 2026 after a 2027 pending row exists',
        $studentData->getStudentYearOfStudy('CSE26456789', '2026') === 1
    );
    spf_check(
        'YoS resolves 2 for 2027 (the pending progression row)',
        $studentData->getStudentYearOfStudy('CSE26456789', '2027') === 2
    );
} finally {
    spf_cleanup_cse_2027($db);
}


// ---------------------------------------------------------------------
// 2 & 3. registerStudentForPeriod claims a pending progression row and
// syncs student_program position; re-enrolment moves course period.
// (Committed setup: StudentDataService reads via its own PDO connection.)
// ---------------------------------------------------------------------
$sid = 'SPFTEST0001';
$program = 'ICT-001';
spf_cleanup_flow_student($db, $sid);
try {
    $stmt = $db->prepare("INSERT INTO students (SID, Fname, Lname, sex, email, mobile, status, program, academic_year, year)
        VALUES (?, 'Portal', 'Flow Test', 'M', ?, '0000000000', 'active', ?, '2026', 1)");
    $email = 'spftest0001@example.invalid';
    $stmt->bind_param('sss', $sid, $email, $program);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("INSERT INTO student_program
        (Sid, program_code, curriculum_version_id, intake, mode, startYear, endYear,
         status, academic_year, year_of_study, current_year_number, term, current_term_number, semester)
        VALUES (?, ?, 23, 'January 2026', 'Full Time', 2026, 2027,
                'active', '2026', 1, 1, '1', 1, 1)");
    $stmt->bind_param('ss', $sid, $program);
    $stmt->execute();
    $stmt->close();

    // Pre-seed a pending placeholder for Term 2/2026 (as progression would).
    $db->query("INSERT INTO semester_registration
        (student_id, SID, program_code, semester, period_type, year_of_study, `Year`, academic_year, registration_status, fee_status)
        VALUES ('{$sid}','{$sid}','{$program}','2','term',1,1,'2026','pending','unknown')");

    $wf = new StudentAcademicWorkflowService($db);
    $result = $wf->registerStudentForPeriod($sid, ['skip_teveta' => true]);
    spf_check('registerStudentForPeriod claims a pending row instead of refusing', (bool)($result['ok'] ?? false));
    if (!$result['ok']) {
        echo '       -> ' . ($result['message'] ?? '') . PHP_EOL;
    }

    $row = $db->query("SELECT registration_status FROM semester_registration WHERE student_id='{$sid}' LIMIT 1")->fetch_assoc();
    spf_check('claimed row registration_status is now registered', ($row['registration_status'] ?? '') === 'registered');

    $spRow = $db->query("SELECT current_term_number FROM student_program WHERE Sid='{$sid}' LIMIT 1")->fetch_assoc();
    spf_check('student_program.current_term_number synced to 2', (int)($spRow['current_term_number'] ?? 0) === 2);

    $cc = $db->query("SELECT COUNT(*) c FROM course_registration WHERE Sid='{$sid}' AND semester=2")->fetch_assoc();
    spf_check('courses auto-enrolled at period 2', (int)($cc['c'] ?? 0) > 0);

    // Simulate Term 3 re-enrolment: reactivation must move the course period.
    $semRegIdRow = $db->query("SELECT id FROM semester_registration WHERE student_id='{$sid}' LIMIT 1")->fetch_assoc();
    $refl = new ReflectionClass($wf);
    $method = $refl->getMethod('reactivateCourseRegistrationForYear');
    $method->setAccessible(true);
    $method->invoke($wf, $sid, 'DCSE-101', 1, '2026', (int)$semRegIdRow['id'], 3);
    $moved = $db->query("SELECT semester FROM course_registration WHERE Sid='{$sid}' AND course_code='DCSE-101' LIMIT 1")->fetch_assoc();
    spf_check('reactivateCourseRegistrationForYear moves course to period 3', (int)($moved['semester'] ?? 0) === 3);
} finally {
    spf_cleanup_flow_student($db, $sid);
}


// ---------------------------------------------------------------------
// 4 & 5. CA class list / registration guard tolerate full-year courses
// stamped with a different period than requested (Phase 2 fix).
// ---------------------------------------------------------------------
$listResult = ca_fetch_course_students($db, 'DCSE-101', '2', '2026');
$sids = array_column($listResult['students'], 'Sid');
spf_check('ca_fetch_course_students(DCSE-101,2,2026) includes CSE26456789', in_array('CSE26456789', $sids, true));
spf_check('ca_fetch_course_students includes EXH-ALU-001 (full-year tolerance)', in_array('EXH-ALU-001', $sids, true));
spf_check('ca_fetch_course_students includes EXH-STU-001 (full-year tolerance)', in_array('EXH-STU-001', $sids, true));

spf_check('ca_student_registered(EXH-ALU-001,DCSE-101,2,2026) is true', ca_student_registered($db, 'EXH-ALU-001', 'DCSE-101', '2', '2026'));
spf_check('ca_student_registered(SCONLYTEST01,DCSE-101,2,2026) is false', !ca_student_registered($db, 'SCONLYTEST01', 'DCSE-101', '2', '2026'));

// ---------------------------------------------------------------------
// 6. Normalized bridge resolves full-year courses at the requested period.
// ---------------------------------------------------------------------
$db->begin_transaction();
try {
    $bridgeId = ca_ensure_normalized_registration_bridge($db, 'CSE26456789', 'DCSE-101', '3', '2026');
    $bridgeOk = false;
    if ($bridgeId !== null) {
        $br = $db->query("SELECT co.academic_period_id FROM student_course_registrations scr
                          JOIN course_offerings co ON co.id = scr.course_offering_id
                          WHERE scr.id = " . (int)$bridgeId . " LIMIT 1")->fetch_assoc();
        // academic_periods id 9 = Term 3, 2026
        $bridgeOk = (int)($br['academic_period_id'] ?? 0) === 9;
    }
    spf_check('bridge targets requested period 3 (academic_period_id=9) for full-year course', $bridgeOk);
} finally {
    $db->rollback();
}

echo PHP_EOL . "TOTAL: {$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
