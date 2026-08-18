<?php
// Optional machine-local configuration outside htdocs. Production platforms
// may instead inject the same values through their secret manager.

if (!function_exists('wuc_portal_env_remember')) {
    function wuc_portal_env_remember(string $name, string $value): void
    {
        $GLOBALS['wuc_portal_env_cache'][$name] = $value;
    }
}

if (!function_exists('wuc_portal_env')) {
    /**
     * Env lookup that survives the threaded-Apache putenv race: values putenv'd
     * by one request are restored (removed from the shared process environment)
     * when that request ends, so a concurrent request that relied on getenv()
     * can lose them mid-flight. Values resolved while loading the machine-local
     * config file are cached in PHP-land and preferred over live getenv().
     */
    function wuc_portal_env(string $name, ?string $default = null): ?string
    {
        if (isset($GLOBALS['wuc_portal_env_cache']) && array_key_exists($name, $GLOBALS['wuc_portal_env_cache'])) {
            return (string)$GLOBALS['wuc_portal_env_cache'][$name];
        }
        $value = getenv($name);
        return ($value === false || $value === '') ? $default : $value;
    }
}

if (!function_exists('wuc_portal_apply_env_file')) {
    /** @param array<string,mixed> $values */
    function wuc_portal_apply_env_file(array $values): void
    {
        foreach ($values as $name => $value) {
            $name = (string)$name;
            if (!preg_match('/^(?:APP_ENV|WUC_[A-Z0-9_]+|OLLAMA_HOST|AI_CHAT_MODEL|AI_EMBED_MODEL|ENTERPRISE_[A-Z0-9_]+|AGRICULTURE_[A-Z0-9_]+)$/', $name)) {
                continue;
            }
            $current = getenv($name);
            if ($current === false || $current === '') {
                putenv($name . '=' . (string)$value);
                wuc_portal_env_remember($name, (string)$value);
            } else {
                wuc_portal_env_remember($name, (string)$current);
            }
        }
    }
}

if (!function_exists('wuc_portal_is_local_request')) {
    function wuc_portal_is_local_request(): bool
    {
        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
        if ($host === '') {
            return PHP_SAPI === 'cli';
        }
        $host = preg_replace('/:\d+$/', '', $host) ?: $host;

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}

$configCandidates = [];
$configFromEnv = getenv('WUC_CONFIG_FILE');
if (is_string($configFromEnv) && $configFromEnv !== '') {
    $configCandidates[] = $configFromEnv;
}
$configCandidates[] = dirname(__DIR__, 3) . '/wucportal-var/config/environment.php';
$configCandidates[] = dirname(__DIR__) . '/config/environment.local.php';

foreach ($configCandidates as $externalConfig) {
    if (!is_file($externalConfig)) {
        continue;
    }
    $values = require $externalConfig;
    if (is_array($values)) {
        wuc_portal_apply_env_file($values);
    }
    unset($values);
    $loadedUser = getenv('WUC_DB_USER');
    if ($loadedUser !== false && $loadedUser !== '') {
        break;
    }
}

// Local XAMPP must not inherit deployment DB credentials from wucportal-var/.
// That file sets APP_ENV=production and wucportal_app, which breaks or
// stalls localhost when MySQL is on the default root/no-password setup.
if (wuc_portal_is_local_request()) {
    $useDeploymentDb = in_array(
        strtolower(trim((string) wuc_portal_env('WUC_USE_DEPLOYMENT_DB', ''))),
        ['1', 'true', 'yes', 'on'],
        true
    );
    if (!$useDeploymentDb) {
        putenv('APP_ENV=development');
        wuc_portal_env_remember('APP_ENV', 'development');
        putenv('WUC_DB_HOST=127.0.0.1');
        wuc_portal_env_remember('WUC_DB_HOST', '127.0.0.1');
        putenv('WUC_DB_USER=root');
        wuc_portal_env_remember('WUC_DB_USER', 'root');
        putenv('WUC_DB_PASSWORD=');
        wuc_portal_env_remember('WUC_DB_PASSWORD', '');
        if (!wuc_portal_env('WUC_DB_NAME')) {
            putenv('WUC_DB_NAME=wucportal');
            wuc_portal_env_remember('WUC_DB_NAME', 'wucportal');
        }
    }
}

// Shared URL configuration for portal pages.
if (!defined('PORTAL_ROOT')) {
    define('PORTAL_ROOT', '/wucportal/');
}

// Central environment gate for development-only conveniences.
if (!defined('APP_ENV')) {
    define('APP_ENV', wuc_portal_env('APP_ENV') ?: 'production');
}
