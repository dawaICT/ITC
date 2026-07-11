<?php
declare(strict_types=1);
/**
 * Flash message helper.
 *
 * A tiny, dependency-free wrapper over $_SESSION['flash'] so success/error
 * messages are set and read the same way everywhere. Existing pages that use
 * $_SESSION['errorMessage'] / $_SESSION['flash'] directly keep working; this is
 * an additive convenience, not a replacement.
 */

if (!function_exists('wuc_set_flash')) {
    /** @param string $type 'success' | 'error' | 'info' | 'warning' */
    function wuc_set_flash(string $type, string $message): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('wuc_get_flash')) {
    /** Returns ['type'=>..,'message'=>..] once, then clears it. Null if none. */
    function wuc_get_flash(): ?array
    {
        if (empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
            return null;
        }
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
}
