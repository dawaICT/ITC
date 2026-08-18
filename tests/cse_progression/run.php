<?php
declare(strict_types=1);

define('IS_SCRIPT', true);
require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/includes/cse_progression.php';
require_once dirname(__DIR__, 2) . '/includes/applicant_admission.php';
require_once dirname(__DIR__, 2) . '/students/includes/RegistrationDataService.php';

$passes = 0;
$failures = 0;

function cse_check(string $label, bool $condition): void
{
    global $passes, $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $condition ? $passes++ : $failures++;
}

$service = new RegistrationDataService($db);

cse_check('legacy CSE rejects direct assignment', wuc_cse_direct_assignment_error('CSE') !== null);
cse_check('ICT-002 rejects direct assignment', wuc_cse_direct_assignment_error('ICT-002') !== null);
cse_check('ICT-001 allows direct admission', wuc_cse_direct_assignment_error('ICT-001') === null);
$directDiploma = admissionsEnrollExistingStudent($db, 'NOT-A-STUDENT', 'ICT-002', 'January 2099', 'Full Time', 2099);
cse_check('canonical admission service blocks direct ICT-002 enrolment', !$directDiploma['success'] && str_contains($directDiploma['message'], 'progression'));
cse_check('ICT-001 exposes 8 Year-1 modules', count($service->getAvailableCourses('ICT-001', 1, 1)) === 8);
cse_check('ICT-001 exposes no Year-2 modules', count($service->getAvailableCourses('ICT-001', 2, 1)) === 0);
cse_check('ICT-002 exposes no Year-1 modules', count($service->getAvailableCourses('ICT-002', 1, 1)) === 0);
cse_check('ICT-002 exposes 8 Year-2 modules', count($service->getAvailableCourses('ICT-002', 2, 1)) === 8);
cse_check('retired CSE exposes no modules', count($service->getAvailableCourses('CSE', 1, 1)) === 0);

$sid = 'TEST-CSE-PROGRESSION';
$cleanupRegistration = $db->prepare('DELETE FROM semester_registration WHERE student_id = ? OR SID = ?');
$cleanupRegistration->bind_param('ss', $sid, $sid);
$cleanupRegistration->execute();
$cleanupRegistration->close();
$db->begin_transaction();
try {
    $stmt = $db->prepare(
        "INSERT INTO students (SID, Fname, Lname, sex, email, mobile, status, program, academic_year, year)
         VALUES (?, 'Progression', 'Test', 'M', 'cse-progression-test@example.invalid', '0000000000', 'active', 'ICT-001', '2099', 1)"
    );
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare(
        "INSERT INTO semester_registration
            (student_id, SID, program_code, semester, period_type, year_of_study, Year,
             academic_year, registration_status, fee_status)
         VALUES (?, ?, 'ICT-001', '3', 'term', 1, 1, '2099', 'registered', 'eligible')"
    );
    $stmt->bind_param('ss', $sid, $sid);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare(
        "INSERT INTO student_program
            (Sid, program_code, curriculum_version_id, intake, mode, startYear, endYear,
             status, academic_year, year_of_study, current_year_number, term, current_term_number, semester)
         VALUES (?, 'ICT-001',
                 (SELECT id FROM curriculum_versions WHERE program_code='ICT-001' AND status='active' ORDER BY id DESC LIMIT 1),
                 'January 2099', 'Full Time', 2099, 2100, 'active', '2099', 1, 1, '1', 1, 1)"
    );
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $stmt->close();

    $codes = ['DCSE-101','DCSE-103','DCSE-104','DCSE-105','DCSE-106','DCSE-107','DCSE-108','DCSE-109'];
    $stmt = $db->prepare(
        "INSERT INTO semester_assessment
            (Sid, Course_Code, Exam, Total_CA, semester, Year, status, posted_by, published_by, published_at)
         VALUES (?, ?, 60, 40, '3', '1', 'Published', 'test', 'test', NOW())"
    );
    foreach ($codes as $courseCode) {
        $stmt->bind_param('ss', $sid, $courseCode);
        $stmt->execute();
    }
    $stmt->close();

    $status = wuc_cse_progression_status($db, $sid);
    cse_check('all 8 published passes make the craft student eligible', $status['eligible'] && $status['passed'] === 8);

    $result = wuc_cse_progress_student($db, $sid, 'test-suite', false);
    cse_check('eligible student progresses successfully', $result['ok']);

    $stmt = $db->prepare(
        'SELECT sp.program_code, sp.year_of_study, sp.current_year_number, s.program, s.year
           FROM student_program sp INNER JOIN students s ON s.SID = sp.Sid
          WHERE sp.Sid = ? ORDER BY sp.id DESC LIMIT 1'
    );
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    cse_check(
        'progression synchronizes programme and Year 2 in both student tables',
        ($row['program_code'] ?? '') === 'ICT-002'
        && (int)($row['year_of_study'] ?? 0) === 2
        && (int)($row['current_year_number'] ?? 0) === 2
        && ($row['program'] ?? '') === 'ICT-002'
        && (int)($row['year'] ?? 0) === 2
    );

    $progressedContext = $service->resolveRegistrationTermContext($sid);
    cse_check(
        'registration context switches to diploma Year 2 after progression',
        ($progressedContext['program_code'] ?? '') === 'ICT-002'
        && (int)($progressedContext['year_of_study'] ?? 0) === 2
    );
} finally {
    $db->rollback();
    // semester_registration is a legacy MyISAM table, so it is not covered by
    // the transaction rollback and must be cleaned explicitly.
    $cleanupRegistration = $db->prepare('DELETE FROM semester_registration WHERE student_id = ? OR SID = ?');
    $cleanupRegistration->bind_param('ss', $sid, $sid);
    $cleanupRegistration->execute();
    $cleanupRegistration->close();
}

echo PHP_EOL . "PASS: {$passes}  FAIL: {$failures}" . PHP_EOL;
exit($failures === 0 ? 0 : 1);
