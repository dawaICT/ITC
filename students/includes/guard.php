<?php
// Shared student session/authentication guard.
require_once dirname(__DIR__, 2) . '/includes/session_guard.php';

$guardRequestUri = str_replace('\\', '/', (string)($_SERVER['REQUEST_URI'] ?? ''));
$guardPathOnly = strtolower((string)(parse_url($guardRequestUri, PHP_URL_PATH) ?: $guardRequestUri));
$isElearningPath = strpos($guardPathOnly, '/students/elearning/') !== false
    || strpos($guardPathOnly, '/elearning/') !== false;
$guardLoginPath = $isElearningPath
    ? '/wucportal/elearning_login.php'
    : '/wucportal/student_login.php';

wuc_enforce_session_guard([
    'context' => 'student',
    'session_keys' => ['Sid'],
    'activity_keys' => ['last_activity'],
    'timeout' => 1800,
    'post_grace' => 30,
    'login_path' => $guardLoginPath,
    'flash_key' => 'loginStudent',
    'timeout_message' => 'Your session has expired. Please log in again.',
    'login_message' => 'Please log in to continue.',
]);

require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/includes/portal_access.php';
require_once dirname(__DIR__, 2) . '/includes/portal_context.php';

$guardRequestUri = str_replace('\\', '/', (string)($_SERVER['REQUEST_URI'] ?? ''));
$guardPortalContext = wuc_portal_context_from_request($guardRequestUri);
$guardPortalCode = wuc_portal_code_from_context($guardPortalContext);
wuc_require_portal_access($db, $guardPortalCode);
wuc_set_portal_context($guardPortalContext);

if (!empty($_SESSION['Sid']) && isset($db) && $db instanceof mysqli) {
    $stmtStatus = $db->prepare("SELECT status FROM students WHERE SID = ? LIMIT 1");
    if ($stmtStatus) {
        $stmtStatus->bind_param("s", $_SESSION['Sid']);
        $stmtStatus->execute();
        $stmtStatus->bind_result($stdStatus);
        if ($stmtStatus->fetch()) {
            $stdStatus = strtolower(trim($stdStatus));
            if (in_array($stdStatus, ['inactive', 'suspended', 'blocked', 'disabled', 'withdrawn', 'deleted'], true)) {
                $stmtStatus->close();
                // Clear session and redirect to login
                wuc_guard_clear_session();
                session_start();
                $_SESSION['loginStudent'] = 'Your student account is not active. Please contact the administrator.';
                wuc_safe_redirect($guardLoginPath, 302, $guardLoginPath);
            }
        }
        $stmtStatus->close();
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_role'])) {
    $_SESSION['user_role'] = 'student';
}

// First-login password changes are the only permitted destination while this
// flag is active.
if (!empty($_SESSION['must_change_password'])) {
    $guardScript = basename($_SERVER['PHP_SELF'] ?? '');
    if ($guardScript !== 'change_password.php') {
        wuc_safe_redirect(
            '/wucportal/students/change_password.php',
            302,
            $guardLoginPath
        );
    }
}
