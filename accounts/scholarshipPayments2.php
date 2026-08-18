<?php
/**
 * Compatibility route for the retired scholarship-specific direct poster.
 * Sponsor payments now use the same auditable fee-account workflow as other
 * manually recorded payments.
 */
$page_title = 'Process Sponsor Payments';
$outputLevel = ob_get_level();
ob_start();
require __DIR__ . '/includes/nav.php';
while (ob_get_level() > $outputLevel) {
    ob_end_clean();
}

$_SESSION['flash_info'] = 'Record scholarship and sponsor payments from Process Payments.';
wuc_safe_redirect('/wucportal/accounts/fees_student_payments.php', 303);

