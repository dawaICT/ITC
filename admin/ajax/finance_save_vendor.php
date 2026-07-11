<?php
require_once "../../includes/finance_helpers.php";
require_once dirname(__DIR__, 2) . '/includes/csrf_guard.php';
wuc_ajax_require_csrf();
ensure_roles($db, ['Accountant','Systems Admin']);

$id = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$tin = trim($_POST['tin'] ?? '');
$bank_account = trim($_POST['bank_account'] ?? '');
$contact_email = trim($_POST['contact_email'] ?? '');
$contact_phone = trim($_POST['contact_phone'] ?? '');
$status = in_array($_POST['status'] ?? 'active', ['active','inactive'], true) ? $_POST['status'] : 'active';

if ($name === '') { json_error('Name required', 422); }

if ($id > 0) {
  $stmt = $db->prepare("UPDATE finance_vendors SET name=?, tin=?, bank_account=?, contact_email=?, contact_phone=?, status=? WHERE id=?");
  $stmt->bind_param('ssssssi', $name, $tin, $bank_account, $contact_email, $contact_phone, $status, $id);
  $ok = $stmt->execute();
  if (!$ok) { json_error('Update failed'); }
} else {
  $stmt = $db->prepare("INSERT INTO finance_vendors (name, tin, bank_account, contact_email, contact_phone, status) VALUES (?,?,?,?,?,?)");
  $stmt->bind_param('ssssss', $name, $tin, $bank_account, $contact_email, $contact_phone, $status);
  $ok = $stmt->execute();
  if (!$ok) { json_error('Insert failed'); }
  $id = $db->insert_id;
}

log_audit($db, $_SESSION['staff_id'] ?? 'system', 'vendor.save', json_encode(['id'=>$id]));
json_success(['id'=>$id]);


