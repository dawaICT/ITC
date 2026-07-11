<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/transport.php';
require_once __DIR__ . '/../services/TransportIntelligence.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        throw new RuntimeException('Use POST to generate a transport briefing.');
    }

    $token = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['transport_csrf'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $token)) {
        http_response_code(419);
        throw new RuntimeException('Your session expired. Refresh the page and try again.');
    }

    $intelligence = new TransportIntelligence($db);
    $snapshot = $intelligence->snapshot();
    echo json_encode([
        'ok' => true,
        'snapshot' => $snapshot,
        'briefing' => $intelligence->executiveBrief($snapshot),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code(500);
    }
    error_log('Transport intelligence endpoint failed: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
