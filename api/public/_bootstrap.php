<?php
declare(strict_types=1);

/**
 * Public API bootstrap — the ONLY data bridge between the public ITC website
 * and the WUCPortal database.
 *
 * Design rules (see web.md architecture goal):
 *  - JSON only. No HTML, no portal includes, no portal session.
 *  - Reads through a dedicated READ-ONLY MySQL user (itc_public) that can SELECT
 *    only the approved public tables. It cannot write and cannot see private
 *    tables (students, finance, results, staff, etc.) at the database level.
 *  - GET only. Any POST/PUT/DELETE is rejected.
 *  - Per-IP rate limiting.
 *  - Inputs validated, outputs JSON-escaped, raw SQL errors never leaked.
 *
 * Endpoints include this file after defining WUC_PUBLIC_API.
 */

if (!defined('WUC_PUBLIC_API')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// ---------------------------------------------------------------------------
// Read-only database credentials (SELECT on approved public tables only).
// Loaded from environment / external config — never commit secrets here.
// ---------------------------------------------------------------------------
require_once dirname(__DIR__, 2) . '/includes/portal_config.php';

if (!function_exists('wuc_public_db_config')) {
    function wuc_public_db_config(string $key, ?string $default = null): ?string
    {
        $envKey = 'WUC_PUBLIC_DB_' . strtoupper($key);
        $value = wuc_portal_env($envKey);
        if ($value !== null && $value !== '') {
            return $value;
        }

        if ($key === 'host') {
            return wuc_portal_env('WUC_DB_HOST', $default ?? '127.0.0.1');
        }
        if ($key === 'name') {
            return wuc_portal_env('WUC_DB_NAME', $default ?? 'wucportal');
        }

        // Local development may reuse the main app DB user when no dedicated
        // read-only public user is configured. Production must set WUC_PUBLIC_DB_*.
        if (defined('APP_ENV') && APP_ENV === 'development') {
            if ($key === 'user') {
                return wuc_portal_env('WUC_DB_USER', $default);
            }
            if ($key === 'password') {
                return wuc_portal_env('WUC_DB_PASSWORD', $default ?? '');
            }
        }

        return $default;
    }
}

// Origins allowed to call this API from a browser (CORS). In local dev the
// website and portal share the localhost origin, so CORS is a no-op; in
// production they are different sub-domains and this allowlist applies.
const WUC_PUBLIC_ALLOWED_ORIGINS = [
    'https://www.itc.ac.zm',
    'https://itc.ac.zm',
    'http://localhost',
    'http://itc.local',
];

// ---------------------------------------------------------------------------
// Security headers + content type
// ---------------------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: public, max-age=300'); // public data; safe to cache 5 min

$__origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($__origin !== '' && in_array($__origin, WUC_PUBLIC_ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $__origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Accept');
}

/**
 * Emit a JSON payload and stop.
 * JSON_HEX_* flags keep the response safe even if embedded directly in HTML.
 */
function wuc_public_json($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    exit;
}

/** Emit a generic error (never leak SQL/internal detail to the client). */
function wuc_public_error(string $message, int $code = 400): void
{
    wuc_public_json(['error' => $message], $code);
}

/**
 * Enforce GET-only access (preflight OPTIONS answered with 204).
 */
function wuc_public_require_get(): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if ($method !== 'GET') {
        header('Allow: GET');
        wuc_public_error('Method not allowed. Public API is read-only.', 405);
    }
}

/**
 * Simple per-IP fixed-window rate limiter.
 */
function wuc_public_rate_limit(int $maxPerWindow = 90, int $window = 60): void
{
    $ip  = (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wuc_public_api_rl';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    $file = $dir . DIRECTORY_SEPARATOR . md5($ip) . '.json';
    $now  = time();

    $state = ['count' => 0, 'start' => $now];
    if (is_file($file)) {
        $prev = json_decode((string)@file_get_contents($file), true);
        if (is_array($prev) && isset($prev['start'], $prev['count']) && ($now - (int)$prev['start']) < $window) {
            $state = ['count' => (int)$prev['count'], 'start' => (int)$prev['start']];
        }
    }
    $state['count']++;
    @file_put_contents($file, json_encode($state), LOCK_EX);

    if ($state['count'] > $maxPerWindow) {
        header('Retry-After: ' . max(1, $window - ($now - (int)$state['start'])));
        wuc_public_error('Too many requests. Please slow down.', 429);
    }
}

/**
 * Lazily open the read-only connection. Failures are logged privately and the
 * client only ever sees a generic 503.
 */
function wuc_public_db(): mysqli
{
    static $db = null;
    if ($db instanceof mysqli) {
        return $db;
    }
    mysqli_report(MYSQLI_REPORT_OFF);

    // Read the machine-local env file directly and prefer it for the public
    // API credentials. Background: on the threaded Windows MPM, Apache hands
    // each request a per-request environment whose contents can be clobbered
    // mid-flight by concurrent requests, so getenv('WUC_PUBLIC_DB_*') is not
    // reliable here. The env file is the deterministic source of truth.
    $apiEnv = [];
    foreach ([
        getenv('WUC_CONFIG_FILE') ?: null,
        dirname(__DIR__, 3) . '/wucportal-var/config/environment.php',
        dirname(__DIR__, 2) . '/config/environment.local.php',
    ] as $envFile) {
        if (is_string($envFile) && $envFile !== '' && is_file($envFile)) {
            $values = require $envFile;
            if (is_array($values)) {
                $apiEnv = $values + $apiEnv;
            }
        }
    }
    $apiEnvValue = static function (string $key) use ($apiEnv): ?string {
        $v = $apiEnv[$key] ?? null;
        return (is_string($v) && $v !== '') ? $v : null;
    };

    $host = $apiEnvValue('WUC_PUBLIC_DB_HOST') ?? (string) wuc_public_db_config('host', '127.0.0.1');
    $user = $apiEnvValue('WUC_PUBLIC_DB_USER') ?? (string) wuc_public_db_config('user', '');
    $pass = $apiEnvValue('WUC_PUBLIC_DB_PASSWORD') ?? (string) wuc_public_db_config('password', '');
    $name = $apiEnvValue('WUC_PUBLIC_DB_NAME') ?? (string) wuc_public_db_config('name', 'wucportal');

    if ($user === '' || $pass === '') {
        error_log('[public-api] WUC_PUBLIC_DB_USER / WUC_PUBLIC_DB_PASSWORD are not configured.');
        wuc_public_error('Service temporarily unavailable.', 503);
    }

    $db = @new mysqli($host, $user, $pass, $name);
    if ($db->connect_errno) {
        error_log('[public-api] DB connect failed: ' . $db->connect_error);
        wuc_public_error('Service temporarily unavailable.', 503);
    }
    $db->set_charset('utf8mb4');
    return $db;
}

/** True if a table exists (used so optional tables like news degrade to []). */
function wuc_public_table_exists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    if (!$stmt) { return false; }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

/** Run a prepared SELECT and return all rows as an array. */
function wuc_public_select(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log('[public-api] prepare failed: ' . $db->error . ' | ' . $sql);
        wuc_public_error('Service temporarily unavailable.', 503);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        error_log('[public-api] execute failed: ' . $stmt->error);
        wuc_public_error('Service temporarily unavailable.', 503);
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Every endpoint gets these for free.
wuc_public_require_get();
wuc_public_rate_limit();
