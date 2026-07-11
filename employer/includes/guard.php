<?php
/**
 * Employer Portal Guard
 *
 * Access is grant-based via the multi-portal layer (user_portal_access /
 * portals, portal_code 'employer'), the same mechanism portal_selection.php
 * uses to show the Employer tile. A legacy $_SESSION['role'] of 'employer' or
 * 'systems_admin' is still honoured for accounts created before the
 * portal-access layer existed.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth_helpers.php';
wuc_secure_session_start();
wuc_security_headers();

require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/includes/portal_access.php';

if (!defined('IS_SCRIPT')) {
    // Not logged in at all (neither staff nor student session) → login page.
    if (empty($_SESSION['logged_in']) && empty($_SESSION['Sid'])) {
        $_SESSION['errorMessage'] = 'Please log in to access the Employer Portal.';
        header('Location: /wucportal/staff_login.php', true, 303);
        exit;
    }

    $legacyRole = (string)($_SESSION['role'] ?? '');
    if ($legacyRole === 'employer' || $legacyRole === 'systems_admin') {
        wuc_resolve_session_user_id($db); // backfill user_id_db when mapped
        $_SESSION['current_portal'] = 'employer';
    } else {
        // Denials are audit-logged and redirected to portal_selection.php.
        wuc_require_portal_access($db, 'employer', 'You do not have permission to access the Employer Portal.');
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
