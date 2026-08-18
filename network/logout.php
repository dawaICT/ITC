<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
wuc_network_session_start();

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? true));
}
session_destroy();

wuc_redirect('/wucportal/network/');
