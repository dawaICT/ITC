<?php
require_once "../../includes/finance_helpers.php";
require_once dirname(__DIR__, 2) . '/includes/csrf_guard.php';
wuc_ajax_require_csrf();
ensure_roles($db, ['Accountant','Systems Admin','Head of Section']);

$vendor_id = (int)($_POST['vendor_id'] ?? 0);
$category = trim($_POST['category'] ?? 'Academic');
$description = trim($_POST['description'] ?? '');
$amount = (float)($_POST['amount'] ?? 0);
$currency_code = trim($_POST['currency_code'] ?? 'ZMW');
$expense_date = trim($_POST['expense_date'] ?? date('Y-m-d'));

if ($vendor_id <= 0 || $description === '' || $amount <= 0) {
  json_error('Missing or invalid fields', 422);
}

$stmt = $db->prepare("INSERT INTO finance_expenses (vendor_id, category, description, amount, currency_code, expense_date, status, created_by) VALUES (?,?,?,?,?,?, 'pending', ?)");
if (!$stmt) { json_error('Failed to prepare'); }
$created_by = $_SESSION['staff_id'] ?? 'system';
$stmt->bind_param('issdsss', $vendor_id, $category, $description, $amount, $currency_code, $expense_date, $created_by);
if (!$stmt->execute()) { json_error('Failed to save'); }

// Create approval flow entries (HOS -> Dean -> Finance)
$expense_id = $db->insert_id;
$levels = [1=>'Head of Section', 2=>'Dean', 3=>'Accountant'];
foreach ($levels as $lvl => $role) {
  $appr = $db->prepare("INSERT INTO finance_approvals (entity_type, entity_id, approval_level, approver_role, action) VALUES ('expense', ?, ?, ?, 'requested_changes')");
  $appr->bind_param('iis', $expense_id, $lvl, $role);
  $appr->execute();
}

log_audit($db, $created_by, 'expense.submit', json_encode(['expense_id'=>$expense_id]));
json_success(['id'=>$expense_id]);


