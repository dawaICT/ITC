<?php
require_once "../../includes/finance_helpers.php";
require_once dirname(__DIR__, 2) . '/includes/csrf_guard.php';
wuc_ajax_require_csrf();
ensure_roles($db, ['Head of Section','Dean','Accountant','Systems Admin']);

$expense_id = (int)($_POST['expense_id'] ?? 0);
$action = $_POST['action'] ?? 'approved';
$comments = trim($_POST['comments'] ?? '');

if ($expense_id <= 0 || !in_array($action, ['approved','rejected','requested_changes'], true)) {
  json_error('Invalid payload', 422);
}

$role = get_staff_role($db, $_SESSION['staff_id'] ?? null);
if (!$role) { json_error('Unknown role', 403); }

// Determine approval level by role
$canonicalRole = canonicalize_role($role);
$level = $canonicalRole === 'head_of_department' ? 1 : ($role === 'Dean' ? 2 : 3);
$approvalRole = $canonicalRole === 'head_of_department' ? 'Head of Section' : $role;

$stmt = $db->prepare("UPDATE finance_approvals SET action=?, comments=?, approver_id=? WHERE entity_type='expense' AND entity_id=? AND approver_role=?");
$approver_id = $_SESSION['staff_id'] ?? '';
$stmt->bind_param('sssis', $action, $comments, $approver_id, $expense_id, $approvalRole);
if (!$stmt->execute()) { json_error('Failed to update approval'); }

// If Finance approved, mark expense as approved
if ($role === 'Accountant' && $action === 'approved') {
  $db->query("UPDATE finance_expenses SET status='approved' WHERE id=".(int)$expense_id);
}
// If any rejected
if ($action === 'rejected') {
  $db->query("UPDATE finance_expenses SET status='rejected' WHERE id=".(int)$expense_id);
}

log_audit($db, $approver_id, 'expense.approval', json_encode(['expense_id'=>$expense_id,'role'=>$role,'action'=>$action]));
json_success(['ok'=>true]);


