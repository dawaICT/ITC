<?php
declare(strict_types=1);

echo "APP_ENV from getenv: " . var_export(getenv('APP_ENV'), true) . "\n";

require_once __DIR__ . '/../includes/portal_config.php';
echo "APP_ENV constant: " . (defined('APP_ENV') ? APP_ENV : 'undefined') . "\n";
echo "WUC_DB_USER: " . var_export(getenv('WUC_DB_USER'), true) . "\n";
echo "WUC_DB_PASSWORD set: " . (getenv('WUC_DB_PASSWORD') !== false ? 'yes' : 'no') . "\n";

$isDevelopment = APP_ENV === 'development';
$db_host = getenv('WUC_DB_HOST') ?: '127.0.0.1';
$db_port = (int)(getenv('WUC_DB_PORT') ?: 3306);
$db_user = getenv('WUC_DB_USER') ?: ($isDevelopment ? 'root' : '');
$db_password = getenv('WUC_DB_PASSWORD');
$db_password = $db_password === false ? '' : $db_password;
$db_name = getenv('WUC_DB_NAME') ?: 'wucportal';

echo "Resolved: host={$db_host} port={$db_port} user={$db_user} db={$db_name} dev=" . ($isDevelopment ? 'yes' : 'no') . "\n";

if (!$isDevelopment && ($db_user === '' || $db_user === 'root' || $db_password === '')) {
    echo "FAIL: Production credential guard would block connection\n";
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($db_host, $db_user, $db_password, $db_name, $db_port);
    echo "OK: connected, server=" . $db->server_info . "\n";
    $r = $db->query('SELECT COUNT(*) AS c FROM students');
    echo "students count: " . ($r->fetch_assoc()['c'] ?? '?') . "\n";
    $db->close();
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit(1);
}
