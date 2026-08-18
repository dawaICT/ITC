<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once dirname(__DIR__) . '/db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once dirname(__DIR__) . '/includes/ca_helpers.php';
require_once dirname(__DIR__) . '/includes/finance_guard.php';

$course = 'DCSE-101';
$period = '1';
$year = '2026';
$stu = ca_fetch_course_students($db, $course, $period, $year);
echo "students=" . $stu['count'] . "\n";
$sid = $stu['students'][0]['Sid'] ?? '';
echo "sid=$sid\n";
if ($sid === '') exit;

$elig = is_student_allowed_ca($db, $sid, $year, $period);
echo "elig=" . json_encode($elig) . "\n";
$reg = ca_student_registered($db, $sid, $course, $period, $year);
echo "registered=" . ($reg ? 'yes' : 'no') . "\n";

// Dry-run save path pieces
$normReady = ca_normalized_tables_ready($db);
echo "norm_ready=" . ($normReady ? 'yes' : 'no') . "\n";
try {
  $bridge = ca_ensure_normalized_registration_bridge($db, $sid, $course, $period, $year);
  echo "bridge_id=" . var_export($bridge, true) . "\n";
} catch (Throwable $e) {
  echo "bridge_fail=" . $e->getMessage() . "\n";
}

$guard = ca_save_entry_guard($db, $sid, $course, $period, $year, 'term');
echo "guard=" . json_encode($guard) . "\n";
$pay = ca_payment_check($db, $sid, $period, $year, ['A1' => 70]);
echo "pay=" . json_encode($pay) . "\n";
$shape = ca_validate_period_component_shape($db, $sid, $course, $period, ['A1' => 70]);
echo "shape=" . json_encode($shape) . "\n";

// Attempt actual save in a transaction that we roll back? Better call save and report
$save = ca_save_component($db, $sid, $course, $period, $year, 'term', 'A1', 70.0, 'EXH-LEC-001');
echo "save=" . json_encode($save) . "\n";

// Check assessment_components exists
foreach (['assessment_schemes','assessment_components','student_assessment_marks','student_course_results'] as $t) {
  $r=$db->query("SHOW TABLES LIKE '$t'");
  echo "$t=" . (($r&&$r->num_rows)?'OK':'MISSING') . "\n";
}
