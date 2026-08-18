<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/includes/applicant_workflow.php';

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label, string $detail = '') use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo '[PASS] ' . $label . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL;
    } else {
        $failed++;
        echo '[FAIL] ' . $label . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL;
    }
};

$actor = 'ITC902';
$email = 'applicant.conversion.e2e@example.test';
$nrc = '776655/44/2';
$mobile = '0976112233';
$rollbackEmail = 'applicant.rollback.e2e@example.test';
$rollbackNrc = '776655/44/3';
$fixtureSids = [];

$cleanupIdentity = static function (mysqli $db, string $email, string $nrc) use (&$fixtureSids): void {
    $stmt = $db->prepare('SELECT SID FROM students WHERE email = ? OR nrc_pass = ?');
    $stmt->bind_param('ss', $email, $nrc);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $fixtureSids[] = (string)$row['SID'];
    }
    $stmt->close();

    foreach (array_unique($fixtureSids) as $sid) {
        foreach ([
            ['processed_applicants_added', 'student_id'],
            ['finance_student_sponsors', 'student_id'],
            ['course_registration', 'Sid'],
            ['student_courses', 'student_id'],
            ['semester_registration', 'student_id'],
            ['portal_alerts', 'user_id'],
            ['invoices', 'student_id'],
            ['student_login', 'Sid'],
            ['student_program', 'Sid'],
            ['students', 'SID'],
        ] as [$table, $column]) {
            $delete = $db->prepare("DELETE FROM `{$table}` WHERE `{$column}` = ?");
            $delete->bind_param('s', $sid);
            $delete->execute();
            $delete->close();
        }
    }

    foreach (['online_applicants', 'processed_applicants'] as $table) {
        $delete = $db->prepare("DELETE FROM `{$table}` WHERE email = ? OR nrc_pass = ?");
        $delete->bind_param('ss', $email, $nrc);
        $delete->execute();
        $delete->close();
    }
};

$cleanupIdentity($db, $email, $nrc);
$cleanupIdentity($db, $rollbackEmail, $rollbackNrc);

$insertApplicant = static function (mysqli $db, string $email, string $nrc, string $mobile, string $program): int {
    $year = date('Y');
    $intake = 'January ' . $year;
    $stmt = $db->prepare(
        "INSERT INTO online_applicants
            (title, Fname, Lname, sex, nrc_pass, country, dob, mobile, email, status,
             h_addre, p_addre, sponsor, next_kin, next_kin_mobile, relat, program,
             intake, mode, year, dte_adm)
         VALUES ('Ms', 'Exhibition', 'Applicant', 'F', ?, 'Zambia', '2001-06-15', ?, ?, 'pending',
                 'Test address', 'Test address', 'Self', 'Test Guardian', '0976000000', 'Parent', ?,
                 ?, 'Full-time', ?, NOW())"
    );
    $stmt->bind_param('ssssss', $nrc, $mobile, $email, $program, $intake, $year);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
};

try {
    $onlineId = $insertApplicant($db, $email, $nrc, $mobile, 'ICT-001');
    $result = wuc_accept_online_applicant($db, $onlineId, $actor);
    $check(!empty($result['success']), 'online application converts atomically', json_encode($result));
    $sid = (string)($result['student_id'] ?? '');
    if ($sid !== '') {
        $fixtureSids[] = $sid;
    }

    $stmt = $db->prepare('SELECT COUNT(*) total FROM online_applicants WHERE id = ?');
    $stmt->bind_param('i', $onlineId);
    $stmt->execute();
    $sourceCount = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    $check($sourceCount === 0, 'accepted application leaves the inbox');

    $processedId = (int)($result['processed_id'] ?? 0);
    $stmt = $db->prepare("SELECT status, applicant_id, processed_by FROM processed_applicants WHERE id = ?");
    $stmt->bind_param('i', $processedId);
    $stmt->execute();
    $processed = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $check(($processed['status'] ?? '') === 'accepted', 'processed application records the decision');
    $check((int)($processed['applicant_id'] ?? 0) === $onlineId, 'processed application retains the source identity');
    $check(($processed['processed_by'] ?? '') === $actor, 'processed application records the officer');

    $stmt = $db->prepare(
        'SELECT s.SID, sl.must_change_password, sp.program_code
         FROM students s
         INNER JOIN student_login sl ON sl.Sid = s.SID
         INNER JOIN student_program sp ON sp.Sid = s.SID
         WHERE s.SID = ? LIMIT 1'
    );
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $check(($student['SID'] ?? '') === $sid, 'student, login, and programme rows commit together');
    $check((int)($student['must_change_password'] ?? 0) === 1, 'new student must replace the initial password');
    $check(($student['program_code'] ?? '') === 'ICT-001', 'selected programme is preserved');

    $stmt = $db->prepare('SELECT COUNT(*) total FROM student_courses WHERE student_id = ?');
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $courseCount = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    $check($courseCount > 0, 'programme courses are assigned', 'courses=' . $courseCount);

    $stmt = $db->prepare('SELECT COUNT(*) total FROM processed_applicants_added WHERE applicant_id = ? AND student_id = ?');
    $stmt->bind_param('is', $processedId, $sid);
    $stmt->execute();
    $tracked = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    $check($tracked === 1, 'conversion tracker links applicant and student exactly once');

    $stmt = $db->prepare("SELECT COUNT(*) total FROM audit_logs WHERE user_id = ? AND action = 'admissions.application_accepted_and_converted' AND record_id = ?");
    $recordId = (string)$processedId;
    $stmt->bind_param('ss', $actor, $recordId);
    $stmt->execute();
    $auditCount = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    $check($auditCount >= 1, 'officer conversion is audit logged');

    $second = wuc_accept_online_applicant($db, $onlineId, $actor);
    $check(empty($second['success']), 'replaying the same acceptance is rejected');
    $stmt = $db->prepare('SELECT COUNT(*) total FROM students WHERE email = ? OR nrc_pass = ?');
    $stmt->bind_param('ss', $email, $nrc);
    $stmt->execute();
    $studentCount = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    $check($studentCount === 1, 'replay cannot create a duplicate student');

    $rollbackId = $insertApplicant($db, $rollbackEmail, $rollbackNrc, '0976112244', 'CSE');
    $rollback = wuc_accept_online_applicant($db, $rollbackId, $actor);
    $check(empty($rollback['success']), 'invalid retired programme blocks conversion');
    $stmt = $db->prepare('SELECT COUNT(*) total FROM online_applicants WHERE id = ?');
    $stmt->bind_param('i', $rollbackId);
    $stmt->execute();
    $rollbackSource = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    $check($rollbackSource === 1, 'failed conversion restores the original application');
    $stmt = $db->prepare('SELECT COUNT(*) total FROM processed_applicants WHERE email = ?');
    $stmt->bind_param('s', $rollbackEmail);
    $stmt->execute();
    $rollbackProcessed = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    $check($rollbackProcessed === 0, 'failed conversion leaves no processed duplicate');
} finally {
    $cleanupIdentity($db, $email, $nrc);
    $cleanupIdentity($db, $rollbackEmail, $rollbackNrc);
}

echo PHP_EOL . "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
