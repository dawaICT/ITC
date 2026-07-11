<?php
require_once "../../includes/finance_helpers.php";
require_once dirname(__DIR__, 2) . '/includes/csrf_guard.php';
wuc_ajax_require_csrf();
ensure_roles($db, ['Systems Admin','Accountant']);

// Seed cost centers from existing programs and add shared centers
$created = 0; $exists = 0;

// Programs as cost centers
$progRes = $db->query("SELECT program_code, program_name FROM programs");
if ($progRes) {
  $progRes->data_seek(0);
  while ($p = $progRes->fetch_assoc()) {
    $stmt = $db->prepare("INSERT IGNORE INTO finance_cost_centers (center_type, code, name) VALUES ('program', ?, ?)");
    if ($stmt) { $stmt->bind_param('ss', $p['program_code'], $p['program_name']); $ok = $stmt->execute(); $created += ($db->affected_rows > 0) ? 1 : 0; $exists += ($db->affected_rows === 0) ? 1 : 0; }
  }
}

// Shared centers
$shared = [
  ['library', 'LIB', 'Library'],
  ['elearning', 'ELRN', 'E-learning'],
  ['lab', 'LAB', 'Laboratories']
];
foreach ($shared as $s) {
  [$type,$code,$name] = $s;
  $stmt = $db->prepare("INSERT IGNORE INTO finance_cost_centers (center_type, code, name) VALUES (?,?,?)");
  if ($stmt) { $stmt->bind_param('sss', $type, $code, $name); $stmt->execute(); $created += ($db->affected_rows > 0) ? 1 : 0; }
}

log_audit($db, $_SESSION['staff_id'] ?? 'system', 'cost_centers.seed', json_encode(['created'=>$created,'exists'=>$exists]));
json_success(['created'=>$created,'exists'=>$exists]);


