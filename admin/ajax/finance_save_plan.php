<?php
require_once "../../includes/finance_helpers.php";
require_once dirname(__DIR__, 2) . '/includes/csrf_guard.php';
wuc_ajax_require_csrf();
ensure_roles($db, ['Accountant','Systems Admin']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_error('Method not allowed', 405);
}

// CSRF is enforced above by wuc_ajax_require_csrf().

function build_default_schedule(int $installments): array {
    $count = max(1, min(12, $installments));
    $base = round(100 / $count, 2);
    $sum = 0.0;
    $schedule = [];
    $today = new DateTimeImmutable('today');

    for ($i = 0; $i < $count; $i++) {
        $dueDate = $today->modify('+' . ($i + 1) . ' month')->format('Y-m-10');
        $percent = ($i === ($count - 1)) ? round(100 - $sum, 2) : $base;
        $sum = round($sum + $percent, 2);
        $schedule[] = [
            'due_date' => $dueDate,
            'percent' => $percent
        ];
    }

    return $schedule;
}

$program_code = trim((string)($_POST['program_code'] ?? ''));
$plan_name = trim((string)($_POST['plan_name'] ?? ''));
$num_installments = (int)($_POST['num_installments'] ?? 0);
$schedule_input = trim((string)($_POST['schedule_json'] ?? ''));

if ($program_code === '' || $plan_name === '' || $num_installments < 1 || $num_installments > 12) {
    json_error('Missing or invalid fields', 422);
}

// Verify program exists and is active where possible.
$programCols = [];
$colRes = $db->query("SHOW COLUMNS FROM `programs`");
if ($colRes) {
    while ($c = $colRes->fetch_assoc()) {
        $programCols[] = (string)$c['Field'];
    }
    $colRes->free();
}
$hasProgramCol = static function (string $name) use ($programCols): bool {
    return in_array($name, $programCols, true);
};

$programSql = "SELECT program_code FROM programs WHERE program_code = ?";
if ($hasProgramCol('is_active')) {
    $programSql .= " AND is_active = 1";
} elseif ($hasProgramCol('status')) {
    $programSql .= " AND LOWER(status) = 'active'";
}
$programSql .= " LIMIT 1";

$programStmt = $db->prepare($programSql);
if (!$programStmt) {
    json_error('Failed to validate program');
}
$programStmt->bind_param('s', $program_code);
$programStmt->execute();
$programExists = $programStmt->get_result()->num_rows > 0;
$programStmt->close();
if (!$programExists) {
    json_error('Selected program is not available', 422);
}

if ($schedule_input === '') {
    $schedule = build_default_schedule($num_installments);
} else {
    $decoded = json_decode($schedule_input, true);
    if (!is_array($decoded) || empty($decoded)) {
        json_error('Schedule JSON must be a non-empty array', 422);
    }

    $schedule = [];
    $sumPercent = 0.0;
    $today = new DateTimeImmutable('today');
    foreach ($decoded as $idx => $item) {
        if (!is_array($item)) {
            json_error('Schedule item #' . ($idx + 1) . ' is invalid', 422);
        }
        $dueDate = trim((string)($item['due_date'] ?? ''));
        $percent = (float)($item['percent'] ?? 0);
        if ($dueDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            json_error('Schedule item #' . ($idx + 1) . ' has invalid due_date', 422);
        }
        $dueDateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
        if (!$dueDateObj || $dueDateObj < $today) {
            json_error('Schedule item #' . ($idx + 1) . ' has a due date in the past', 422);
        }
        if ($percent <= 0) {
            json_error('Schedule item #' . ($idx + 1) . ' has invalid percent', 422);
        }
        $sumPercent += $percent;
        $schedule[] = [
            'due_date' => $dueDate,
            'percent' => round($percent, 2)
        ];
    }

    if (count($schedule) !== $num_installments) {
        json_error('Installments count does not match schedule entries', 422);
    }
    if (abs($sumPercent - 100) > 0.01) {
        json_error('Schedule percent must total 100', 422);
    }
}

$schedule_json = json_encode($schedule, JSON_UNESCAPED_SLASHES);
if ($schedule_json === false) {
    json_error('Failed to encode schedule', 500);
}

$planCols = [];
$planColRes = $db->query("SHOW COLUMNS FROM `finance_installment_plans`");
if ($planColRes) {
    while ($pc = $planColRes->fetch_assoc()) {
        $planCols[] = (string)$pc['Field'];
    }
    $planColRes->free();
}
$planHasStatus = in_array('status', $planCols, true);
$planHasUpdatedAt = in_array('updated_at', $planCols, true);

$planId = null;
$action = 'created';
$findStmt = $db->prepare("SELECT id FROM finance_installment_plans WHERE program_code = ? AND plan_name = ? LIMIT 1");
if (!$findStmt) {
    json_error('Failed to prepare lookup');
}
$findStmt->bind_param('ss', $program_code, $plan_name);
$findStmt->execute();
$existing = $findStmt->get_result()->fetch_assoc();
$findStmt->close();

if ($existing) {
    $planId = (int)$existing['id'];
    $setParts = [
        "num_installments = ?",
        "schedule_json = ?"
    ];
    if ($planHasStatus) {
        $setParts[] = "status = 'active'";
    }
    if ($planHasUpdatedAt) {
        $setParts[] = "updated_at = CURRENT_TIMESTAMP";
    }
    $updSql = "UPDATE finance_installment_plans SET " . implode(', ', $setParts) . " WHERE id = ?";
    $updStmt = $db->prepare($updSql);
    if (!$updStmt) {
        json_error('Failed to prepare update');
    }
    $updStmt->bind_param('isi', $num_installments, $schedule_json, $planId);
    if (!$updStmt->execute()) {
        error_log('finance_save_plan.php: update failed: ' . $db->error);
        json_error('Failed to update plan');
    }
    $updStmt->close();
    $action = 'updated';
} else {
    $insertCols = ['program_code', 'plan_name', 'num_installments', 'schedule_json'];
    $insertVals = ['?', '?', '?', '?'];
    $insertTypes = 'ssis';
    $insertParams = [$program_code, $plan_name, $num_installments, $schedule_json];
    if ($planHasStatus) {
        $insertCols[] = 'status';
        $insertVals[] = '?';
        $insertTypes .= 's';
        $insertParams[] = 'active';
    }
    $insSql = "INSERT INTO finance_installment_plans (`" . implode('`,`', $insertCols) . "`) VALUES (" . implode(',', $insertVals) . ")";
    $insStmt = $db->prepare($insSql);
    if (!$insStmt) {
        json_error('Failed to prepare insert');
    }

    $bindParams = [$insertTypes];
    foreach ($insertParams as $k => $val) {
        $bindParams[] = &$insertParams[$k];
    }
    call_user_func_array([$insStmt, 'bind_param'], $bindParams);

    if (!$insStmt->execute()) {
        error_log('finance_save_plan.php: insert failed: ' . $db->error);
        json_error('Failed to save plan');
    }
    $planId = (int)$db->insert_id;
    $insStmt->close();
}

log_audit(
    $db,
    $_SESSION['staff_id'] ?? 'system',
    'plan.save',
    json_encode([
        'action' => $action,
        'program_code' => $program_code,
        'plan_name' => $plan_name,
        'num_installments' => $num_installments
    ], JSON_UNESCAPED_SLASHES)
);

json_success([
    'id' => $planId,
    'message' => 'Installment plan ' . $action . ' successfully.'
]);


