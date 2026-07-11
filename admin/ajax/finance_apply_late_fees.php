<?php
require_once "../../includes/finance_helpers.php";
require_once dirname(__DIR__, 2) . '/includes/csrf_guard.php';
wuc_ajax_require_csrf();
ensure_roles($db, ['Accountant','Systems Admin']);

// Fetch active rule (simple pick the first)
$rule = $db->query("SELECT * FROM finance_late_fee_rules WHERE active = 1 ORDER BY id DESC LIMIT 1");
$ruleRow = $rule ? $rule->fetch_assoc() : null;
if (!$ruleRow) { json_error('No active late fee rule configured', 422); }

$applied = 0;
$graceDays = (int)$ruleRow['grace_days'];
// Find overdue installments outside grace
$sql = "SELECT id, student_id, amount, due_date FROM finance_student_installments 
        WHERE status = 'pending' AND DATE_ADD(due_date, INTERVAL ? DAY) < CURDATE()";
$stmt = $db->prepare($sql);
$stmt->bind_param('i', $graceDays);
$stmt->execute();
$rs = $stmt->get_result();

while ($row = $rs->fetch_assoc()) {
    $lateFee = 0.0;
    if ($ruleRow['rule_type'] === 'percent') {
        $lateFee = round(((float)$row['amount']) * ((float)$ruleRow['value'] / 100.0), 2);
    } else {
        $lateFee = (float)$ruleRow['value'];
    }
    if ($lateFee <= 0) continue;

    // Create an additional installment to represent late fee
    $ins = $db->prepare("INSERT INTO finance_student_installments (student_id, plan_id, installment_no, due_date, amount, status) VALUES (?, 0, 0, CURDATE(), ?, 'pending')");
    if ($ins) { $ins->bind_param('sd', $row['student_id'], $lateFee); $ins->execute(); $applied++; }
}

log_audit($db, $_SESSION['staff_id'] ?? 'system', 'ar.latefees.apply', json_encode(['applied'=>$applied]));
json_success(['applied' => $applied]);


