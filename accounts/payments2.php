<?php
/**
 * Compatibility route for the retired direct payment-ledger form.
 * The supported payment screen validates CSRF tokens, active fee accounts,
 * unique receipts, and recalculates the account balance in one workflow.
 */
$page_title = 'Process Student Payments';
$outputLevel = ob_get_level();
ob_start();
require __DIR__ . '/includes/nav.php';
while (ob_get_level() > $outputLevel) {
    ob_end_clean();
}

$_SESSION['flash_info'] = 'Use Process Payments to record cash, cheque, or sponsor payments safely.';
wuc_safe_redirect('/wucportal/accounts/fees_student_payments.php', 303);

