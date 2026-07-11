<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth_helpers.php';
wuc_secure_session_start();
wuc_security_headers();

require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/role_helpers.php';
require_once __DIR__ . '/../../includes/portal_switch.php';

try {
    wuc_apply_portal_switch($db, 'elearning', 'staff');
    header('Location: /wucportal/elearning/index.php', true, 302);
    exit;
} catch (Throwable $e) {
    error_log('staff/elearning/index.php failed: ' . $e->getMessage());
    $_SESSION['errorMessage'] = 'You do not have permission to access the eLearning Portal.';
    header('Location: /wucportal/portal_selection.php', true, 302);
    exit;
}
