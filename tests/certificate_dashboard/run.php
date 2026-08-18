<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/db/connect.php';
require dirname(__DIR__, 2) . '/includes/elearning_access.php';
require dirname(__DIR__, 2) . '/includes/academic_risk_engine.php';
require dirname(__DIR__, 2) . '/includes/course_recommendation_engine.php';
require dirname(__DIR__, 2) . '/includes/elearning_insights_engine.php';
require dirname(__DIR__, 2) . '/students/includes/student_fee_records.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$sid = 'CSE26456789';
$pass = 0;
$fail = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$courses = getStudentEnrolledCourses($db, $sid);
sort($courses);
$expectedCourses = ['DCSE-101', 'DCSE-103', 'DCSE-104', 'DCSE-105', 'DCSE-106', 'DCSE-107', 'DCSE-108', 'DCSE-109'];
$check('dashboard resolves the 8 active CD012 certificate modules', $courses === $expectedCourses, json_encode($courses));
$check('retired and unrelated modules are excluded', !array_intersect(['DCSE-102', 'COM101', 'CSC101', 'CSC102', 'MAT101'], $courses));

$fees = student_fee_current_program_summary($db, $sid);
$check('fee helper recognizes the active fee account', !empty($fees['has_fees']));
$check('dashboard fee totals match the paid account',
    abs((float)$fees['total_due'] - 15000.0) < 0.01
    && abs((float)$fees['total_paid'] - 15000.0) < 0.01
    && abs((float)$fees['balance']) < 0.01,
    json_encode([$fees['total_due'], $fees['total_paid'], $fees['balance']])
);

$risk = wuc_academic_risk_analyze_student($db, $sid, false);
$riskCourses = $risk['course_codes'] ?? [];
sort($riskCourses);
$failedCodes = $risk['metrics']['failed_courses']['failed_course_codes'] ?? [];
$check('risk engine uses the dashboard registration scope', $riskCourses === $expectedCourses);
$check('risk engine excludes historical CA modules', !array_intersect(['DCSE-102', 'COM101'], $failedCodes), json_encode($failedCodes));
$check('external-exam risk guidance labels CA as supporting evidence',
    ($risk['program']['examination_type'] ?? '') === 'external'
    && str_contains(implode(' ', $risk['risk_reasons'] ?? []), 'External examination results determine the final outcome.')
);

$riskAlertStmt = $db->prepare(
    "SELECT COUNT(*) AS total, GROUP_CONCAT(alert_type ORDER BY alert_type) AS types
       FROM portal_alerts
      WHERE user_id = ? AND status IN ('unread', 'read')
        AND alert_type IN ('academic_risk', 'student_dashboard_ai_academic_insight', 'el_academic_risk')"
);
$riskAlertStmt->bind_param('s', $sid);
$riskAlertStmt->execute();
$riskAlerts = $riskAlertStmt->get_result()->fetch_assoc() ?: [];
$riskAlertStmt->close();
$check('dashboard exposes one canonical active academic-risk notification',
    (int)($riskAlerts['total'] ?? 0) === 1 && ($riskAlerts['types'] ?? '') === 'academic_risk',
    json_encode($riskAlerts)
);
$check('CA percentages are normalized to 100 rather than weighted twice',
    (float)($risk['metrics']['assessment']['average_mark'] ?? 0) > 25.0
    && (float)($risk['metrics']['assessment']['average_mark'] ?? 0) <= 100.0,
    (string)($risk['metrics']['assessment']['average_mark'] ?? 'null')
);

$recommendations = wuc_course_recommendations($db, $sid);
$check('internal CA does not create external-exam retake decisions',
    ($recommendations['examination_type'] ?? '') === 'external'
    && empty($recommendations['retake'])
);

$insights = wuc_el_student_insights($db, $sid);
$revisionTopics = $insights['revision_topics'] ?? [];
$check('study checklist is limited to active curriculum modules',
    !array_diff($revisionTopics, $expectedCourses)
    && !array_intersect(['DCSE-102', 'COM101'], $revisionTopics),
    json_encode($revisionTopics)
);

$stmt = $db->prepare(
    "SELECT sp.current_year_number, sp.current_term_number, sp.semester,
            sr.year_of_study, sr.semester AS registered_term
       FROM student_program sp
       JOIN semester_registration sr ON sr.id = (
           SELECT MAX(sr2.id) FROM semester_registration sr2
            WHERE (sr2.student_id = sp.Sid OR sr2.SID = sp.Sid)
              AND LOWER(COALESCE(sr2.registration_status, 'registered')) = 'registered'
       )
      WHERE sp.Sid = ? AND LOWER(COALESCE(sp.status, 'active')) = 'active'
      ORDER BY sp.id DESC LIMIT 1"
);
$stmt->bind_param('s', $sid);
$stmt->execute();
$period = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();
$check('student_program period pointers match the registered term',
    (int)($period['current_year_number'] ?? 0) === (int)($period['year_of_study'] ?? -1)
    && (int)($period['current_term_number'] ?? 0) === (int)($period['registered_term'] ?? -1)
    && (int)($period['semester'] ?? 0) === (int)($period['registered_term'] ?? -1),
    json_encode($period)
);

echo PHP_EOL . "PASS: {$pass}  FAIL: {$fail}" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
