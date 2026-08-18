<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/portal_config.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/schoolpay_webhook.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'status' => 'method_not_allowed', 'message' => 'POST is required.']);
    exit;
}

$raw = (string)file_get_contents('php://input');
if ($raw === '' || strlen($raw) > 65536) {
    http_response_code(400);
    echo json_encode(['success' => false, 'status' => 'invalid', 'message' => 'A valid JSON payload is required.']);
    exit;
}

try {
    $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new JsonException('Payload must be an object.');
    }
    $result = schoolpay_webhook_process($db, $payload, schoolpay_webhook_config($db));
} catch (JsonException $e) {
    $result = ['http_status' => 400, 'success' => false, 'status' => 'invalid', 'message' => 'A valid JSON payload is required.'];
} catch (Throwable $e) {
    error_log('SchoolPay webhook processing failed: ' . $e->getMessage());
    $result = ['http_status' => 500, 'success' => false, 'status' => 'error', 'message' => 'The payment callback could not be processed.'];
}

http_response_code((int)($result['http_status'] ?? 500));
unset($result['http_status']);
echo json_encode($result, JSON_UNESCAPED_SLASHES);
