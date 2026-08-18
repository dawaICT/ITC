<?php
require_once dirname(__DIR__) . '/db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once dirname(__DIR__) . '/includes/ca_helpers.php';
try {
  ca_ensure_schema($db);
  echo "ca_ensure_schema OK\n";
} catch (Throwable $e) {
  echo "ca_ensure_schema FAIL: " . $e->getMessage() . "\n";
}
foreach (['continuous_assessment','semester_assessment','course_lecturer','course_registration','student_course_registration','portal_settings','student_courses'] as $t) {
  $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($t) . "'");
  echo $t . ': ' . (($r && $r->num_rows) ? 'OK' : 'MISSING') . "\n";
  if ($r) $r->free();
}
foreach (['continuous_assessment','semester_assessment','course_registration'] as $t) {
  $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($t) . "'");
  if ($r && $r->num_rows) {
    echo "-- DESCRIBE $t --\n";
    $d = $db->query("DESCRIBE `$t`");
    while ($row = $d->fetch_assoc()) {
      echo $row['Field'] . ' | ' . $row['Type'] . "\n";
    }
  }
  if ($r) $r->free();
}
