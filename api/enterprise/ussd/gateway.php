<?php
declare(strict_types=1);

/**
 * USSD gateway endpoint (provider-neutral).
 * Uses simulator path when live credentials are absent.
 */

require_once dirname(__DIR__, 3) . '/db/connect.php';
require_once dirname(__DIR__, 3) . '/includes/enterprise_portal/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = array_merge($_GET, $_POST);
}

$signature = (string)($_SERVER['HTTP_X_ENTERPRISE_SIGNATURE'] ?? $payload['signature'] ?? '');
if (!ep_adapters()['ussd']->verifySignature($raw !== '' ? $raw : json_encode($payload), $signature)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Invalid gateway signature']);
    exit;
}

$phone = (string)($payload['msisdn'] ?? $payload['phone'] ?? '');
$sessionId = (string)($payload['session_id'] ?? $payload['sessionId'] ?? '');
$requestId = (string)($payload['request_id'] ?? $payload['provider_request_id'] ?? '');
$text = trim((string)($payload['text'] ?? $payload['input'] ?? ''));
$newSession = empty($payload['session_id']) || !empty($payload['new_session']);

if ($phone === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'msisdn required']);
    exit;
}

if ($newSession || $sessionId === '') {
    $start = ep_ussd_start_session($db, $phone, $requestId !== '' ? $requestId : null);
    if (empty($start['ok'])) {
        http_response_code(500);
        echo json_encode($start);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'session_id' => $start['session_reference'],
        'response' => $start['menu'] ?? ep_ussd_main_menu(),
        'end' => false,
        'provider' => ep_adapters()['ussd']->providerName(),
        'live' => ep_adapters()['ussd']->credentialsConfigured(),
    ]);
    exit;
}

$res = ep_ussd_handle_input($db, $sessionId, $text);
http_response_code(!empty($res['ok']) ? 200 : 400);
echo json_encode([
    'ok' => !empty($res['ok']),
    'session_id' => $sessionId,
    'response' => $res['response'] ?? $res['message'] ?? '',
    'end' => !empty($res['end']),
]);
