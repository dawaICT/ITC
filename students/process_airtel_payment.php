<?php
/**
 * Airtel Money processor quarantine.
 *
 * Initiation and status queries remain unavailable until a provider-verified
 * settlement flow is implemented. Starting a charge without a trusted way to
 * reconcile it would leave students charged but their portal balance unpaid.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
http_response_code(503);

echo json_encode([
    'success' => false,
    'code' => 'INTEGRATION_NOT_READY',
    'message' => 'Airtel Money payments are not available. Please use an active payment method.',
]);
