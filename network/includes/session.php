<?php
declare(strict_types=1);

/**
 * Session isolated from the main WUCPortal (academic) session cookie.
 * Uses a dedicated session name so network login does not grant academic access.
 */

if (!function_exists('wuc_network_session_start')) {
    function wuc_network_session_start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && session_name() !== 'WUC_NETWORK_SESS') {
            session_write_close();
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('WUC_NETWORK_SESS');
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            if (PHP_VERSION_ID >= 70300) {
                session_set_cookie_params([
                    'lifetime' => 0,
                    'path' => '/wucportal',
                    'secure' => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            } else {
                session_set_cookie_params(0, '/wucportal', '', $secure, true);
            }
            session_start();
        }
    }
}

if (!function_exists('wuc_network_auth_realm')) {
    function wuc_network_auth_realm(): string
    {
        return (string)($_SESSION['auth_realm'] ?? '');
    }
}

if (!function_exists('wuc_network_is_authenticated')) {
    function wuc_network_is_authenticated(): bool
    {
        return !empty($_SESSION['logged_in']) && wuc_network_auth_realm() === 'network';
    }
}
