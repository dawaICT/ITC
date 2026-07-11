<?php
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/fees_helpers.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$sid = $argv[1] ?? 'CSE26456789';

$stmt = $db->prepare("SELECT id FROM student_fee_accounts WHERE student_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
$stmt->bind_param('s', $sid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo "No active fee account for {$sid}\n";
    exit(1);
}

$accountId = (int)$row['id'];
echo "Account ID: {$accountId}\n";

fees_recalculate_student_balance($db, $accountId);

$stmt = $db->prepare("SELECT total_payable, amount_paid, balance, payment_status FROM student_fee_accounts WHERE id = ?");
$stmt->bind_param('i', $accountId);
$stmt->execute();
$acc = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo "After recalculate:\n";
print_r($acc);

$summary = fees_sum_completed_payments_for_account($db, $accountId);
echo "Payment records: " . count($summary['records']) . ", total_paid: " . $summary['total_paid'] . "\n";

if ((float)$acc['amount_paid'] >= 15000 && (float)$acc['balance'] <= 0.01) {
    echo "OK: Balance reflects payments ledger\n";
} else {
    echo "CHECK: Expected paid 15000, balance ~0\n";
}
