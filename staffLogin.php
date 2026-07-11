<?php

require_once __DIR__ . '/includes/auth_helpers.php';
wuc_secure_session_start();
wuc_security_headers();

require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/includes/login_activity_logger.php';
require_once __DIR__ . '/config/auth_check.php';
require_once __DIR__ . '/includes/hos_section_helpers.php';
require_once __DIR__ . '/includes/helpers/redirect_helper.php';
require_once __DIR__ . '/includes/portal_access.php';

const WUC_MAX_LOGIN_ATTEMPTS = 5;
const WUC_LOGIN_LOCKOUT_SECONDS = 900;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    // Use the helper's default 303 (See Other) like every other redirect in this file,
    // which is the correct status for redirecting a non-POST hit on a POST-only endpoint.
    wuc_redirect(AUTH_LOGIN_PATH);
}

$userId = trim((string) ($_POST['user_id'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if (wuc_is_login_locked($db, 'staff', $userId, WUC_MAX_LOGIN_ATTEMPTS, WUC_LOGIN_LOCKOUT_SECONDS)) {
    $_SESSION['errorMessage'] = 'Too many failed login attempts. Please try again in 15 minutes.';
    wuc_redirect(AUTH_LOGIN_PATH);
}

if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
    wuc_record_failed_login($db, 'staff', $userId);
    $_SESSION['errorMessage'] = 'Security token mismatch. Please refresh the page and try again.';
    wuc_redirect(AUTH_LOGIN_PATH);
}

if ($userId === '' || $password === '') {
    wuc_record_failed_login($db, 'staff', $userId);
    $_SESSION['errorMessage'] = 'Please enter both Staff ID and Password.';
    wuc_redirect(AUTH_LOGIN_PATH);
}

if (!preg_match('/^[A-Za-z0-9_.@-]{3,50}$/', $userId)) {
    wuc_record_failed_login($db, 'staff', $userId);
    $_SESSION['errorMessage'] = 'Invalid Staff ID or Password.';
    wuc_redirect(AUTH_LOGIN_PATH);
}

$stmt = $db->prepare(
    'SELECT u.user_id, u.username, u.password AS user_pass, u.primary_role, u.staff_id,
            s.id AS staff_row_id, s.Fname, s.Lname, s.status, s.failed_attempts, s.lockout_until
     FROM users u
     JOIN staff s ON s.staff_id = u.staff_id
     WHERE u.username = ? AND u.staff_id IS NOT NULL
     LIMIT 1'
);

if (!$stmt) {
    error_log('Staff login prepare failed: ' . $db->error);
    $_SESSION['errorMessage'] = 'Login service temporarily unavailable. Please try again shortly.';
    wuc_redirect(AUTH_LOGIN_PATH);
}

$stmt->bind_param('s', $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result ? $result->fetch_assoc() : null;
$stmt->close();

$storedHash = $user['user_pass'] ?? password_hash('dummy-password', PASSWORD_DEFAULT);
[$passwordOk, $needsRehash] = wuc_password_verify_legacy($password, (string) $storedHash);

// Self-heal accounts where only staff.password was updated (e.g. older reset flows).
if (!$user || !$passwordOk) {
    $existingUserId = $user['user_id'] ?? null;
    $legacyStmt = $db->prepare(
        'SELECT id AS staff_row_id, staff_id, password AS user_pass, Fname, Lname, status, role
         FROM staff WHERE staff_id = ? LIMIT 1'
    );
    if ($legacyStmt) {
        $legacyStmt->bind_param('s', $userId);
        $legacyStmt->execute();
        $legacy = $legacyStmt->get_result()->fetch_assoc();
        $legacyStmt->close();

        if ($legacy) {
            [$legacyOk, $legacyRehash] = wuc_password_verify_legacy($password, (string) ($legacy['user_pass'] ?? ''));
            if ($legacyOk) {
                $user = $legacy;
                if ($existingUserId) {
                    $user['user_id'] = $existingUserId;
                }
                $passwordOk = true;
                $needsRehash = $legacyRehash;

                $legacyHash = $needsRehash ? password_hash($password, PASSWORD_DEFAULT) : (string) $legacy['user_pass'];
                wuc_sync_staff_password(
                    $db,
                    (string) $legacy['staff_id'],
                    $legacyHash,
                    wuc_normalize_staff_role((string) ($legacy['role'] ?? 'staff')),
                    (string) ($legacy['status'] ?? 'active')
                );

                if (empty($user['user_id'])) {
                    $uidStmt = $db->prepare('SELECT user_id FROM users WHERE staff_id = ? OR username = ? LIMIT 1');
                    if ($uidStmt) {
                        $legacyStaffId = (string) $legacy['staff_id'];
                        $uidStmt->bind_param('ss', $legacyStaffId, $legacyStaffId);
                        $uidStmt->execute();
                        $uidStmt->bind_result($resolvedUserId);
                        if ($uidStmt->fetch()) {
                            $user['user_id'] = (int) $resolvedUserId;
                        }
                        $uidStmt->close();
                    }
                }
            }
        }
    }
}

if (!$user || !$passwordOk) {
    wuc_record_failed_login($db, 'staff', $userId);

    if ($user) {
        $attempts = ((int) ($user['failed_attempts'] ?? 0)) + 1;
        $lockoutUntil = null;
        if ($attempts >= WUC_MAX_LOGIN_ATTEMPTS) {
            $lockoutUntil = date('Y-m-d H:i:s', time() + WUC_LOGIN_LOCKOUT_SECONDS);
            $attempts = 0;
        }

        $update = $db->prepare('UPDATE staff SET failed_attempts = ?, lockout_until = ? WHERE id = ?');
        if ($update) {
            $staffRowId = (int) $user['staff_row_id'];
            $update->bind_param('isi', $attempts, $lockoutUntil, $staffRowId);
            $update->execute();
            $update->close();
        }
    }

    $_SESSION['errorMessage'] = 'Invalid Staff ID or Password.';
    wuc_redirect(AUTH_LOGIN_PATH);
}

if (empty($user['user_id'])) {
    error_log('Staff login missing users.user_id for staff_id ' . ($user['staff_id'] ?? $userId));
    $_SESSION['errorMessage'] = 'Login service temporarily unavailable. Please try again shortly.';
    wuc_redirect(AUTH_LOGIN_PATH);
}

$status = strtolower(trim((string) ($user['status'] ?? '')));
if (!in_array($status, ['active', 'enabled'], true)) {
    $_SESSION['errorMessage'] = 'Your account is not active. Please contact the administrator.';
    wuc_redirect(AUTH_LOGIN_PATH);
}

if (!empty($user['lockout_until']) && strtotime((string) $user['lockout_until']) > time()) {
    $_SESSION['errorMessage'] = 'Account temporarily locked due to too many failed attempts. Please try again later.';
    wuc_redirect(AUTH_LOGIN_PATH);
}

session_regenerate_id(true);
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$staffId = (string) $user['staff_id'];
$staffName = trim((string) ($user['Fname'] ?? '') . ' ' . (string) ($user['Lname'] ?? ''));
$role = wuc_normalize_staff_role((string) ($user['primary_role'] ?? 'staff'));

$newHash = null;
if ($needsRehash) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
}

if ($newHash !== null) {
    $reset = $db->prepare('UPDATE users SET password = ? WHERE user_id = ?');
    if ($reset) {
        $userIdDb = (int) $user['user_id'];
        $reset->bind_param('si', $newHash, $userIdDb);
        $reset->execute();
        $reset->close();
    }
    $resetStaff = $db->prepare('UPDATE staff SET failed_attempts = 0, lockout_until = NULL, last_login = NOW() WHERE id = ?');
    if ($resetStaff) {
        $staffRowId = (int) $user['staff_row_id'];
        $resetStaff->bind_param('i', $staffRowId);
        $resetStaff->execute();
        $resetStaff->close();
    }
} else {
    $resetStaff = $db->prepare('UPDATE staff SET failed_attempts = 0, lockout_until = NULL, last_login = NOW() WHERE id = ?');
    if ($resetStaff) {
        $staffRowId = (int) $user['staff_row_id'];
        $resetStaff->bind_param('i', $staffRowId);
        $resetStaff->execute();
        $resetStaff->close();
    }
}

wuc_clear_login_attempts($db, 'staff', $staffId);
wuc_log_login($db, $staffId, 'staff', $staffName);

$_SESSION['user_id'] = $staffId;
$_SESSION['staff_id'] = $staffId;
$_SESSION['db_id'] = (int) $user['staff_row_id'];
$_SESSION['user_id_db'] = (int) $user['user_id'];
$_SESSION['user_name'] = $staffName !== '' ? $staffName : $staffId;
$_SESSION['role_login'] = (string) ($user['primary_role'] ?? '');
$_SESSION['role'] = $role;
$_SESSION['user_role'] = 'staff';
$_SESSION['logged_in'] = true;
$_SESSION['last_activity'] = time();

if (function_exists('hydrateStaffRolesFromDatabase')) {
    hydrateStaffRolesFromDatabase($staffId);
}
if (empty($_SESSION['all_roles'])) {
    require_once __DIR__ . '/includes/helpers/staff_provisioning.php';
    $loginRole = wuc_normalize_staff_role((string)($_SESSION['role'] ?? 'staff'));
    wuc_provision_staff_account($db, $staffId, $loginRole, null, 'login_repair');
    hydrateStaffRolesFromDatabase($staffId);
}
if (in_array(($_SESSION['role'] ?? ''), ['head_of_department', 'systems_admin'], true)) {
    hos_hydrate_section_session($db, $staffId);
}

wuc_redirect(wuc_after_login_portal_url($db, (int)$user['user_id'], 'staff'));
