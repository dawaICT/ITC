<?php

require_once dirname(__DIR__) . '/includes/auth_helpers.php';
wuc_secure_session_start();
wuc_security_headers();

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/login_activity_logger.php';

function legacy_login_fail(string $message = 'Login Failed'): void
{
    $_SESSION['errorMessage'] = $message;
    header('Location: index.php', true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: index.php', true, 302);
    exit;
}

$isStaffLogin = isset($_POST['login-staff']);
$isStudentLogin = isset($_POST['login-student']);
$username = trim((string) ($_POST['user_name'] ?? ''));
$password = (string) ($_POST['pass'] ?? '');

if ($username === '' || $password === '' || (!$isStaffLogin && !$isStudentLogin)) {
    legacy_login_fail();
}

$scope = $isStaffLogin ? 'admin-staff' : 'admin-student';
if (wuc_is_login_locked($db, $scope, $username)) {
    legacy_login_fail('Too many failed attempts. Please try again in 15 minutes.');
}

if ($isStaffLogin) {
    $stmt = $db->prepare(
        'SELECT id, staff_id, Fname, Lname, password, role, status
         FROM staff
         WHERE staff_id = ? OR email = ?
         LIMIT 1'
    );
    if (!$stmt) {
        legacy_login_fail('Login service temporarily unavailable.');
    }
    $stmt->bind_param('ss', $username, $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    [$ok, $needsRehash] = wuc_password_verify_legacy($password, (string) ($user['password'] ?? ''));
    if (!$user || !$ok || !in_array(strtolower((string) $user['status']), ['active', 'enabled'], true)) {
        wuc_record_failed_login($db, $scope, $username);
        legacy_login_fail();
    }

    session_regenerate_id(true);
    $staffId = (string) $user['staff_id'];
    $name = trim((string) $user['Fname'] . ' ' . (string) $user['Lname']);
    if ($needsRehash) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $update = $db->prepare('UPDATE staff SET password = ? WHERE id = ?');
        if ($update) {
            $rowId = (int) $user['id'];
            $update->bind_param('si', $hash, $rowId);
            $update->execute();
            $update->close();
        }
    }

    $_SESSION['index'] = 'admin';
    $_SESSION['user_name'] = $name !== '' ? $name : $staffId;
    $_SESSION['user_id'] = $staffId;
    $_SESSION['staff_id'] = $staffId;
    $_SESSION['user_role'] = 'staff';
    $_SESSION['role'] = wuc_normalize_staff_role((string) $user['role']);
    $_SESSION['logged_in'] = true;
    wuc_clear_login_attempts($db, $scope, $username);
    wuc_log_login($db, $staffId, 'staff', $_SESSION['user_name']);
    header('Location: dashboard_admin.php', true, 303);
    exit;
}

$stmt = $db->prepare(
    'SELECT sl.Sid, sl.Password, s.Fname, s.Lname, s.status
     FROM student_login sl
     LEFT JOIN students s ON s.SID = sl.Sid
     WHERE sl.Sid = ?
     LIMIT 1'
);
if (!$stmt) {
    legacy_login_fail('Login service temporarily unavailable.');
}
$stmt->bind_param('s', $username);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

[$ok, $needsRehash] = wuc_password_verify_legacy($password, (string) ($student['Password'] ?? ''));
if (!$student || !$ok || !in_array(strtolower((string) ($student['status'] ?? 'active')), ['active', 'enabled'], true)) {
    wuc_record_failed_login($db, $scope, $username);
    legacy_login_fail();
}

session_regenerate_id(true);
$sid = (string) $student['Sid'];
$name = trim((string) ($student['Fname'] ?? '') . ' ' . (string) ($student['Lname'] ?? ''));
if ($needsRehash) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $update = $db->prepare('UPDATE student_login SET Password = ? WHERE Sid = ?');
    if ($update) {
        $update->bind_param('ss', $hash, $sid);
        $update->execute();
        $update->close();
    }
}

$_SESSION['index1'] = 'student';
$_SESSION['user_name'] = $name !== '' ? $name : $sid;
$_SESSION['Sid'] = $sid;
$_SESSION['student_id'] = $sid;
$_SESSION['user_id'] = $sid;
$_SESSION['user_role'] = 'student';
$_SESSION['logged_in'] = true;
wuc_clear_login_attempts($db, $scope, $username);
wuc_log_login($db, $sid, 'student', $_SESSION['user_name']);
header('Location: student_dashboard.php', true, 303);
exit;
