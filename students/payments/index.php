<?php
/**
 * Compatibility shim — the online payments history was merged into the fee
 * statement (accounts/fees_statement.php), which renders with the student
 * navbar for student sessions. paygate_return.php success redirects and old
 * bookmarks land here, so this URL must keep working.
 *
 * Students on the new fees system (active student_fee_accounts row) go to the
 * merged statement; legacy students go to students/fees.php, which shows the
 * same payment_status flash and their invoice/payment records.
 */
require_once __DIR__ . '/../includes/guard.php';

$studentId = (string)($_SESSION['Sid'] ?? '');
$query = (string)($_SERVER['QUERY_STRING'] ?? '');
$suffix = $query !== '' ? '?' . $query : '';

$hasNewFeeAccount = false;
if ($studentId !== '' && ($stmt = $db->prepare("SELECT 1 FROM student_fee_accounts WHERE student_id = ? AND status = 'active' LIMIT 1"))) {
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $stmt->store_result();
    $hasNewFeeAccount = $stmt->num_rows > 0;
    $stmt->close();
}

$target = $hasNewFeeAccount
    ? '/wucportal/accounts/fees_statement.php' . $suffix
    : '/wucportal/students/fees.php' . $suffix;

header('Location: ' . $target, true, 302);
exit;
