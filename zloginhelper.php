<?php
// TEMP diagnostic: mint a valid HOS session (no password), like staffLogin.php.
// Usage: /wucportal/_dss_probe_login.php?sid=ITC911
require_once __DIR__ . '/includes/auth_helpers.php';
wuc_secure_session_start();
require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/config/auth_check.php';
require_once __DIR__ . '/includes/hos_section_helpers.php';

$staffId = preg_replace('/[^A-Za-z0-9_.@-]/', '', (string)($_GET['sid'] ?? 'ITC911'));

$stmt = $db->prepare(
    'SELECT u.user_id, u.staff_id, u.primary_role, s.id AS staff_row_id, s.Fname, s.Lname, s.status
     FROM users u JOIN staff s ON s.staff_id = u.staff_id WHERE u.staff_id = ? LIMIT 1'
);
$stmt->bind_param('s', $staffId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) { http_response_code(404); echo "no such staff: $staffId"; exit; }

session_regenerate_id(true);
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$staffName = trim((string)$user['Fname'] . ' ' . (string)$user['Lname']);
$role = wuc_normalize_staff_role((string)($user['primary_role'] ?? 'staff'));
$_SESSION['user_id'] = $staffId;
$_SESSION['staff_id'] = $staffId;
$_SESSION['db_id'] = (int)$user['staff_row_id'];
$_SESSION['user_id_db'] = (int)$user['user_id'];
$_SESSION['user_name'] = $staffName ?: $staffId;
$_SESSION['role_login'] = (string)($user['primary_role'] ?? '');
$_SESSION['role'] = $role;
$_SESSION['user_role'] = 'staff';
$_SESSION['logged_in'] = true;
$_SESSION['last_activity'] = time();

if (function_exists('hydrateStaffRolesFromDatabase')) {
    hydrateStaffRolesFromDatabase($staffId);
}
if (empty($_SESSION['all_roles'])) {
    require_once __DIR__ . '/includes/helpers/staff_provisioning.php';
    wuc_provision_staff_account($db, $staffId, $role, null, 'probe');
    hydrateStaffRolesFromDatabase($staffId);
}
if (in_array(($_SESSION['role'] ?? ''), ['head_of_department', 'systems_admin'], true)) {
    hos_hydrate_section_session($db, $staffId);
}
// Force academic portal context so portal_access passes.
$_SESSION['active_portal'] = 'academic';
$_SESSION['portal_id'] = 'academic';

echo "OK sid=$staffId role={$_SESSION['role']} section=" . ($_SESSION['hos_section_id'] ?? '(none)')
   . " sess=" . session_id() . "\n";
echo "all_roles=" . json_encode($_SESSION['all_roles'] ?? null) . "\n";
