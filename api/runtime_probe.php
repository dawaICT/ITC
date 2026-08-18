<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$op = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
$out = [
    'ok' => true,
    'php' => PHP_VERSION,
    'sapi' => php_sapi_name(),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'display_errors' => ini_get('display_errors'),
    'session.save_handler' => ini_get('session.save_handler'),
    'opcache.enabled' => (bool)ini_get('opcache.enable'),
    'opcache.memory_consumption' => (int)ini_get('opcache.memory_consumption'),
    'opcache.revalidate_freq' => (int)ini_get('opcache.revalidate_freq'),
];
if (is_array($op)) {
    $mem = $op['memory_usage'] ?? [];
    $stats = $op['opcache_statistics'] ?? [];
    $out['opcache_status'] = [
        'enabled' => (bool)($op['opcache_enabled'] ?? false),
        'used_mb' => isset($mem['used_memory']) ? round($mem['used_memory'] / 1048576, 2) : null,
        'free_mb' => isset($mem['free_memory']) ? round($mem['free_memory'] / 1048576, 2) : null,
        'cached_scripts' => $stats['num_cached_scripts'] ?? null,
        'hits' => $stats['hits'] ?? null,
        'misses' => $stats['misses'] ?? null,
    ];
} else {
    $out['opcache_status'] = null;
}
echo json_encode($out, JSON_UNESCAPED_SLASHES);
