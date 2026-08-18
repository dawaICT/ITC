<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/session.php';
require_once dirname(__DIR__, 2) . '/includes/auth_helpers.php';
wuc_network_session_start();
if (function_exists('wuc_security_headers')) {
    wuc_security_headers();
}

require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_portal/bootstrap.php';

// Public zone — no login; never sets auth_realm or academic portal context.

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
