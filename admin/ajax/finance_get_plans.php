<?php
require_once "../../includes/finance_helpers.php";
ensure_roles($db, ['Accountant', 'Systems Admin']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error('Method not allowed', 405);
}

$programCode = trim((string)($_GET['program_code'] ?? ''));
if ($programCode === '') {
    json_error('Program code is required', 422);
}

$tableCheck = $db->query("SHOW TABLES LIKE 'finance_installment_plans'");
if (!$tableCheck || $tableCheck->num_rows === 0) {
    if ($tableCheck) {
        $tableCheck->free();
    }
    json_success([]);
}
$tableCheck->free();

$planCols = [];
$colRes = $db->query("SHOW COLUMNS FROM `finance_installment_plans`");
if ($colRes) {
    while ($row = $colRes->fetch_assoc()) {
        $planCols[] = (string)$row['Field'];
    }
    $colRes->free();
}
$hasStatus = in_array('status', $planCols, true);

$statusExpr = $hasStatus ? '`status`' : "'active'";
$sql = "SELECT id, plan_name, num_installments, {$statusExpr} AS status
        FROM finance_installment_plans
        WHERE program_code = ? ";
if ($hasStatus) {
    $sql .= "AND LOWER(`status`) = 'active' ";
}
$sql .= "ORDER BY plan_name ASC, id DESC";

$stmt = $db->prepare($sql);
if (!$stmt) {
    error_log('finance_get_plans.php: prepare failed: ' . $db->error);
    json_error('Unable to load plans', 500);
}

$stmt->bind_param('s', $programCode);
$stmt->execute();
$res = $stmt->get_result();
$plans = [];
while ($row = $res->fetch_assoc()) {
    $plans[] = [
        'id' => (int)($row['id'] ?? 0),
        'plan_name' => (string)($row['plan_name'] ?? ''),
        'num_installments' => (int)($row['num_installments'] ?? 0),
        'status' => (string)($row['status'] ?? 'active'),
    ];
}
$stmt->close();

json_success($plans);
