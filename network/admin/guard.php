<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/session.php';
require_once dirname(__DIR__, 2) . '/includes/auth_helpers.php';
wuc_network_session_start();
wuc_security_headers();

require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/includes/role_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_portal/bootstrap.php';

if (!function_exists('wuc_network_is_authenticated') || !wuc_network_is_authenticated()) {
    wuc_redirect('/wucportal/network/login.php');
}

if (!ep_is_platform_admin($db)) {
    $_SESSION['network_flash_error'] = 'Platform administrator access only.';
    wuc_redirect('/wucportal/network/dashboard.php');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
ep_ensure_organization_context($db, $userId);

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = function_exists('wuc_csrf_token') ? wuc_csrf_token() : (string)$_SESSION['csrf_token'];

$flashSuccess = (string)($_SESSION['network_flash_success'] ?? '');
$flashError = (string)($_SESSION['network_flash_error'] ?? '');
unset($_SESSION['network_flash_success'], $_SESSION['network_flash_error']);
