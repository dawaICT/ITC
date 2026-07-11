<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth_helpers.php';
wuc_secure_session_start();
wuc_security_headers();

require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/includes/portal_access.php';

// Staff logins set logged_in; student logins set Sid. Accept either — the
// portal-access check below still enforces the 'applicant' grant.
if (empty($_SESSION['logged_in']) && empty($_SESSION['Sid'])) {
    $_SESSION['errorMessage'] = 'Please log in to view your application status.';
    header('Location: /wucportal/staff_login.php', true, 303);
    exit;
}

$userId = wuc_resolve_session_user_id($db);
if ($userId <= 0) {
    $_SESSION['errorMessage'] = 'Unable to resolve your account. Please contact the admissions office.';
    header('Location: /wucportal/staff_login.php', true, 303);
    exit;
}

wuc_require_portal_access($db, 'applicant', 'You do not have permission to access the Applicant Portal.');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!function_exists('applicant_portal_h')) {
    function applicant_portal_h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
