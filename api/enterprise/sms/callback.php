<?php
declare(strict_types=1);

/**
 * SMS delivery-status callback (provider-neutral).
 * Idempotent on provider_reference.
 */

require_once dirname(__DIR__, 3) . '/db/connect.php';
require_once dirname(__DIR__, 3) . '/includes/enterprise_portal/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$ref = trim((string)($payload['provider_reference'] ?? $payload['message_id'] ?? ''));
$status = trim((string)($payload['delivery_status'] ?? $payload['status'] ?? ''));
$signature = (string)($_SERVER['HTTP_X_ENTERPRISE_SIGNATURE'] ?? $payload['signature'] ?? '');

$secret = function_exists('wuc_portal_env')
    ? (string)wuc_portal_env('ENTERPRISE_SMS_WEBHOOK_SECRET', '')
    : (string)(getenv('ENTERPRISE_SMS_WEBHOOK_SECRET') ?: '');

if ($secret === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'SMS webhook secret not configured']);
    exit;
}

$expected = hash_hmac('sha256', $raw !== '' ? $raw : json_encode($payload), $secret);
if ($signature === '' || !hash_equals($expected, $signature)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Invalid signature']);
    exit;
}

if ($ref === '' || $status === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'provider_reference and delivery_status required']);
    exit;
}

if (!ep_agri_table_exists($db, 'enterprise_sms_messages')) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'SMS schema unavailable']);
    exit;
}

$stmt = $db->prepare('SELECT id, delivery_status FROM enterprise_sms_messages WHERE provider_reference = ? LIMIT 1');
$stmt->bind_param('s', $ref);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Unknown message']);
    exit;
}

// Idempotent: same status replay is OK
if ((string)$row['delivery_status'] === $status) {
    echo json_encode(['ok' => true, 'message' => 'Idempotent replay']);
    exit;
}

$upd = $db->prepare('UPDATE enterprise_sms_messages SET delivery_status = ?, received_at = NOW() WHERE provider_reference = ?');
$upd->bind_param('ss', $status, $ref);
$upd->execute();
$upd->close();

ep_adapters()['audit']->log($db, 'agriculture.sms_callback', [
    'provider_reference' => $ref,
    'delivery_status' => $status,
]);

echo json_encode(['ok' => true]);
