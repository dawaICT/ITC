<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/guard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(410);

echo json_encode([
    'success' => false,
    'error' => 'This legacy registration endpoint has been retired. Use the current registration page.',
    'next_url' => '/wucportal/students/registration.php',
], JSON_UNESCAPED_SLASHES);
