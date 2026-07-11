<?php
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/fees_helpers.php';

$sid = $argv[1] ?? 'CSE26456789';

$stmt = $db->prepare("SELECT sfa.*, c.course_code FROM student_fee_accounts sfa INNER JOIN courses c ON c.id = sfa.course_id WHERE sfa.student_id = ? AND sfa.status = 'active' ORDER BY sfa.id DESC LIMIT 1");
$stmt->bind_param('s', $sid);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc();
$stmt->close();

$breakdown = fees_statement_build_breakdown($db, $account);
echo "Source: {$breakdown['source']}\n";
echo "Period: {$breakdown['period_label_full']}\n";
echo "Program: {$breakdown['program_code']}\n\n";

$sum = 0.0;
foreach ($breakdown['institutional_lines'] as $line) {
    echo "- {$line['name']}: ZMW " . number_format($line['amount'], 2) . "\n";
    $sum += (float)$line['amount'];
}
$sum -= (float)$breakdown['bursary'];
echo "\nLines sum (less bursary): ZMW " . number_format($sum, 2) . "\n";
echo "Account total_payable: ZMW " . number_format((float)$account['total_payable'], 2) . "\n";

if (abs($sum - (float)$account['total_payable']) <= 0.01) {
    echo "OK: Breakdown reconciles with account total\n";
} else {
    echo "MISMATCH: Breakdown does not match total_payable\n";
    exit(1);
}
