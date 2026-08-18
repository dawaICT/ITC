<?php
declare(strict_types=1);

/**
 * Enable enterprise AI for local Ollama and print readiness.
 *
 *   C:\xampp\php\php.exe scripts\enable_ollama_ai.php
 */

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/enterprise_portal/bootstrap.php';
require_once dirname(__DIR__) . '/includes/ai_portal.php';

echo "=== Enable Ollama for Skills and Enterprise Portal ===" . PHP_EOL;

$status = wuc_ai_local_status();
echo 'Ollama host     : ' . (defined('OLLAMA_HOST') ? OLLAMA_HOST : 'n/a') . PHP_EOL;
echo 'Local ready     : ' . (!empty($status['local_ready']) ? 'yes' : 'no') . PHP_EOL;
echo 'Provider        : ' . (string)($status['provider'] ?? 'none') . PHP_EOL;
echo 'Model           : ' . (string)($status['model'] ?? '') . PHP_EOL;
echo 'Message         : ' . (string)($status['message'] ?? '') . PHP_EOL;

if (empty($status['local_ready'])) {
    echo PHP_EOL . "Ollama is not ready yet." . PHP_EOL;
    echo "1. Install: winget install --id Ollama.Ollama -e" . PHP_EOL;
    echo "2. Run:     powershell -ExecutionPolicy Bypass -File ai\\setup_ollama.ps1" . PHP_EOL;
    echo "3. Re-run this script." . PHP_EOL;
    exit(1);
}

ep_set_setting($db, 'ai_enabled', 'true');
echo PHP_EOL . "Set enterprise setting ai_enabled=true" . PHP_EOL;

$gen = wuc_ai_generate($db, [
    'feature' => 'ollama_enable_check',
    'user_role' => 'cli',
    'user_id' => 'setup',
    'messages' => [
        ['role' => 'system', 'content' => 'Reply with one short plain sentence. No emojis.'],
        ['role' => 'user', 'content' => 'Confirm local Ollama is working for the ITC portal.'],
    ],
    'fallback' => static fn(): string => 'Fallback: Ollama did not return a reply.',
    'log_content' => false,
]);

echo 'Generate status : ' . (string)($gen['status'] ?? '') . PHP_EOL;
echo 'Used AI         : ' . (!empty($gen['used_ai']) ? 'yes' : 'no') . PHP_EOL;
echo 'Reply           : ' . substr(trim((string)($gen['text'] ?? '')), 0, 200) . PHP_EOL;

if (!empty($gen['used_ai'])) {
    echo PHP_EOL . "PASS: Local Ollama is serving enterprise AI drafting." . PHP_EOL;
    exit(0);
}

echo PHP_EOL . "WARN: Ollama is up but generation fell back. Check installed chat models (ollama list)." . PHP_EOL;
exit(1);
