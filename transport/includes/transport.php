<?php
require_once __DIR__ . '/../../includes/session_guard.php';
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../includes/role_helpers.php';
require_once __DIR__ . '/../../includes/portal_access.php';
require_once __DIR__ . '/../../includes/permissions.php';

$isScript = defined('IS_SCRIPT') && IS_SCRIPT;
wuc_enforce_session_guard([
    'context' => 'transport',
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
if ($staff_id !== null && function_exists('hydrateStaffRolesFromDatabase')) {
    hydrateStaffRolesFromDatabase((string)$staff_id);
}

wuc_require_portal_access($db, 'academic', 'You do not have permission to access Transport Management from the current portal.');

if (!canAccessTransport()) {
    if ($isScript) {
        http_response_code(403);
        exit;
    }
    wuc_permission_denied('Your staff account is not assigned to Transport Management.', '/wucportal/portal_selection.php');
}

$base_url = '/wucportal/transport';
$root_url = '/wucportal';
