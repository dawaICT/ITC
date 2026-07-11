<?php
/**
 * Reusable CSRF guard for AJAX / JSON endpoints.
 *
 * Validates state-changing requests against $_SESSION['csrf_token'], accepting
 * the token from the X-CSRF-Token header (auto-attached by includes/nav_unified.php)
 * or a csrf_token POST field. GET/HEAD requests are never blocked.
 *
 * Usage at the top of an endpoint (after auth/session bootstrap):
 *     require_once dirname(__DIR__, 2) . '/includes/csrf_guard.php';
 *     wuc_ajax_require_csrf();
 */

if (!function_exists('wuc_ajax_csrf_token')) {
    function wuc_ajax_csrf_token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (headers_sent()) {
                // Too late to start a session (output already emitted by the
                // caller); fail soft instead of a fatal ErrorException.
                error_log('[csrf_guard] token requested after output started');
                return (string) ($_SESSION['csrf_token'] ?? '');
            }
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            try {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
            }
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('wuc_ajax_request_csrf')) {
    function wuc_ajax_request_csrf(): string
    {
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($header !== '') {
            return (string) $header;
        }
        return (string) ($_POST['csrf_token'] ?? '');
    }
}

if (!function_exists('wuc_ajax_require_csrf')) {
    /**
     * Enforce CSRF for state-changing requests. On failure emits a JSON 403 and
     * exits. Safe to call unconditionally; only POST/PUT/PATCH/DELETE are checked.
     */
    function wuc_ajax_require_csrf(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        $session = (string) ($_SESSION['csrf_token'] ?? '');
        $provided = wuc_ajax_request_csrf();
        if ($session === '' || $provided === '' || !hash_equals($session, $provided)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Security token mismatch. Please refresh the page and try again.',
            ]);
            exit;
        }
    }
}
