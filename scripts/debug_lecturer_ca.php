<?php
// Simulate lecturer session and capture fatals from upload_ca bootstrap path
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/wucportal/lecturers/upload_ca.php';
$_SERVER['SCRIPT_NAME'] = '/wucportal/lecturers/upload_ca.php';
session_start();
require_once dirname(__DIR__) . '/db/connect.php';
// find a lecturer with course assignments
$staffId = '';
$r = $db->query("SELECT staff_id FROM course_lecturer WHERE staff_id IS NOT NULL AND staff_id <> '' LIMIT 1");
if ($r && ($row = $r->fetch_assoc())) { $staffId = (string)$row['staff_id']; }
if ($staffId === '') {
  echo "NO_LECTURER_ASSIGNED\n";
  exit(0);
}
$_SESSION['staff_id'] = $staffId;
$_SESSION['user_id'] = $staffId;
$_SESSION['role'] = 'lecturer';
$_SESSION['csrf_token'] = bin2hex(random_bytes(16));
echo "Using staff_id=$staffId\n";
ob_start();
try {
  // Include only the data portion by requiring helpers the page uses
  require_once dirname(__DIR__) . '/includes/ca_helpers.php';
  require_once dirname(__DIR__) . '/includes/finance_guard.php';
  require_once dirname(__DIR__) . '/includes/academic_settings_helper.php';
  ca_ensure_schema($db);
  $assigned = [];
  $stmt = $db->prepare("SELECT DISTINCT c.course_code, COALESCE(c.course_name,'') AS course_name FROM course_lecturer lc INNER JOIN courses c ON c.course_code = lc.course_code WHERE lc.staff_id = ? ORDER BY c.course_code");
  $stmt->bind_param('s', $staffId);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) { $assigned[] = $row; }
  $stmt->close();
  echo 'assigned_courses=' . count($assigned) . "\n";
  if ($assigned) {
    $code = $assigned[0]['course_code'];
    echo "sample_course=$code\n";
    $mode = ca_course_period_mode($db, $code);
    echo 'period_mode=' . json_encode($mode) . "\n";
    $years = function_exists('wuc_academic_year_options') ? wuc_academic_year_options($db) : [];
    echo 'years=' . count($years) . "\n";
    // try common periods
    foreach (['1','2'] as $p) {
      foreach (array_slice($years ?: [date('Y')], 0, 3) as $y) {
        $y = (string)(is_array($y) ? ($y['value'] ?? $y['year'] ?? reset($y)) : $y);
        $stu = ca_fetch_course_students($db, $code, $p, $y);
        echo "students course=$code period=$p year=$y count={$stu['count']}\n";
        if ($stu['count'] > 0) break 2;
      }
    }
    $diag = ca_registration_diagnostics($db, $code);
    echo 'diag=' . json_encode($diag) . "\n";
  }
  // Exercise AJAX endpoint logic tables
  if (function_exists('is_student_allowed_ca')) {
    echo "is_student_allowed_ca exists\n";
  } else {
    echo "is_student_allowed_ca MISSING\n";
  }
  if (function_exists('isLecturerAssignedToCourse')) {
    echo "isLecturerAssignedToCourse exists\n";
  } else {
    echo "isLecturerAssignedToCourse MISSING\n";
  }
  echo "HELPERS_OK\n";
} catch (Throwable $e) {
  echo 'FAIL: ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
}
ob_end_clean();
