<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/portal_config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$status = ['status' => 'ok', 'time' => gmdate(DATE_ATOM)];
$code = 200;
$cacheDir = rtrim((string)(wuc_portal_env('WUC_LOG_DIR') ?: getenv('WUC_LOG_DIR') ?: sys_get_temp_dir()), '/\\');
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0750, true);
}
$cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'health-status.json';
$lock = @fopen($cacheDir . DIRECTORY_SEPARATOR . 'health-status.lock', 'c');
if ($lock) {
    @flock($lock, LOCK_EX);
}

$cached = is_file($cacheFile) && filemtime($cacheFile) >= time() - 2
    ? json_decode((string)file_get_contents($cacheFile), true)
    : null;
if (is_array($cached) && isset($cached['code'], $cached['database'])) {
    $code = (int)$cached['code'];
    $status['status'] = $code === 200 ? 'ok' : 'unavailable';
} else {
    try {
        $isDevelopment = defined('APP_ENV') && APP_ENV === 'development';
        $host = (string)(wuc_portal_env('WUC_DB_HOST', '127.0.0.1') ?: '127.0.0.1');
        $user = (string)(wuc_portal_env('WUC_DB_USER', $isDevelopment ? 'root' : '') ?? '');
        $password = (string)(wuc_portal_env('WUC_DB_PASSWORD', '') ?? '');
        $database = (string)(wuc_portal_env('WUC_DB_NAME', 'wucportal') ?: 'wucportal');
        $port = (int)(wuc_portal_env('WUC_DB_PORT', '3306') ?: 3306);

        // Production still requires a real password; local XAMPP often uses empty root.
        if ($host === '' || $user === '' || $database === '') {
            throw new RuntimeException('Database health configuration is unavailable.');
        }
        if (!$isDevelopment && $password === '') {
            throw new RuntimeException('Database health configuration is unavailable.');
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $connected = false;
        $lastError = null;
        for ($attempt = 1; $attempt <= 3 && !$connected; $attempt++) {
            try {
                $healthDb = mysqli_init();
                if ($healthDb === false) {
                    throw new RuntimeException('Unable to initialize MySQLi.');
                }
                if (defined('MYSQLI_OPT_CONNECT_TIMEOUT')) {
                    $healthDb->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
                }
                if (!$healthDb->real_connect($host, $user, $password, $database, $port)) {
                    throw new RuntimeException('MySQL connection failed: ' . mysqli_connect_error());
                }
                $healthDb->set_charset('utf8mb4');
                $healthDb->query('SELECT 1');
                $healthDb->close();
                $connected = true;
            } catch (Throwable $connectionError) {
                $lastError = $connectionError;
                if ($attempt < 3) {
                    usleep(50000);
                }
            }
        }
        if (!$connected) {
            throw $lastError ?: new RuntimeException('Database check failed.');
        }
    } catch (Throwable $e) {
        error_log('health check failed: ' . $e->getMessage());
        $status['status'] = 'unavailable';
        $code = 503;
    }
    @file_put_contents($cacheFile, json_encode(['code' => $code, 'database' => $code === 200 ? 'ok' : 'failed']), LOCK_EX);
}
if ($lock) {
    @flock($lock, LOCK_UN);
    fclose($lock);
}

$expected = (string)(wuc_portal_env('WUC_HEALTH_TOKEN', '') ?? '');
$provided = (string)($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? '');
if ($expected !== '' && hash_equals($expected, $provided)) {
    $status['checks'] = [
        'database' => $code === 200 ? 'ok' : 'failed',
        'cache_ttl_seconds' => 2,
        'php' => PHP_VERSION,
    ];
}
http_response_code($code);
echo json_encode($status, JSON_UNESCAPED_SLASHES);
