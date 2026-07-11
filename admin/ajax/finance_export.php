<?php
require_once "../../includes/finance_helpers.php";
ensure_roles($db, ['Accountant','Systems Admin']);

$type = $_GET['type'] ?? '';
$allowedTypes = ['budgets', 'ar'];
if (!in_array($type, $allowedTypes, true)) {
  $type = '';
}
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="finance_export_'.($type?:'all').'.csv"');

if ($type === 'budgets') {
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Year', 'Term', 'Center', 'Amount']);
  $sql = "SELECT b.period_year, b.period_term, CONCAT(cc.center_type,' - ',cc.name) AS center, b.allocated_amount
          FROM finance_budgets b JOIN finance_cost_centers cc ON cc.id=b.cost_center_id ORDER BY b.period_year DESC";
  $res = $db->query($sql);
  while ($r = $res->fetch_assoc()) {
    fputcsv($out, [$r['period_year'], $r['period_term'], $r['center'], number_format((float)$r['allocated_amount'], 2, '.', '')]);
  }
  fclose($out);
  exit;
}

if ($type === 'ar') {
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Student', 'Overdue Amount']);
  $sql = "SELECT student_id, SUM(amount) AS overdue FROM finance_student_installments WHERE status='pending' AND due_date < CURDATE() GROUP BY student_id";
  $res = $db->query($sql);
  while ($r = $res->fetch_assoc()) {
    fputcsv($out, [$r['student_id'], number_format((float)$r['overdue'], 2, '.', '')]);
  }
  fclose($out);
  exit;
}

echo "No data\n";


