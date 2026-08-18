<?php
/**
 * Compatibility route for the retired manual bank-payment poster.
 * Authentication and finance authorization are applied by the shared nav
 * bootstrap before redirecting to the verified proof queue.
 */
$page_title = 'Review Bank Transfers';
$outputLevel = ob_get_level();
ob_start();
require __DIR__ . '/includes/nav.php';
while (ob_get_level() > $outputLevel) {
    ob_end_clean();
}

$_SESSION['flash_info'] = 'Manual bank posting has been replaced by proof verification and invoice-safe posting.';
wuc_safe_redirect('/wucportal/accounts/pendingPayments.php', 303);

