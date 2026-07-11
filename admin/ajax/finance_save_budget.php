<?php
require_once "../../includes/finance_helpers.php";
require_once dirname(__DIR__, 2) . '/includes/csrf_guard.php';
wuc_ajax_require_csrf();
ensure_roles($db, ['Accountant','Systems Admin']);

$period_year = trim($_POST['period_year'] ?? '');
$period_term = trim($_POST['period_term'] ?? '');
$cost_center_id = (int)($_POST['cost_center_id'] ?? 0);
$allocated_amount = (float)($_POST['allocated_amount'] ?? 0);

if ($period_year === '' || $period_term === '' || $cost_center_id <= 0 || $allocated_amount <= 0) {
  json_error('Missing or invalid fields', 422);
}

$stmt = $db->prepare("INSERT INTO finance_budgets (period_year, period_term, cost_center_id, allocated_amount) VALUES (?,?,?,?)
                      ON DUPLICATE KEY UPDATE allocated_amount = VALUES(allocated_amount), updated_at = CURRENT_TIMESTAMP");
if (!$stmt) {
  json_error('Failed to prepare statement');
}
$stmt->bind_param('ssid', $period_year, $period_term, $cost_center_id, $allocated_amount);
$ok = $stmt->execute();
if (!$ok) { json_error('Failed to save'); }

log_audit($db, $_SESSION['staff_id'] ?? 'system', 'budget.save', json_encode($_POST));
json_success(['id' => $db->insert_id ?: null]);


