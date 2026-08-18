<?php
declare(strict_types=1);

/**
 * One-shot enterprise AI capability check (CLI).
 * Usage: php scripts/debug_enterprise_ai.php
 */

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/enterprise_portal/bootstrap.php';
require_once dirname(__DIR__) . '/includes/ai_portal.php';

$pass = 0;
$fail = 0;
$warn = 0;

function line(string $label, string $value): void
{
    echo str_pad($label, 36) . $value . PHP_EOL;
}

function ok(string $msg): void
{
    global $pass;
    $pass++;
    echo "[PASS] {$msg}" . PHP_EOL;
}

function bad(string $msg): void
{
    global $fail;
    $fail++;
    echo "[FAIL] {$msg}" . PHP_EOL;
}

function soft(string $msg): void
{
    global $warn;
    $warn++;
    echo "[WARN] {$msg}" . PHP_EOL;
}

echo "=== Enterprise AI capability check ===" . PHP_EOL . PHP_EOL;

$aiEnabled = ep_ai_enabled($db);
line('ep_ai_enabled', $aiEnabled ? 'true' : 'false');
line('ENTERPRISE_AI_ENABLED env', var_export(getenv('ENTERPRISE_AI_ENABLED'), true));

$stmt = $db->prepare("SELECT setting_key, setting_value FROM enterprise_portal_settings WHERE setting_key LIKE '%ai%'");
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    $found = false;
    while ($row = $res->fetch_assoc()) {
        $found = true;
        line('db.' . $row['setting_key'], (string)$row['setting_value']);
    }
    $stmt->close();
    if (!$found) {
        line('db.ai settings', '(none — defaults apply)');
    }
}

echo PHP_EOL . '--- Provider status ---' . PHP_EOL;
$status = wuc_ai_local_status();
foreach ($status as $k => $v) {
    if (is_bool($v)) {
        $v = $v ? 'true' : 'false';
    } elseif (is_array($v)) {
        $v = json_encode($v);
    }
    line((string)$k, (string)$v);
}

$available = !empty($status['available']);
$modelReady = !empty($status['model_ready']);
if ($available && $modelReady) {
    ok('AI provider available with usable model (' . (string)($status['provider'] ?? '?') . ' / ' . (string)($status['model'] ?? '?') . ')');
} elseif ($available) {
    soft('AI transport available but no usable model: ' . (string)($status['message'] ?? ''));
} else {
    soft('AI offline: ' . (string)($status['message'] ?? 'no provider'));
}

echo PHP_EOL . '--- Ollama / embeddings ---' . PHP_EOL;
$ollama = function_exists('ollama_available') && ollama_available();
line('ollama_available', $ollama ? 'true' : 'false');
line('AI_EMBED_MODEL', defined('AI_EMBED_MODEL') ? AI_EMBED_MODEL : 'undefined');
if ($ollama && defined('AI_EMBED_MODEL') && function_exists('ollama_model_available')) {
    $embedOk = ollama_model_available(AI_EMBED_MODEL);
    line('embed_model_available', $embedOk ? 'true' : 'false');
    if ($embedOk) {
        ok('Embedding model ready for Skill Discovery semantic mode');
    } else {
        soft('Embedding model missing — Skill Discovery can still use keyword/lexical mode');
    }
} else {
    soft('Ollama offline — Skill Discovery semantic matching unavailable');
}

echo PHP_EOL . '--- Skill taxonomy ---' . PHP_EOL;
$tax = $db->query("SHOW TABLES LIKE 'ai_skill_taxonomy'");
$taxExists = $tax && $tax->num_rows > 0;
if ($tax) {
    $tax->free();
}
line('ai_skill_taxonomy', $taxExists ? 'exists' : 'missing');
if ($taxExists) {
    $c = $db->query('SELECT COUNT(*) AS c, SUM(embedding IS NOT NULL) AS e FROM ai_skill_taxonomy')->fetch_assoc();
    $rows = (int)($c['c'] ?? 0);
    $embedded = (int)($c['e'] ?? 0);
    line('taxonomy_rows', (string)$rows);
    line('taxonomy_embedded', (string)$embedded);
    if ($rows > 0) {
        ok('Skill taxonomy loaded (' . $rows . ' skills)');
    } else {
        soft('Skill taxonomy table empty — run ai/load_skills.php');
    }
} else {
    soft('Skill taxonomy missing — Skill Discovery matching will fail until loaded');
}

