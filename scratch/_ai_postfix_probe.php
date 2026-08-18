<?php
declare(strict_types=1);
/**
 * Post-fix probe: Learning Assistant model path without browser login.
 */
require_once 'C:/xampp/htdocs/wucportal/db/connect.php';
require_once 'C:/xampp/htdocs/wucportal/includes/ai_portal.php';
require_once 'C:/xampp/htdocs/wucportal/services/ai/AIGateway.php';

$out = [];
$out[] = '=== LEARNING ASSISTANT POST-FIX PROBE ===';
$out[] = 'time=' . date('c');

$status = wuc_ai_local_status();
$out[] = 'status=' . json_encode([
    'available' => $status['available'] ?? null,
    'model_ready' => $status['model_ready'] ?? null,
    'provider' => $status['provider'] ?? null,
    'model' => $status['model'] ?? null,
    'cloud_enabled' => $status['cloud_enabled'] ?? null,
    'local_ready' => $status['local_ready'] ?? null,
    'message' => $status['message'] ?? null,
], JSON_UNESCAPED_SLASHES);

$out[] = 'cloud_enabled_fn=' . (wuc_ai_cloud_enabled() ? 'yes' : 'no');
$chain = wuc_ai_cloud_chain();
$out[] = 'cloud_chain=' . implode(',', array_map(static fn($c) => $c['provider'] . ':' . $c['model'], $chain));

// Direct router test
$router = new AIModelRouter($db);
try {
    $r = $router->generate(
        'You are a course tutor. Reply in one short sentence. No markdown.',
        'What is Information Technology in one sentence?',
        'chat'
    );
    $out[] = 'router_ok=yes';
    $out[] = 'router_model=' . $r['model'];
    $out[] = 'router_answer=' . substr(trim($r['answer']), 0, 240);
} catch (Throwable $e) {
    $out[] = 'router_ok=no';
    $out[] = 'router_error=' . $e->getMessage();
}

// Full gateway ask (may create conversation rows)
$studentId = 'CSE26456789';
$courseId = 'DCSE-101';
try {
    $gateway = new AIGateway($db);
    $resp = $gateway->askStudent($studentId, [
        'course_id' => $courseId,
        'question' => 'Explain what Information Technology means in this course in simple terms.',
        'explanation_level' => 'simple',
        'request_type' => 'chat',
    ]);
    $out[] = 'gateway_ok=yes';
    $out[] = 'source_status=' . ($resp['source_status'] ?? '');
    $out[] = 'answer_preview=' . substr(trim((string)($resp['answer'] ?? '')), 0, 300);
    $out[] = 'confirm=' . (!empty($resp['lecturer_confirmation_recommended']) ? 'yes' : 'no');
    $failMsg = 'The configured AI model is temporarily unavailable, and no approved course source matched';
    $out[] = 'is_old_error=' . (str_contains((string)$resp['answer'], $failMsg) ? 'yes' : 'no');
} catch (Throwable $e) {
    $out[] = 'gateway_ok=no';
    $out[] = 'gateway_error=' . $e->getMessage();
}

$text = implode(PHP_EOL, $out) . PHP_EOL;
file_put_contents('C:/xampp/htdocs/wucportal/scratch/_ai_postfix.txt', $text);
echo $text;
