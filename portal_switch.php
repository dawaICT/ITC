<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth_helpers.php';
wuc_secure_session_start();
wuc_security_headers();

require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/includes/role_helpers.php';
require_once __DIR__ . '/includes/portal_switch.php';

$userKind = wuc_portal_switch_user_kind();
$loginPath = $userKind === 'student' ? '/wucportal/student_login.php' : '/wucportal/staff_login.php';
$hasIdentity = $userKind === 'student'
    ? !empty($_SESSION['Sid'])
    : (!empty($_SESSION['staff_id']) || !empty($_SESSION['user_id']));

if (!$hasIdentity) {
    header('Location: ' . $loginPath, true, 302);
    exit;
}

$targetPortal = strtolower(trim((string)($_REQUEST['portal'] ?? $_REQUEST['target'] ?? '')));
$requestedKind = strtolower(trim((string)($_REQUEST['as'] ?? '')));
if (!in_array($requestedKind, ['student', 'staff', 'lecturer'], true)) {
    $requestedKind = $userKind;
}

try {
    $redirectTo = wuc_apply_portal_switch($db, $targetPortal, $requestedKind);
    header('Location: ' . $redirectTo, true, 302);
    exit;
} catch (Throwable $e) {
    error_log('portal_switch.php failed: ' . $e->getMessage());
    $flashKey = $userKind === 'student' ? 'errorMssg' : 'errorMessage';
    $_SESSION[$flashKey] = 'You do not have permission to access the selected portal.';
    header('Location: ' . ($userKind === 'student' ? '/wucportal/students/index.php' : '/wucportal/portal_selection.php'), true, 302);
    exit;
}
