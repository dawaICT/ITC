<?php
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/finance_guard.php';
require_once dirname(__DIR__) . '/includes/ca_helpers.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$sid = 'EXH-ALU-001';
echo "period1=" . json_encode(is_student_allowed_ca($db, $sid, '2026', '1')) . "\n";
echo "period2=" . json_encode(is_student_allowed_ca($db, $sid, '2026', '2')) . "\n";

// Find unlocked assessment row or different student
$r = $db->query("SELECT Sid, Course_Code, semester, Year, status FROM semester_assessment WHERE status IS NULL OR LOWER(status) NOT IN ('approved','published') LIMIT 5");
echo "-- unlocked rows --\n";
while ($row = $r->fetch_assoc()) echo json_encode($row) . "\n";

$stu = ca_fetch_course_students($db, 'DCSE-101', '1', '2026');
foreach ($stu['students'] as $s) {
  $sid2 = $s['Sid'];
  $st = $db->prepare("SELECT status FROM semester_assessment WHERE Sid=? AND Course_Code='DCSE-101' AND semester='1' AND Year='2026' LIMIT 1");
  $st->bind_param('s', $sid2);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();
  $status = $row['status'] ?? '(none)';
  echo "student=$sid2 status=$status elig=" . json_encode(is_student_allowed_ca($db, $sid2, '2026', '1')) . "\n";
  if (!in_array(strtolower((string)$status), ['approved','published'], true)) {
    $save = ca_save_component($db, $sid2, 'DCSE-101', '1', '2026', 'term', 'A2', 65.0, 'EXH-LEC-001');
    echo "save_A2=" . json_encode($save) . "\n";
  }
}
echo "DONE\n";
