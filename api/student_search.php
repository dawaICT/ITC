<?php
declare(strict_types=1);

/**
 * Staff-only student lookup. Never expose PII to unauthenticated callers.
 */
require_once __DIR__ . '/../includes/production_guards.php';
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/json_response.php';
require_once __DIR__ . '/../includes/role_helpers.php';
require_once __DIR__ . '/../config/auth_check.php';

wuc_enforce_session_guard([
    'context' => 'api student search',
    'session_keys' => ['user_id', 'staff_id'],
    'activity_keys' => ['last_activity', 'last_active_time'],
    'timeout' => 1800,
    'login_path' => WUC_APP_BASE_PATH . '/staff_login.php',
    'flash_key' => 'errorMessage',
    'login_message' => 'Authentication required.',
]);

$staffId = trim((string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? ''));
if ($staffId !== '') {
    hydrateStaffRolesFromDatabase($staffId);
}

$allowed = (function_exists('isSystemsAdmin') && isSystemsAdmin())
    || (function_exists('canAccessAdmissions') && canAccessAdmissions())
    || (function_exists('canAccessRegistrar') && canAccessRegistrar())
    || (function_exists('canAccessFinance') && canAccessFinance())
    || (function_exists('canAccessAcademics') && canAccessAcademics());

if (!$allowed) {
    wuc_json_error('Access denied.', 403);
}

if (!isset($_GET['SID']) || trim((string)$_GET['SID']) === '') {
    wuc_json_error('Missing SID parameter.', 422);
}

$sid = trim((string)$_GET['SID']);
if (!preg_match('/^[A-Za-z0-9\/\-_]{3,40}$/', $sid)) {
    wuc_json_error('Invalid SID parameter.', 422);
}

$response = ['success' => false];
$query = 'SELECT SID, Fname, Lname, email, mobile FROM students WHERE SID = ? LIMIT 1';
if ($stmt = $db->prepare($query)) {
    $stmt->bind_param('s', $sid);
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $response['success'] = true;
            $response['student'] = $res->fetch_assoc();
        } else {
            $stmt->close();
            wuc_json_error('Student not found.', 404);
        }
    } else {
        error_log('Student search execute failed: ' . $stmt->error);
        $stmt->close();
        wuc_json_error('Student search failed.', 500);
    }
    $stmt->close();
} else {
    error_log('Student search prepare failed: ' . $db->error);
    wuc_json_error('Student search failed.', 500);
}

wuc_json_response($response);
