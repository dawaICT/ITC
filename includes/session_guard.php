<?php
/**
 * Shared session/authentication guard used by protected portal areas.
 *
 * Area-specific authorization remains in each module. This helper owns the
 * mechanics that must be identical everywhere: secure session startup, legacy
 * staff-key synchronization, idle timeout, flash messages, and safe redirects.
 */

require_once __DIR__ . '/security.php';

if (!function_exists('wuc_guard_start_session')) {
    function wuc_guard_start_session(string $context, bool $isScript = false): bool
    {
        wuc_apply_security_headers(true);

        if (session_status() !== PHP_SESSION_NONE) {
            return true;
        }

        if (headers_sent($file, $line)) {
            error_log("{$context} guard: session could not start because output already began at {$file}:{$line}");
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                $_SESSION = [];
            }
            return false;
        }

        wuc_configure_session_cookie();
        session_start();
        return true;
    }
}

if (!function_exists('wuc_guard_sync_session_aliases')) {
    function wuc_guard_sync_session_aliases(array $keys): void
    {
        $value = '';
        foreach ($keys as $key) {
            if (!empty($_SESSION[$key])) {
                $value = (string) $_SESSION[$key];
                break;
            }
        }
        if ($value === '') {
            return;
        }
        foreach ($keys as $key) {
            if (empty($_SESSION[$key])) {
                $_SESSION[$key] = $value;
            }
        }
    }
}

if (!function_exists('wuc_guard_clear_session')) {
    function wuc_guard_clear_session(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                (bool) ($params['secure'] ?? false),
                (bool) ($params['httponly'] ?? true)
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}

if (!function_exists('wuc_enforce_session_guard')) {
    /**
     * @param array<string,mixed> $options
     */
    function wuc_enforce_session_guard(array $options): bool
    {
        $context = (string) ($options['context'] ?? 'portal');
        $isScript = (bool) ($options['is_script'] ?? false);
        $sessionStarted = wuc_guard_start_session($context, $isScript);

        $aliases = array_values(array_filter((array) ($options['session_keys'] ?? []), 'is_string'));
        wuc_guard_sync_session_aliases($aliases);

        $timeout = max(60, (int) ($options['timeout'] ?? 1800));
        $grace = max(0, (int) ($options['post_grace'] ?? 30));
        $activityKeys = array_values(array_filter(
            (array) ($options['activity_keys'] ?? ['last_activity']),
            'is_string'
        ));
        if (!$activityKeys) {
            $activityKeys = ['last_activity'];
        }

        $lastActivity = 0;
        foreach ($activityKeys as $key) {
            $lastActivity = max($lastActivity, (int) ($_SESSION[$key] ?? 0));
        }

        $now = time();
        $elapsed = $lastActivity > 0 ? $now - $lastActivity : 0;
        $isPost = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
        if ($isPost && $elapsed > $timeout && $elapsed <= ($timeout + $grace)) {
            $elapsed = 0;
        }

        if ($elapsed > $timeout) {
            $identity = 'unknown';
            foreach ($aliases as $key) {
                if (!empty($_SESSION[$key])) {
                    $identity = (string) $_SESSION[$key];
                    break;
                }
            }
            error_log("GUARD [{$context}]: idle timeout for user={$identity} on " . ($_SERVER['REQUEST_URI'] ?? ''));
            wuc_guard_clear_session();

            if ($isScript) {
                return false;
            }

            if ($sessionStarted && !headers_sent()) {
                wuc_configure_session_cookie();
                session_start();
                $_SESSION[(string) ($options['flash_key'] ?? 'errorMessage')] =
                    (string) ($options['timeout_message'] ?? 'Your session has expired. Please log in again.');
            }
            $loginPath = (string) ($options['login_path'] ?? WUC_APP_BASE_PATH . '/staff_login.php');
            wuc_safe_redirect($loginPath, 302, $loginPath);
        }

        foreach ($activityKeys as $key) {
            $_SESSION[$key] = $now;
        }

        $authenticated = false;
        foreach ($aliases as $key) {
            if (!empty($_SESSION[$key])) {
                $authenticated = true;
                break;
            }
        }

        if (!$authenticated) {
            if ($isScript) {
                return false;
            }
            $flashKey = (string) ($options['flash_key'] ?? 'errorMessage');
            if (empty($_SESSION[$flashKey])) {
                $_SESSION[$flashKey] = (string) ($options['login_message'] ?? 'Please log in to continue.');
            }
            $loginPath = (string) ($options['login_path'] ?? WUC_APP_BASE_PATH . '/staff_login.php');
            wuc_safe_redirect($loginPath, 302, $loginPath);
        }

        return true;
    }
}
