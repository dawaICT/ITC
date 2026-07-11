<?php
declare(strict_types=1);

/**
 * Install the AI academic-risk support tables.
 *
 * Run from the project root:
 *   C:\xampp\php\php.exe db\create_ai_academic_risk_tables.php
 */

require_once __DIR__ . '/connect.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$migration = dirname(__DIR__) . '/migrations/2026_06_25_ai_academic_risk.sql';
if (!is_file($migration)) {
    fwrite(STDERR, "Migration file not found: {$migration}\n");
    exit(1);
}

$sql = (string)file_get_contents($migration);
$sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
$statements = array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: []));

$ok = true;
foreach ($statements as $statement) {
    if ($statement === '') {
        continue;
    }

    try {
        $db->query($statement);
    } catch (Throwable $e) {
        $ok = false;
        echo "[FAIL] " . substr(preg_replace('/\s+/', ' ', $statement) ?: $statement, 0, 90) . "\n";
        echo "       " . $e->getMessage() . "\n";
    }
}

foreach (['student_risk_summary', 'academic_alerts', 'student_interventions', 'ai_report_summaries', 'ai_threshold_settings'] as $table) {
    $res = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    $exists = $res && $res->num_rows > 0;
    echo ($exists ? '[OK]   ' : '[FAIL] ') . $table . "\n";
    if ($res) {
        $res->free();
    }
    if (!$exists) {
        $ok = false;
    }
}

echo $ok ? "\nAI academic-risk tables are ready.\n" : "\nAI academic-risk setup finished with errors.\n";
exit($ok ? 0 : 1);
