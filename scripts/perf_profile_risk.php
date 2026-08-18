<?php
declare(strict_types=1);
$root = dirname(__DIR__);
chdir($root);
require_once $root . '/db/connect.php';
require_once $root . '/includes/academic_risk_engine.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$sid = '';
$r = $db->query('SELECT SID FROM students LIMIT 1');
if ($row = $r->fetch_assoc()) {
    $sid = (string)$row['SID'];
}
echo "SID={$sid}\n";

// Warm connection
$db->query('SELECT 1');

$steps = [
    'thresholds' => fn() => wuc_risk_load_thresholds($db),
    'courses' => fn() => wuc_risk_student_courses($db, $sid),
    'program' => fn() => wuc_risk_student_program($db, $sid),
];
$courses = wuc_risk_student_courses($db, $sid);
$steps['attendance'] = fn() => wuc_risk_attendance_metrics($db, $sid, $courses);
$steps['assessment'] = fn() => wuc_risk_assessment_metrics($db, $sid, $courses);
$steps['assignments'] = fn() => wuc_risk_assignment_metrics($db, $sid, $courses);
$steps['activity'] = fn() => wuc_risk_activity_metrics($db, $sid);
$steps['progress'] = fn() => wuc_risk_progress_metrics($db, $sid, $courses);
$steps['fees'] = fn() => wuc_risk_fee_metrics($db, $sid);
$steps['registration'] = fn() => wuc_risk_registration_metrics($db, $sid, $courses);
$steps['failed'] = fn() => wuc_risk_failed_course_metrics($db, $sid, $courses);

foreach ($steps as $name => $fn) {
    $t0 = hrtime(true);
    try {
        $fn();
        $ms = (hrtime(true) - $t0) / 1e6;
        echo sprintf("%-14s %.2f ms\n", $name, $ms);
    } catch (Throwable $e) {
        echo sprintf("%-14s FAIL %s\n", $name, $e->getMessage());
    }
}

$t0 = hrtime(true);
$risk = wuc_academic_risk_analyze_student($db, $sid, false);
echo sprintf("FULL analyze   %.2f ms\n", (hrtime(true) - $t0) / 1e6);

// Cached read from summary
$t0 = hrtime(true);
$stmt = $db->prepare('SELECT risk_score, risk_level, risk_reason, recommended_action, data_quality, generated_at
                      FROM student_risk_summary WHERE student_id = ? ORDER BY generated_at DESC LIMIT 1');
$stmt->bind_param('s', $sid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
echo sprintf("cached read    %.2f ms has=%s\n", (hrtime(true) - $t0) / 1e6, $row ? 'yes' : 'no');
