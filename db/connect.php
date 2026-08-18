<?php
global $db;
if (isset($db) && $db instanceof mysqli) {
    return;
}
// Development error bootstrap (shows errors rather than blank pages)
require_once dirname(__DIR__) . '/includes/error_bootstrap.php';
// Shared security helpers (wuc_encode_id / wuc_decode_id / wuc_resolve_id) so
// every page that opens a DB connection can keep raw record IDs out of URLs.
require_once dirname(__DIR__) . '/includes/security.php';

// Defensive runtime schema helpers (wuc_table_exists / wuc_ensure_tables) so the
// DML-only app user does not crash pages that still call CREATE TABLE at runtime.
require_once dirname(__DIR__) . '/includes/schema_guard.php';

// Stable reference-data cache (programs/departments/roles) — per-request memo
// plus optional APCu. Safe to load on every connection; unused until called.
require_once dirname(__DIR__) . '/includes/lookup_cache.php';

// Lightweight request/SQL timing (opt-in via WUC_PERF_LOG=1).
require_once dirname(__DIR__) . '/includes/perf_monitor.php';

require_once dirname(__DIR__) . '/includes/portal_config.php';

// Database configuration is environment-driven in production. Local XAMPP
// defaults are retained only when APP_ENV=development.
$isDevelopment = APP_ENV === 'development';
// wuc_portal_env (not getenv) — putenv'd values can vanish mid-request under
// threaded Apache when a concurrent request's shutdown restores the shared
// process environment.
$db_host = wuc_portal_env('WUC_DB_HOST', '127.0.0.1') ?: '127.0.0.1';
$db_port = (int)(wuc_portal_env('WUC_DB_PORT') ?: 3306);
$db_user = wuc_portal_env('WUC_DB_USER', $isDevelopment ? 'root' : '') ?? '';
$db_password = wuc_portal_env('WUC_DB_PASSWORD', '') ?? '';
$db_name = wuc_portal_env('WUC_DB_NAME', 'wucportal') ?: 'wucportal';

// Create connection
try {
    if (!$isDevelopment && ($db_user === '' || $db_user === 'root' || $db_password === '')) {
        throw new RuntimeException(
            'Production database credentials are not configured safely. Set WUC_DB_USER and WUC_DB_PASSWORD.'
        );
    }
    $db = mysqli_init();
    if ($db === false) {
        throw new RuntimeException('Unable to initialize MySQLi.');
    }
    if (defined('MYSQLI_OPT_CONNECT_TIMEOUT')) {
        $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    }
    if (!$db->real_connect($db_host, $db_user, $db_password, $db_name, $db_port)) {
        throw new RuntimeException('MySQL connection failed: ' . mysqli_connect_error());
    }

    // Check connection
    if ($db->connect_error) {
        throw new Exception('MySQL connection failed: ' . $db->connect_error);
    }

    // Keep charset + connection collation in lockstep. Forcing collation_connection
    // alone (without SET NAMES) breaks prepared comparisons like `? = ''` on this
    // MariaDB build with "Illegal mix of collations (...general_ci...unicode_ci)".
    $db->set_charset('utf8mb4');
    $db->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");

    if (function_exists('wuc_perf_wrap_mysqli')) {
        wuc_perf_wrap_mysqli($db);
    }

} catch (Exception $e) {
    error_log('DB connect error: ' . $e->getMessage());
    if (function_exists('wuc_render_error_response')) {
        wuc_render_error_response(
            'We could not connect to the portal database right now. Please try again later or contact support.',
            'Database connection failed: ' . $e->getMessage(),
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'error.log'
        );
    } else {
        header('HTTP/1.1 500 Internal Server Error');
        echo 'We could not connect to the portal database right now. Please try again later or contact support.';
    }
    exit;
}
