<?php
declare(strict_types=1);

/**
 * Centralized production hardening helpers.
 *
 * Prefer these over ad-hoc session/CSRF checks on mutating endpoints.
 * Keep business logic in callers; this file only enforces boundaries.
 */

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth_helpers.php';

if (!function_exists('wuc_require_cli_only')) {
    /** Block HTTP execution of maintenance / schema scripts. */
    function wuc_require_cli_only(string $message = 'This script can only be run from the command line.'): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }
}

if (!function_exists('wuc_require_post')) {
    function wuc_require_post(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            return;
        }
        http_response_code(405);
        header('Allow: POST');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Method Not Allowed';
        exit;
    }
}

if (!function_exists('wuc_require_csrf')) {
    /** Require a valid CSRF token from POST (or optional alternate field). */
    function wuc_require_csrf(?string $token = null, string $field = 'csrf_token'): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            wuc_configure_session_cookie();
            session_start();
        }
        $token = $token ?? (string)($_POST[$field] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!wuc_validate_csrf($token)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Invalid CSRF token';
            exit;
        }
    }
}

if (!function_exists('wuc_json_abort')) {
    /** @param array<string,mixed> $extra */
    function wuc_json_abort(string $message, int $status = 403, array $extra = []): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(array_merge(['success' => false, 'error' => $message], $extra));
        exit;
    }
}

if (!function_exists('wuc_require_student_session')) {
    /**
     * Ensure a student is logged in and return their canonical SID.
     * Never trust a client-supplied Sid for ownership decisions.
     */
    function wuc_require_student_session(bool $json = false): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            wuc_configure_session_cookie();
            session_start();
        }
        $sid = trim((string)($_SESSION['Sid'] ?? $_SESSION['student_id'] ?? ''));
        if ($sid === '' || !preg_match('/^[A-Za-z0-9\/\-_]{3,40}$/', $sid)) {
            if ($json) {
                wuc_json_abort('Authentication required.', 401);
            }
            http_response_code(401);
            header('Location: ' . WUC_APP_BASE_PATH . '/student_login.php');
            exit;
        }
        return $sid;
    }
}

if (!function_exists('wuc_force_session_student_id')) {
    /**
     * Resolve the acting student ID: session SID wins; reject mismatches.
     */
    function wuc_force_session_student_id(?string $postedSid = null, bool $json = false): string
    {
        $sessionSid = wuc_require_student_session($json);
        $postedSid = trim((string)$postedSid);
        if ($postedSid !== '' && strcasecmp($postedSid, $sessionSid) !== 0) {
            error_log('IDOR blocked: session SID=' . $sessionSid . ' posted SID=' . $postedSid . ' uri=' . ($_SERVER['REQUEST_URI'] ?? ''));
            if ($json) {
                wuc_json_abort('You may only act on your own student record.', 403);
            }
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Access denied.';
            exit;
        }
        return $sessionSid;
    }
}

if (!function_exists('wuc_require_finance_staff')) {
    /** Staff session + finance capability (accountant / fees module / systems_admin). */
    function wuc_require_finance_staff(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            wuc_configure_session_cookie();
            session_start();
        }

        require_once __DIR__ . '/session_guard.php';
        require_once __DIR__ . '/../db/connect.php';
        require_once __DIR__ . '/portal_access.php';
        require_once __DIR__ . '/staff_role_helpers.php';
        require_once __DIR__ . '/role_helpers.php';

        wuc_enforce_session_guard([
            'context' => 'finance',
            'session_keys' => ['user_id', 'staff_id'],
            'activity_keys' => ['last_activity', 'last_active_time'],
            'timeout' => 1800,
            'login_path' => WUC_APP_BASE_PATH . '/staff_login.php',
            'flash_key' => 'errorMessage',
            'login_message' => 'Please log in to continue.',
        ]);

        global $db;
        if ($db instanceof mysqli) {
            wuc_require_portal_access($db, 'academic');
            $staffId = trim((string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? ''));
            if ($staffId !== '') {
                wuc_hydrate_staff_roles($db, $staffId);
            }
        }

        $allowed = (function_exists('canAccessFinance') && canAccessFinance())
            || (function_exists('isSystemsAdmin') && isSystemsAdmin())
            || (function_exists('hasAnyRole') && hasAnyRole([ROLE_ACCOUNTANT, ROLE_SYSTEMS_ADMIN]));

        if (!$allowed) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Access denied.';
            exit;
        }
    }
}

if (!function_exists('wuc_require_admissions_staff')) {
    /** Staff session + admissions entitlement (not merely any staff_id). */
    function wuc_require_admissions_staff(bool $json = false): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            wuc_configure_session_cookie();
            session_start();
        }

        require_once __DIR__ . '/../config/auth_check.php';
        require_once __DIR__ . '/role_helpers.php';
        require_once __DIR__ . '/../db/connect.php';

        global $db;
        $staffId = trim((string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? ''));
        $userRole = (string)($_SESSION['user_role'] ?? '');

        if ($staffId === '' || ($userRole !== '' && $userRole !== 'staff' && empty($_SESSION['staff_id']))) {
            // Allow legacy admissions admin marker only when staff_id is also present.
            if (!(isset($_SESSION['index']) && $_SESSION['index'] === 'admin' && $staffId !== '')) {
                if ($json) {
                    wuc_json_abort('Authentication required.', 401);
                }
                authRedirectToLogin();
            }
        }

        if ($staffId !== '' && isset($db) && $db instanceof mysqli) {
            hydrateStaffRolesFromDatabase($staffId);
        }

        if (!function_exists('canAccessAdmissions') || !canAccessAdmissions()) {
            // Role fallback when module grants are not hydrated yet.
            $roleOk = function_exists('hasAnyRole') && hasAnyRole([
                ROLE_SYSTEMS_ADMIN,
                ROLE_ADMISSION_OFFICER,
                ROLE_REGISTRAR,
            ]);
            if (!$roleOk) {
                if ($json) {
                    wuc_json_abort('Admissions access required.', 403);
                }
                $_SESSION['errorMessage'] = 'Access denied. Admissions access is required.';
                header('Location: ' . (defined('STAFF_DASHBOARD') ? STAFF_DASHBOARD : WUC_APP_BASE_PATH . '/portal_selection.php'));
                exit;
            }
        }
    }
}
