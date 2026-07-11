<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/portal_config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$status = ['status' => 'ok', 'time' => gmdate(DATE_ATOM)];
$code = 200;
$cacheDir = rtrim((string)(getenv('WUC_LOG_DIR') ?: sys_get_temp_dir()), '/\\');
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0750, true);
$cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'health-status.json';
$lock = @fopen($cacheDir . DIRECTORY_SEPARATOR . 'health-status.lock', 'c');
if ($lock) @flock($lock, LOCK_EX);

$cached = is_file($cacheFile) && filemtime($cacheFile) >= time() - 2
    ? json_decode((string)file_get_contents($cacheFile), true)
    : null;
if (is_array($cached) && isset($cached['code'], $cached['database'])) {
    $code = (int)$cached['code'];
    $status['status'] = $code === 200 ? 'ok' : 'unavailable';
} else {
    try {
        $host = (string)(getenv('WUC_DB_HOST') ?: '');
        $user = (string)(getenv('WUC_DB_USER') ?: '');
        $password = (string)(getenv('WUC_DB_PASSWORD') ?: '');
        $database = (string)(getenv('WUC_DB_NAME') ?: '');
        if ($host === '' || $user === '' || $password === '' || $database === '') {
            throw new RuntimeException('Database health configuration is unavailable.');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $connected = false;
        $lastError = null;
        for ($attempt = 1; $attempt <= 3 && !$connected; $attempt++) {
            try {
                $healthDb = new mysqli($host, $user, $password, $database, (int)(getenv('WUC_DB_PORT') ?: 3306));
                $healthDb->set_charset('utf8mb4');
                $healthDb->query('SELECT 1');
                $healthDb->close();
                $connected = true;
            } catch (Throwable $connectionError) {
                $lastError = $connectionError;
                if ($attempt < 3) usleep(50000);
            }
        }
        if (!$connected) {
            throw $lastError ?: new RuntimeException('Database check failed.');
        }
    } catch (Throwable $e) {
        $status['status'] = 'unavailable';
        $code = 503;
    }
    @file_put_contents($cacheFile, json_encode(['code' => $code, 'database' => $code === 200 ? 'ok' : 'failed']), LOCK_EX);
}
if ($lock) { @flock($lock, LOCK_UN); fclose($lock); }

$expected = (string)(getenv('WUC_HEALTH_TOKEN') ?: '');
$provided = (string)($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? '');
if ($expected !== '' && hash_equals($expected, $provided)) {
    $status['checks'] = ['database' => $code === 200 ? 'ok' : 'failed', 'cache_ttl_seconds' => 2];
}
http_response_code($code);
echo json_encode($status, JSON_UNESCAPED_SLASHES);
