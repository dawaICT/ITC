<?php
/**
 * Retired legacy DPO callback.
 *
 * Active DPO return flows verify the transaction token with DPO before applying
 * a payment. This historical endpoint trusted caller-supplied status values and
 * must never be used to mutate financial records.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
http_response_code(410);

echo json_encode([
    'success' => false,
    'code' => 'ENDPOINT_RETIRED',
    'message' => 'This legacy DPO callback endpoint is retired.',
]);
