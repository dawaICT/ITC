<?php
/**
 * Retired demonstration page.
 *
 * Route students to the real fee ledger instead of presenting a hard-coded
 * balance or a payment-success message derived from query-string input.
 */

$message = rawurlencode('Airtel Money is not available. Please use an active payment method.');
header('Location: /wucportal/students/fees.php?payment_status=unavailable&payment_message=' . $message, true, 302);
exit;
