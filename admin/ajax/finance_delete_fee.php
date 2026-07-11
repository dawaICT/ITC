<?php
require_once "../../includes/finance_helpers.php";
require_once dirname(__DIR__, 2) . '/includes/csrf_guard.php';
wuc_ajax_require_csrf();
ensure_roles($db, ['Accountant', 'Systems Admin']);

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    json_error('Invalid ID');
}

$stmt = $db->prepare("DELETE FROM fee_structure WHERE id = ?");
$stmt->bind_param('i', $id);

if ($stmt->execute()) {
    log_audit($db, $_SESSION['staff_id'] ?? 'system', 'fee.delete', json_encode(['id' => $id]));
    json_success();
} else {
    json_error('Database error: ' . $db->error);
}
?>