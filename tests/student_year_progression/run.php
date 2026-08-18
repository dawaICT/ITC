<?php
declare(strict_types=1);

define('IS_SCRIPT', true);
require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/includes/student_year_progression.php';
require_once dirname(__DIR__, 2) . '/students/includes/RegistrationDataService.php';

$pass = 0;
$fail = 0;
function yp_check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

$service = new RegistrationDataService($db);
$stagedSid = 'TEST-YEAR-PROGRESS-CSE';
$generalSid = 'TEST-YEAR-PROGRESS-AUTO';

$cleanup = $db->prepare('DELETE FROM semester_registration WHERE student_id IN (?, ?) OR SID IN (?, ?)');
$cleanup->bind_param('ssss', $stagedSid, $generalSid, $stagedSid, $generalSid);
$cleanup->execute();
$cleanup->close();

yp_check(
    'unauthorized staff role cannot progress a student',
    !wuc_progress_student_year($db, 'missing', 'LECTURER', 'lecturer', 'external_results', 'REF-001')['ok']
);
yp_check(
    'HOS action is rejected without an assigned programme scope',
    !wuc_progress_student_year($db, 'missing', 'ITC904', 'head_of_department', 'external_results', 'REF-002')['ok']
);

$db->begin_transaction();
try {
    $insertStudent = $db->prepare(
        "INSERT INTO students (SID, Fname, Lname, sex, email, mobile, status, program, academic_year, year)
         VALUES (?, 'Year', 'Progression Test', 'M', ?, '0000000000', 'active', ?, '2099', 1)"
    );
    $insertProgram = $db->prepare(
        "INSERT INTO student_program
            (Sid, program_code, curriculum_version_id, intake, mode, startYear, endYear,
             status, academic_year, year_of_study, current_year_number, term, current_term_number, semester)
         VALUES (?, ?, NULL, 'January 2099', 'Full Time', 2099, 2101,
                 'active', '2099', 1, 1, '1', 1, 1)"
    );

    $email = 'year-progression-cse@example.invalid';
    $program = 'ICT-001';
    $insertStudent->bind_param('sss', $stagedSid, $email, $program);
    $insertStudent->execute();
    $insertProgram->bind_param('ss', $stagedSid, $program);
    $insertProgram->execute();

    $email = 'year-progression-auto@example.invalid';
    $program = 'AUTO-003';
    $insertStudent->bind_param('sss', $generalSid, $email, $program);
    $insertStudent->execute();
    $insertProgram->bind_param('ss', $generalSid, $program);
    $insertProgram->execute();
    $insertStudent->close();
    $insertProgram->close();

    $insertPeriod = $db->prepare(
        "INSERT INTO semester_registration
            (student_id, SID, program_code, semester, period_type, year_of_study, Year,
             academic_year, registration_status, fee_status)
         VALUES (?, ?, ?, '3', 'term', 1, 1, '2099', 'registered', 'eligible')"
    );
    foreach ([[$stagedSid, 'ICT-001'], [$generalSid, 'AUTO-003']] as [$sid, $programCode]) {
        $insertPeriod->bind_param('sss', $sid, $sid, $programCode);
        $insertPeriod->execute();
    }
    $insertPeriod->close();

    // CA evidence is supporting information only; one recorded CA is enough to
    // prove that the snapshot is captured without becoming an exam gate.
    $courseCode = (string)($db->query("SELECT course_code FROM program_courses WHERE program_code='ICT-001' AND year=1 ORDER BY course_code LIMIT 1")->fetch_row()[0] ?? 'DCSE-101');
    $insertCa = $db->prepare(
        "INSERT INTO semester_assessment
            (Sid, Course_Code, A1, A2, A3, T1, T2, Total_CA, semester, Year,
             status, internal_moderation_status, posted_by)
         VALUES (?, ?, 10, 10, 10, 5, 5, 40, '3', '1', 'Approved', 'approved', 'test')"
    );
    $insertCa->bind_param('ss', $stagedSid, $courseCode);
    $insertCa->execute();
    $insertCa->close();

    $evidence = wuc_progression_ca_evidence($db, $stagedSid, 'ICT-001', 1);
    yp_check('internal CA evidence is summarized independently of external exams', $evidence['recorded'] === 1 && $evidence['approved'] === 1);

    $outOfScope = wuc_progress_student_year(
        $db, $generalSid, 'ITC904', 'head_of_department',
        'external_results', 'EXT-OUTSIDE-001', '', ['ICT-001'], 'ENGICT', false
    );
    yp_check('HOS cannot progress a student outside the assigned programme scope', !$outOfScope['ok']);

    $staged = wuc_progress_student_year(
        $db, $stagedSid, 'ITC904', 'head_of_department',
        'external_results', 'TEVETA-2099-001', 'External results reviewed by HOS.',
        ['ICT-001', 'ICT-002'], 'ENGICT', false
    );
    yp_check('HOS can manually progress craft certificate to diploma using an external-results reference', $staged['ok']);
    $stagedContext = $service->resolveRegistrationTermContext($stagedSid);
    yp_check(
        'staged progression immediately resolves Diploma Year 2',
        ($stagedContext['program_code'] ?? '') === 'ICT-002' && (int)($stagedContext['year_of_study'] ?? 0) === 2
    );

    $general = wuc_progress_student_year(
        $db, $generalSid, 'ITC900', 'systems_admin',
        'examination_board', 'BOARD-2099-007', 'Approved for the next year.',
        null, null, false
    );
    yp_check('systems administrator can progress a general programme from Year 1 to Year 2', $general['ok']);
    $generalContext = $service->resolveRegistrationTermContext($generalSid);
    yp_check(
        'general progression keeps the programme and advances the registration context',
        ($generalContext['program_code'] ?? '') === 'AUTO-003' && (int)($generalContext['year_of_study'] ?? 0) === 2
    );

    $decisionStmt = $db->prepare(
        'SELECT COUNT(*) total, SUM(decision_reference = ?) staged_ref,
                SUM(decision_reference = ?) general_ref,
                SUM(ca_evidence_json IS NOT NULL) evidence_rows
           FROM student_progression_decisions WHERE student_id IN (?, ?)'
    );
    $stagedRef = 'TEVETA-2099-001';
    $generalRef = 'BOARD-2099-007';
    $decisionStmt->bind_param('ssss', $stagedRef, $generalRef, $stagedSid, $generalSid);
    $decisionStmt->execute();
    $decisionAudit = $decisionStmt->get_result()->fetch_assoc() ?: [];
    $decisionStmt->close();
    yp_check(
        'both decisions are auditable with references and CA snapshots',
        (int)($decisionAudit['total'] ?? 0) === 2
        && (int)($decisionAudit['staged_ref'] ?? 0) === 1
        && (int)($decisionAudit['general_ref'] ?? 0) === 1
        && (int)($decisionAudit['evidence_rows'] ?? 0) === 2
    );

    $autoMax = wuc_progression_program_max_year($db, 'AUTO-002', 1.0);
    yp_check('curriculum years raise max year when program_duration understates the map', $autoMax >= 2);
    $autoTarget = wuc_progression_target('AUTO-002', 1, 1.0, $autoMax);
    yp_check('AUTO-002 Year 1 can progress to Year 2 using curriculum span', $autoTarget['ok'] && (int)$autoTarget['year'] === 2);
} finally {
    $db->rollback();
    // semester_registration is MyISAM and must be explicitly cleaned.
    $cleanup = $db->prepare('DELETE FROM semester_registration WHERE student_id IN (?, ?) OR SID IN (?, ?)');
    $cleanup->bind_param('ssss', $stagedSid, $generalSid, $stagedSid, $generalSid);
    $cleanup->execute();
    $cleanup->close();
}

echo PHP_EOL . "PASS: {$pass}  FAIL: {$fail}" . PHP_EOL;
exit($fail === 0 ? 0 : 1);

