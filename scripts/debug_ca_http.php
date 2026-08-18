<?php
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/ca_helpers.php';
foreach (['DCSE-101','DCSE-103'] as $code) {
  echo $code . ' external_short=' . (ca_is_external_short_course($db, $code) ? 'yes' : 'no') . "\n";
  $mode = ca_course_period_mode($db, $code);
  echo json_encode($mode) . "\n";
}
// Try HTTP fetch of page
$ch = curl_init('http://localhost/wucportal/lecturers/upload_ca.php');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => false,
  CURLOPT_HEADER => true,
  CURLOPT_TIMEOUT => 15,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
echo "HTTP=$code err=$err\n";
if ($resp !== false) {
  $parts = explode("\r\n\r\n", $resp, 2);
  echo "HEADERS:\n" . substr($parts[0], 0, 800) . "\n";
  $body = $parts[1] ?? '';
  if (preg_match('/Fatal error|mysqli_sql_exception|Warning:|Parse error|Access denied|Please log in/i', $body, $m)) {
    echo "BODY_SIGNAL=" . $m[0] . "\n";
  }
  echo "BODY_LEN=" . strlen($body) . "\n";
  echo "BODY_SNIP=\n" . substr(strip_tags($body), 0, 500) . "\n";
}