echo PHP_EOL . '--- Files ---' . PHP_EOL;
$files = [
    'includes/enterprise_portal/ai_assistant.php',
    'enterprise/tools/ai_assist.php',
    'enterprise/tools/skill_discovery.php',
    'students/includes/skill_discovery_run.php',
    'students/includes/skill_discovery_body.php',
    'students/includes/skill_discovery_scripts.php',
    'includes/ai_portal.php',
    'ai/match.php',
];
foreach ($files as $rel) {
    $path = dirname(__DIR__) . '/' . $rel;
    if (is_file($path)) {
        ok('File exists: ' . $rel);
    } else {
        bad('Missing file: ' . $rel);
    }
}

echo PHP_EOL . '--- Capability helper ---' . PHP_EOL;
$cap = ep_ai_capability($db);
foreach ($cap as $k => $v) {
    if (is_bool($v)) {
        $v = $v ? 'true' : 'false';
    }
    line((string)$k, (string)$v);
}
if (function_exists('ep_ai_local_draft')) {
    $local = ep_ai_local_draft('bio', ['title' => 'Welder', 'notes' => 'Fabrication']);
    if (!empty($local['ok']) && (string)($local['text'] ?? '') !== '') {
        ok('Local template draft works without provider');
    } else {
        bad('Local template draft failed');
    }
}
$off = ep_ai_assist($db, 'bio', ['title' => 'Welder', 'notes' => 'Fabrication', 'student_id' => 'SHOULD_STRIP', 'nrc' => 'X']);
if (!$aiEnabled) {
    if (empty($off['ok'])) {
        ok('AI assist refuses when enterprise AI disabled');
    } else {
        bad('AI assist should refuse when disabled');
    }
} else {
    line('assist.ok', !empty($off['ok']) ? 'true' : 'false');
    line('assist.status', (string)($off['status'] ?? ''));
    line('assist.message', substr((string)($off['message'] ?? ''), 0, 120));
    line('assist.text_len', (string)strlen((string)($off['text'] ?? '')));
    if (!empty($off['ok']) && (string)($off['text'] ?? '') !== '') {
        ok('AI assist returned draft text while enabled');
        if (($off['status'] ?? '') === 'ok') {
            ok('AI assist used live model (status=ok)');
        } else {
            soft('AI assist returned fallback/local draft (status=' . (string)($off['status'] ?? '?') . ')');
        }
    } else {
        bad('AI assist enabled but returned no usable draft: ' . (string)($off['message'] ?? ''));
    }
}

// Live generate smoke (does not require enterprise flag)
echo PHP_EOL . '--- wuc_ai_generate smoke ---' . PHP_EOL;
$gen = wuc_ai_generate($db, [
    'feature' => 'enterprise_ai_debug',
    'user_role' => 'cli',
    'user_id' => 'debug',
    'messages' => [
        ['role' => 'system', 'content' => 'Reply with one short plain sentence. No emojis.'],
        ['role' => 'user', 'content' => 'Say hello from the ITC Skills and Enterprise Portal AI check.'],
    ],
    'fallback' => static fn(): string => 'Fallback: AI provider unavailable during debug check.',
    'log_content' => false,
]);
line('generate.status', (string)($gen['status'] ?? ''));
line('generate.provider', (string)($gen['provider'] ?? ''));
line('generate.model', (string)($gen['model'] ?? ''));
line('generate.used_ai', !empty($gen['used_ai']) ? 'true' : 'false');
line('generate.error', (string)($gen['error'] ?? ''));
line('generate.text', substr(trim((string)($gen['text'] ?? '')), 0, 160));
if (!empty($gen['used_ai'])) {
    ok('wuc_ai_generate produced live AI text');
} elseif (trim((string)($gen['text'] ?? '')) !== '') {
    soft('wuc_ai_generate fell back to static text (provider offline or model missing)');
} else {
    bad('wuc_ai_generate returned empty text');
}

echo PHP_EOL . '=== Summary: pass=' . $pass . ' warn=' . $warn . ' fail=' . $fail . ' ===' . PHP_EOL;
if (!$aiEnabled) {
    echo 'Note: Enterprise AI drafting is OFF. Enable via management settings (ai_enabled) or ENTERPRISE_AI_ENABLED=true after confirming a provider.' . PHP_EOL;
}
exit($fail > 0 ? 1 : 0);
