<?php
declare(strict_types=1);
/**
 * Smoke tests for StudentAcademicWorkflowService.
 * Usage: C:\xampp\php\php.exe scripts/test_student_academic_workflow.php
 */
putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../students/includes/StudentAcademicWorkflowService.php';

$failures = 0;

function assert_true(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        echo "FAIL: {$msg}\n";
        $failures++;
    } else {
        echo "OK: {$msg}\n";
    }
}

assert_true(
    StudentAcademicWorkflowService::requiredFeePercentage('term', 1) === 50.0,
    'Term 1 requires 50%'
);
assert_true(
    StudentAcademicWorkflowService::requiredFeePercentage('term', 2) === 100.0,
    'Term 2 requires 100%'
);
assert_true(
    StudentAcademicWorkflowService::requiredFeePercentage('semester', 1) === 50.0,
    'Semester 1 requires 50%'
);
assert_true(
    StudentAcademicWorkflowService::requiredFeePercentage('semester', 2) === 100.0,
    'Semester 2 requires 100%'
);

$svc = new StudentAcademicWorkflowService($db);

// Pick any student with a program
$sid = null;
if ($r = $db->query("SELECT sp.Sid FROM student_program sp INNER JOIN students s ON s.SID = sp.Sid LIMIT 1")) {
    if ($row = $r->fetch_assoc()) {
        $sid = (string)$row['Sid'];
    }
    $r->free();
}

if ($sid) {
    $period = $svc->getActiveAcademicPeriod($sid);
    assert_true(isset($period['ok']), 'getActiveAcademicPeriod returns ok key for sample student');
    if ($period['ok']) {
        $fee = $svc->checkFeeEligibility($sid, $period);
        assert_true(isset($fee['is_eligible'], $fee['required_percentage']), 'checkFeeEligibility structure');
        $reg = $svc->checkStudentRegistration($sid, $period);
        assert_true(isset($reg['is_registered']), 'checkStudentRegistration structure');
    }
} else {
    echo "SKIP: no sample student in DB\n";
}

// Schema columns
foreach (['registration_open', 'docket_open', 'exam_slip_open'] as $col) {
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $t = 'academic_periods';
    $stmt->bind_param('ss', $t, $col);
    $stmt->execute();
    $exists = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
    $stmt->close();
    assert_true($exists, "academic_periods.{$col} exists");
}

echo $failures === 0 ? "All tests passed.\n" : "{$failures} test(s) failed.\n";
exit($failures > 0 ? 1 : 0);
