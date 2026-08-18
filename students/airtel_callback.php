<?php
/**
 * Airtel Money callback quarantine.
 *
 * Callback processing stays fail-closed until the merchant account's current
 * server-verification contract has been implemented and approved in sandbox.
 * Never trust a caller-supplied payment status or amount as proof of settlement.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
http_response_code(503);

echo json_encode([
    'success' => false,
    'code' => 'CALLBACK_VERIFICATION_REQUIRED',
    'message' => 'Airtel Money callback processing is not available.',
]);
