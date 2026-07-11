<?php
require_once "../../includes/finance_helpers.php";
require_once dirname(__DIR__, 2) . '/includes/csrf_guard.php';
wuc_ajax_require_csrf();
ensure_roles($db, ['Accountant', 'Systems Admin']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_error('Method not allowed', 405);
}

// CSRF is enforced above by wuc_ajax_require_csrf() (accepts X-CSRF-Token header
// or csrf_token POST field).

$planId = (int)($_POST['plan_id'] ?? 0);
if ($planId <= 0) {
    json_error('Invalid plan id', 422);
}

$planCols = [];
$colRes = $db->query("SHOW COLUMNS FROM `finance_installment_plans`");
if ($colRes) {
    while ($row = $colRes->fetch_assoc()) {
        $planCols[] = (string)$row['Field'];
    }
    $colRes->free();
}
$hasStatus = in_array('status', $planCols, true);
$hasUpdatedAt = in_array('updated_at', $planCols, true);

if ($hasStatus) {
    $sql = "UPDATE finance_installment_plans SET status = 'inactive'";
    if ($hasUpdatedAt) {
        $sql .= ", updated_at = CURRENT_TIMESTAMP";
    }
    $sql .= " WHERE id = ?";
} else {
    $sql = "DELETE FROM finance_installment_plans WHERE id = ?";
}

$stmt = $db->prepare($sql);
if (!$stmt) {
    error_log('finance_delete_plan.php: prepare failed: ' . $db->error);
    json_error('Unable to delete plan', 500);
}

$stmt->bind_param('i', $planId);
if (!$stmt->execute()) {
    error_log('finance_delete_plan.php: execute failed: ' . $db->error);
    $stmt->close();
    json_error('Unable to delete plan', 500);
}
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected < 1) {
    json_error('Plan was not found', 404);
}

log_audit(
    $db,
    $_SESSION['staff_id'] ?? 'system',
    'plan.delete',
    json_encode(['plan_id' => $planId], JSON_UNESCAPED_SLASHES)
);

json_success(['message' => 'Installment plan deleted.']);
