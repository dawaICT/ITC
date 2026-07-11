<?php
require_once "../../includes/finance_helpers.php";
ensure_roles($db, ['Accountant','Systems Admin','Dean','Head of Section']);

$rows = [];
$res = $db->query("SELECT id, center_type, code, name FROM finance_cost_centers WHERE is_active = 1 ORDER BY center_type, name");
if ($res) {
  while ($r = $res->fetch_assoc()) { $rows[] = $r; }
}
json_success($rows);


