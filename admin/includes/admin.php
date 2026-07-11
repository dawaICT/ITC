<?php
require_once dirname(__DIR__, 2) . '/includes/session_guard.php';
require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/config/auth_check.php';
require_once dirname(__DIR__, 2) . '/includes/portal_access.php';

$isScript = defined('IS_SCRIPT') && IS_SCRIPT;
wuc_enforce_session_guard([
    'context' => 'admin',
    'is_script' => $isScript,
    'session_keys' => ['staff_id', 'user_id'],
    'activity_keys' => ['last_activity', 'last_active_time'],
    'timeout' => 1800,
    'post_grace' => 30,
    'login_path' => '/wucportal/staff_login.php',
    'flash_key' => 'errorMessage',
    'timeout_message' => 'Your session has expired. Please log in again.',
    'login_message' => 'Please log in to continue.',
]);

$staff_id = $_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? null;
if ($staff_id !== null) {
    hydrateStaffRolesFromDatabase((string) $staff_id);
}

wuc_require_portal_access($db, 'academic');

if (!function_exists('isAdmin')) {
    function isAdmin($staff_id = null): bool
    {
        global $db;
        $staffId = trim((string) ($staff_id ?? $_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? ''));
        if ($staffId === '' || !isset($db) || !$db instanceof mysqli) {
            return false;
        }

        $currentId = (string) ($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? '');
        if ($staffId === $currentId && wuc_normalize_staff_role((string) ($_SESSION['role'] ?? ''), false) === 'systems_admin') {
            return true;
        }

        return wuc_staff_has_role($db, $staffId, 'systems_admin');
    }
}

$isAdmin = isAdmin($staff_id);

// Header/footer files own all layout output.
