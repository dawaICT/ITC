<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once dirname(__DIR__, 2) . '/includes/auth_helpers.php';
wuc_network_session_start();
wuc_security_headers();

require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/includes/role_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_portal/bootstrap.php';

if (!wuc_network_is_authenticated()) {
    wuc_redirect('/wucportal/network/login.php');
}

if (!ep_can_access_network_app($db)) {
    $_SESSION['network_flash_error'] = 'Your account does not have network member access. Contact a platform administrator or complete enterprise membership onboarding.';
    wuc_redirect('/wucportal/network/login.php');
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
