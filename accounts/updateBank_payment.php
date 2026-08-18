<?php
/**
 * Retired legacy bank-transaction poster.
 *
 * This endpoint previously updated the non-transactional student_accounts
 * table before writing payment ledgers. A later failure could therefore leave
 * a balance change behind even after rollback. Keep the route as an
 * authenticated compatibility redirect so bookmarks cannot bypass the
 * verified bank-proof workflow.
 */
$page_title = 'Review Bank Transfers';
$outputLevel = ob_get_level();
ob_start();
require __DIR__ . '/includes/nav.php';
while (ob_get_level() > $outputLevel) {
    ob_end_clean();
}

$_SESSION['flash_info'] = 'Bank transfers are now reviewed from the verified payment-proof queue.';
wuc_safe_redirect('/wucportal/accounts/pendingPayments.php', 303);

