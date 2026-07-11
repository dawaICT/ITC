<?php
declare(strict_types=1);

/**
 * CLI self-test for the cloud AI backend.
 *
 *   C:\xampp\php\php.exe ai\cloud_test.php
 *
 * Verifies the key is loaded and the provider returns a completion.
 * Prints clear PASS/FAIL lines. Safe to run repeatedly; sends no portal data.
 */

require_once __DIR__ . '/../includes/ai_cloud.php';

$cfg = wuc_ai_cloud_config();

echo "ITC Portal — Cloud AI self-test\n";
echo "--------------------------------\n";
echo 'Provider : ' . $cfg['provider'] . "\n";
echo 'Base URL : ' . $cfg['base_url'] . "\n";
echo 'Model    : ' . $cfg['model'] . "\n";
echo 'Key set  : ' . ($cfg['api_key'] !== '' ? 'YES (' . substr($cfg['api_key'], 0, 4) . '…' . substr($cfg['api_key'], -2) . ')' : 'NO') . "\n";
echo 'Enabled  : ' . (wuc_ai_cloud_enabled() ? 'YES' : 'NO') . "\n\n";

if (!wuc_ai_cloud_enabled()) {
    echo "FAIL: Cloud AI not enabled. Add a free key to ai/cloud_key.txt (see ai/cloud_key.txt.example).\n";
    exit(1);
}

echo "Sending a test prompt...\n";
$start = microtime(true);
try {
    $reply = wuc_ai_cloud_chat([
        ['role' => 'system', 'content' => 'You are a test assistant. Reply in one short sentence.'],
        ['role' => 'user',   'content' => 'Say "ITC cloud AI is working" and nothing else.'],
    ]);
    $ms = (int)round((microtime(true) - $start) * 1000);
    if (trim($reply) === '') {
        echo "FAIL: Provider returned an empty response.\n";
        exit(1);
    }
    echo "Reply    : " . $reply . "\n";
    echo "Latency  : {$ms} ms\n\n";
    echo "PASS: Cloud AI backend is working. The portal will now use it instead of the fallback.\n";
    exit(0);
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit(1);
}
