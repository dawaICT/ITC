<?php
// Central error handling. Technical details are logged for support, but are
// only rendered to authenticated systems administrators.

if (!defined('WUC_DEBUG')) {
    $debugFlag = strtolower(trim((string)(getenv('WUC_DEBUG') ?: '')));
    $envFlag = strtolower(trim((string)(getenv('WUC_ENV') ?: '')));
    define('WUC_DEBUG', in_array($debugFlag, ['1', 'true', 'yes', 'on'], true) || $envFlag === 'development');
}

error_reporting(E_ALL);
ini_set('log_errors', '1');

$defaultLogDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'wucportal-var' . DIRECTORY_SEPARATOR . 'logs';
$logDir = rtrim((string)(getenv('WUC_LOG_DIR') ?: $defaultLogDir), '/\\');
if (!is_dir($logDir) && !@mkdir($logDir, 0750, true) && !is_dir($logDir)) {
    $logDir = sys_get_temp_dir();
}
$logFile = $logDir . DIRECTORY_SEPARATOR . 'wucportal-error.log';

// Bound local disk use even when the host has not yet installed logrotate.
// The rename is intentionally best-effort; concurrent requests may race safely.
if (is_file($logFile) && filesize($logFile) > 10 * 1024 * 1024) {
    for ($i = 4; $i >= 1; $i--) {
        $older = $logFile . '.' . $i;
        $newer = $logFile . '.' . ($i + 1);
        if (is_file($older)) {
            $i === 4 ? @unlink($older) : @rename($older, $newer);
        }
    }
    @rename($logFile, $logFile . '.1');
}
ini_set('error_log', $logFile);

if (!ini_get('date.timezone')) {
    date_default_timezone_set('UTC');
}

function wuc_error_viewer_is_admin(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    $roles = [];
    foreach (['role', 'user_role', 'assigned_access'] as $key) {
        if (!empty($_SESSION[$key])) {
            $roles[] = strtolower(trim((string)$_SESSION[$key]));
        }
    }

    foreach (['all_roles', 'all_roles_raw'] as $key) {
        if (!empty($_SESSION[$key]) && is_array($_SESSION[$key])) {
            foreach ($_SESSION[$key] as $role) {
                $roles[] = strtolower(trim((string)$role));
            }
        }
    }

    $adminRoles = [
        'systems_admin',
        'systems admin',
        'system administrator',
        'superadmin',
        'super admin',
        'admin',
        'administrator',
    ];

    return (bool)array_intersect($roles, $adminRoles);
}

function wuc_should_show_error_details(): bool
{
    return defined('WUC_DEBUG') && WUC_DEBUG && wuc_error_viewer_is_admin();
}

function wuc_render_error_response(string $publicMessage, string $detail, string $logFile): void
{
    if (!headers_sent()) {
        header('HTTP/1.1 500 Internal Server Error');
    }

    if (wuc_should_show_error_details()) {
        echo '<pre style="white-space:pre-wrap;color:#7f1d1d;background:#fef2f2;border:1px solid #fecaca;padding:12px;border-radius:6px;">';
        echo htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
        echo "\nSee logs at: " . htmlspecialchars((string)$logFile, ENT_QUOTES, 'UTF-8');
        echo '</pre>';
        return;
    }

    echo '<div style="font-family:Arial,sans-serif;margin:24px;padding:16px;border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:8px;">';
    echo htmlspecialchars($publicMessage, ENT_QUOTES, 'UTF-8');
    echo '</div>';
}

ini_set('display_errors', wuc_should_show_error_details() ? '1' : '0');
ini_set('display_startup_errors', wuc_should_show_error_details() ? '1' : '0');

set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    $nonFatal = [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED, E_STRICT];
    if (in_array($severity, $nonFatal, true)) {
        error_log(sprintf('PHP notice: %s in %s on line %d', (string)$message, (string)$file, (int)$line));
        return true;
    }

    throw new ErrorException((string)$message, 0, (int)$severity, (string)$file, (int)$line);
});

set_exception_handler(function(Throwable $e) use ($logFile) {
    $detail = sprintf(
        "Uncaught %s: %s in %s on line %d\n%s",
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    error_log($detail);
    wuc_render_error_response('We could not load this page right now. Please try again later or contact support.', $detail, $logFile);
});

register_shutdown_function(function() use ($logFile) {
    $err = error_get_last();
    if (!$err) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    $detail = sprintf(
        'Fatal error: %s in %s on line %d',
        (string)$err['message'],
        (string)$err['file'],
        (int)$err['line']
    );
    error_log($detail);
    wuc_render_error_response('We could not load this page right now. Please try again later or contact support.', $detail, $logFile);
});

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}
