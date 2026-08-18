<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/services/ai/AIGateway.php';
try {
    $input = ai_api_input();
    ai_api_require_post($input);
    $input['request_type'] = 'practice';
    if (trim((string)($input['question'] ?? '')) === '') {
        $input['question'] = 'Create a short revision activity from the most relevant approved course material.';
    }
    $result = ai_api_format_answer((new AIGateway($db))->askStudent((string)$_SESSION['Sid'], $input));
    ai_api_success(['data' => $result]);
} catch (Throwable $e) { ai_api_error($e); }
