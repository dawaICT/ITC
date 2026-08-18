<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/session.php';

/**
 * Network-only authentication (separate session from WUCPortal academic login).
 */
function network_process_login(mysqli $db): void
{
    require_once dirname(__DIR__, 2) . '/includes/auth_helpers.php';
    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        $_SESSION['network_flash_error'] = 'Security token mismatch. Refresh and try again.';
        wuc_redirect('/wucportal/network/login.php');
    }

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $_SESSION['network_flash_error'] = 'Enter username and password.';
        wuc_redirect('/wucportal/network/login.php');
    }

    $user = null;
    $stmt = $db->prepare('SELECT user_id, username, password, primary_role, staff_id FROM users WHERE username = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$user) {
        $_SESSION['network_flash_error'] = 'Invalid credentials.';
        wuc_redirect('/wucportal/network/login.php');
    }

    $hash = (string)($user['password'] ?? '');
    [$ok] = function_exists('wuc_password_verify_legacy')
        ? wuc_password_verify_legacy($password, $hash)
        : [password_verify($password, $hash), false];

    if (!$ok) {
        $_SESSION['network_flash_error'] = 'Invalid credentials.';
        wuc_redirect('/wucportal/network/login.php');
    }

    session_regenerate_id(true);
    $_SESSION['logged_in'] = true;
    $_SESSION['auth_realm'] = 'network';
    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['username'] = (string)$user['username'];
    $_SESSION['role'] = (string)($user['primary_role'] ?? '');
    $_SESSION['staff_id'] = $user['staff_id'] ?? null;

    require_once dirname(__DIR__, 2) . '/includes/role_helpers.php';
    if (function_exists('wuc_load_user_roles_into_session')) {
        wuc_load_user_roles_into_session($db, (int)$user['user_id']);
    }

    if (function_exists('ep_adapters')) {
        ep_adapters()['audit']->log($db, 'network.login', ['user_id' => (int)$user['user_id']]);
    }

    if (function_exists('ep_is_platform_admin') && ep_is_platform_admin($db)) {
        wuc_redirect('/wucportal/network/admin/');
    }
    wuc_redirect('/wucportal/network/dashboard.php');
}
