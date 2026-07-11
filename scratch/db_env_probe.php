<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../includes/portal_config.php';
$configPath = getenv('WUC_CONFIG_FILE') ?: dirname(__DIR__, 2) . '/../wucportal-var/config/environment.php';
$configPath = realpath(dirname(__DIR__, 2) . '/../wucportal-var/config/environment.php') ?: $configPath;

echo "config file exists: " . (is_file($configPath) ? 'yes' : 'no') . "\n";
echo "config path: {$configPath}\n";
echo "APP_ENV: " . APP_ENV . "\n";
echo "WUC_DB_USER getenv: " . var_export(getenv('WUC_DB_USER'), true) . "\n";
echo "WUC_DB_PASSWORD set: " . (getenv('WUC_DB_PASSWORD') !== false && getenv('WUC_DB_PASSWORD') !== '' ? 'yes' : 'no') . "\n";

try {
    require_once __DIR__ . '/../db/connect.php';
    echo "DB: connected\n";
    $r = $db->query('SELECT COUNT(*) AS c FROM students');
    echo "students: " . ($r->fetch_assoc()['c'] ?? 0) . "\n";
} catch (Throwable $e) {
    echo "DB FAIL: " . $e->getMessage() . "\n";
}
