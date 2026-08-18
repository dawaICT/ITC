<?php
declare(strict_types=1);

/**
 * Open-source (Ollama) AI path check.
 * Usage: php scripts/debug_ollama_ai.php
 */

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/ai_portal.php';

echo "=== Ollama / open-source AI debug ===" . PHP_EOL . PHP_EOL;

echo 'OLLAMA_HOST          ' . (defined('OLLAMA_HOST') ? OLLAMA_HOST : '?') . PHP_EOL;
echo 'AI_CHAT_MODEL        ' . (defined('AI_CHAT_MODEL') ? AI_CHAT_MODEL : '?') . PHP_EOL;
echo 'AI_EMBED_MODEL       ' . (defined('AI_EMBED_MODEL') ? AI_EMBED_MODEL : '?') . PHP_EOL;
echo 'WUC_AI_BACKEND       ' . (getenv('WUC_AI_BACKEND') ?: '(auto)') . PHP_EOL;
echo 'curl_init            ' . (function_exists('curl_init') ? 'yes' : 'NO') . PHP_EOL;
echo PHP_EOL;

$up = ollama_available();
echo 'ollama_available     ' . ($up ? 'yes' : 'NO') . PHP_EOL;
if (!$up) {
    echo PHP_EOL . 'FAIL: Ollama is not reachable at ' . OLLAMA_HOST . PHP_EOL;
    echo 'Install: winget install --id Ollama.Ollama -e' . PHP_EOL;
    echo 'Then:    powershell -ExecutionPolicy Bypass -File ai\\setup_ollama.ps1' . PHP_EOL;
    echo 'Enable:  php scripts\\enable_ollama_ai.php' . PHP_EOL;
    exit(1);
}

$models = [];
try {
    foreach (ollama_installed_models() as $m) {
        $n = (string)($m['model'] ?? $m['name'] ?? '');
        if ($n !== '') {
            $models[] = $n;
        }
    }
} catch (Throwable $e) {
    echo 'model list error     ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

echo 'installed_models     ' . (count($models) ? implode(', ', $models) : '(none)') . PHP_EOL;
$resolved = ai_resolve_chat_model();
echo 'resolved_chat_model  ' . $resolved . PHP_EOL;
echo 'chat_model_ready     ' . (ollama_model_available($resolved) ? 'yes' : 'NO') . PHP_EOL;
echo 'embed_model_ready    ' . (ollama_model_available(AI_EMBED_MODEL) ? 'yes' : 'NO') . PHP_EOL;
echo PHP_EOL;

$status = wuc_ai_local_status();
echo 'portal.provider      ' . ($status['provider'] ?? '?') . PHP_EOL;
echo 'portal.model_ready   ' . (!empty($status['model_ready']) ? 'yes' : 'NO') . PHP_EOL;
echo 'portal.message       ' . ($status['message'] ?? '') . PHP_EOL;
echo PHP_EOL;

$gen = wuc_ai_generate($db, [
    'feature' => 'debug_ollama_ai',
    'user_role' => 'cli',
    'user_id' => 'debug',
    'messages' => [
        ['role' => 'system', 'content' => 'Reply with exactly: OLLAMA_OK'],
        ['role' => 'user', 'content' => 'ping'],
    ],
    'fallback' => static fn(): string => 'FALLBACK',
]);

echo 'generate.status      ' . ($gen['status'] ?? '') . PHP_EOL;
echo 'generate.provider    ' . ($gen['provider'] ?? '') . PHP_EOL;
echo 'generate.model       ' . ($gen['model'] ?? '') . PHP_EOL;
echo 'generate.used_ai     ' . (!empty($gen['used_ai']) ? 'yes' : 'NO') . PHP_EOL;
echo 'generate.text        ' . substr(trim((string)($gen['text'] ?? '')), 0, 120) . PHP_EOL;
if (!empty($gen['error'])) {
    echo 'generate.error       ' . $gen['error'] . PHP_EOL;
}

if (!empty($gen['used_ai']) && ($gen['provider'] ?? '') === 'local') {
    echo PHP_EOL . 'PASS: Local Ollama generate works.' . PHP_EOL;
    exit(0);
}

echo PHP_EOL . 'WARN: Generate did not use local Ollama.' . PHP_EOL;
exit(2);
