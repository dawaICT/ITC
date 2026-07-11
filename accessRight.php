<?php

require_once __DIR__ . '/includes/auth_helpers.php';
wuc_secure_session_start();
wuc_security_headers();

require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/includes/login_activity_logger.php';
require_once __DIR__ . '/includes/hos_section_helpers.php';
require_once __DIR__ . '/includes/staff_role_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    wuc_redirect('staff_login.php', 302);
}

$staffId = trim((string) ($_POST['staff_id'] ?? ($_POST['user_id'] ?? '')));
$password = (string) ($_POST['pass'] ?? ($_POST['password'] ?? ''));

if ($staffId === '' || $password === '') {
    $_SESSION['errorMessage'] = 'Please enter both Staff ID and Password.';
    wuc_redirect('staff_login.php');
}

if (wuc_is_login_locked($db, 'access-right', $staffId)) {
    $_SESSION['errorMessage'] = 'Too many failed attempts. Please try again in 15 minutes.';
    wuc_redirect('staff_login.php');
}

$stmt = $db->prepare(
    'SELECT id, staff_id, Fname, Lname, password, role, status
     FROM staff
     WHERE staff_id = ?
     LIMIT 1'
);
if (!$stmt) {
    $_SESSION['errorMessage'] = 'Login service temporarily unavailable.';
    wuc_redirect('staff_login.php');
}
$stmt->bind_param('s', $staffId);
$stmt->execute();
$staff = $stmt->get_result()->fetch_assoc();
$stmt->close();

[$ok, $needsRehash] = wuc_password_verify_legacy($password, (string) ($staff['password'] ?? ''));
if (!$staff || !$ok || !in_array(strtolower((string) $staff['status']), ['active', 'enabled'], true)) {
    wuc_record_failed_login($db, 'access-right', $staffId);
    $_SESSION['errorMessage'] = 'Invalid Staff ID or Password.';
    wuc_redirect('staff_login.php');
}

session_regenerate_id(true);
if ($needsRehash) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $update = $db->prepare('UPDATE staff SET password = ? WHERE id = ?');
    if ($update) {
        $rowId = (int) $staff['id'];
        $update->bind_param('si', $hash, $rowId);
        $update->execute();
        $update->close();
    }
}

$name = trim((string) $staff['Fname'] . ' ' . (string) $staff['Lname']);
$_SESSION['staff_id'] = $staffId;
$_SESSION['user_id'] = $staffId;
$_SESSION['user_name'] = $name !== '' ? $name : $staffId;
$_SESSION['user_role'] = 'staff';
// Start with the staff-row fallback, then replace it from the authoritative
// positions/access-right chain when assignments exist.
$role = wuc_normalize_staff_role((string) $staff['role']);
$_SESSION['role'] = $role;
$_SESSION['role_raw'] = (string) ($staff['role'] ?? 'Staff');
$_SESSION['all_roles'] = [$role];
$_SESSION['all_roles_raw'] = [(string) ($staff['role'] ?? 'Staff')];
wuc_hydrate_staff_roles($db, $staffId);
$role = (string) ($_SESSION['role'] ?? $role);
$_SESSION['logged_in'] = true;
if (in_array($role, ['head_of_department', 'systems_admin'], true)) {
    hos_hydrate_section_session($db, $staffId);
}
wuc_clear_login_attempts($db, 'access-right', $staffId);
wuc_log_login($db, $staffId, 'staff', $_SESSION['user_name']);

// All staff route through the portal selection page: it auto-forwards
// single-portal accounts straight to the module dashboard their role guard
// accepts, and only renders a chooser when the account has several portals.
wuc_redirect('portal_selection.php');
