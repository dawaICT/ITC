<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once dirname(__DIR__) . '/db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once dirname(__DIR__) . '/includes/ca_helpers.php';
require_once dirname(__DIR__) . '/includes/finance_guard.php';
require_once dirname(__DIR__) . '/includes/academic_settings_helper.php';

$staffId = '';
$r = $db->query("SELECT staff_id FROM course_lecturer WHERE staff_id IS NOT NULL AND staff_id <> '' LIMIT 1");
if ($r && ($row = $r->fetch_assoc())) { $staffId = (string)$row['staff_id']; }
echo "staff=$staffId\n";

$tables = $db->query("SHOW TABLES");
$norm = [];
while ($row = $tables->fetch_row()) {
  if (stripos($row[0], 'student_course') !== false || stripos($row[0], 'course_offer') !== false || stripos($row[0], 'curriculum') !== false || stripos($row[0], 'academic_period') !== false) {
    $norm[] = $row[0];
  }
}
echo "related_tables=" . implode(',', $norm) . "\n";
echo "normalized_ready=" . (ca_normalized_tables_ready($db) ? 'yes' : 'no') . "\n";
echo "normalized_reg_ready=" . (ca_normalized_registration_tables_ready($db) ? 'yes' : 'no') . "\n";

$assigned = [];
$stmt = $db->prepare("SELECT DISTINCT c.course_code FROM course_lecturer lc INNER JOIN courses c ON c.course_code = lc.course_code WHERE lc.staff_id = ? LIMIT 5");
$stmt->bind_param('s', $staffId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) { $assigned[] = $row['course_code']; }
$stmt->close();
echo "courses=" . implode(',', $assigned) . "\n";

$years = wuc_academic_year_options($db);
echo "year_options_sample=" . json_encode(array_slice($years, 0, 5)) . "\n";

foreach ($assigned as $code) {
  echo "---- $code ----\n";
  echo "has_norm_reg=" . (ca_course_has_normalized_registrations($db, $code) ? 'yes' : 'no') . "\n";
  $mode = ca_course_period_mode($db, $code);
  echo "mode=" . json_encode($mode) . "\n";
  $diag = ca_registration_diagnostics($db, $code);
  echo "diag=" . json_encode($diag) . "\n";
  $yearCandidates = [];
  foreach ($years as $y) {
    if (is_array($y)) {
      $yearCandidates[] = (string)($y['value'] ?? $y['label'] ?? $y['year'] ?? '');
    } else {
      $yearCandidates[] = (string)$y;
    }
  }
  $yearCandidates = array_values(array_filter(array_unique($yearCandidates)));
  if (!$yearCandidates) { $yearCandidates = [(string)date('Y'), (string)date('Y') . '/' . (date('Y')+1)]; }
  foreach (array_slice($yearCandidates, 0, 4) as $y) {
    foreach (['1','2','3','4'] as $p) {
      try {
        $stu = ca_fetch_course_students($db, $code, $p, $y);
        if ($stu['count'] > 0) {
          echo "HIT course=$code year=$y period=$p count={$stu['count']}\n";
        }
      } catch (Throwable $e) {
        echo "FETCH_FAIL course=$code year=$y period=$p err=" . $e->getMessage() . "\n";
      }
    }
  }
}

// DESCRIBE student_courses
if (ca_table_exists($db, 'student_courses')) {
  echo "-- student_courses --\n";
  $d = $db->query('DESCRIBE student_courses');
  while ($row = $d->fetch_assoc()) echo $row['Field'].'|'.$row['Type']."\n";
  $scSid = ca_student_courses_sid_column($db);
  echo "sc_sid_col=" . var_export($scSid, true) . "\n";
}

// Sample course_registration years
$r = $db->query("SELECT course_code, academic_year, Year, semester, COUNT(*) c FROM course_registration GROUP BY course_code, academic_year, Year, semester ORDER BY c DESC LIMIT 15");
echo "-- top course_registration groups --\n";
while ($row = $r->fetch_assoc()) echo json_encode($row) . "\n";

echo "DONE\n";
