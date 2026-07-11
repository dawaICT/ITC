<?php
require_once __DIR__ . '/auth_constants.php';
require_once dirname(__DIR__) . '/includes/staff_role_helpers.php';

if (!defined('AUTH_LOGIN_PATH')) {
    define('AUTH_LOGIN_PATH', APP_BASE_PATH . '/staff_login.php');
}

function authRedirectToLogin(): void
{
    header('Location: ' . AUTH_LOGIN_PATH);
    exit;
}

/** Keep legacy and canonical staff session identifiers aligned. */
function syncLegacyStaffSessionKey(): void
{
    if (!empty($_SESSION['user_id']) && empty($_SESSION['staff_id'])) {
        $_SESSION['staff_id'] = $_SESSION['user_id'];
    } elseif (!empty($_SESSION['staff_id']) && empty($_SESSION['user_id'])) {
        $_SESSION['user_id'] = $_SESSION['staff_id'];
    }
}

/** Hydrate every assigned role using the shared authoritative role resolver. */
function hydrateStaffRolesFromDatabase(string $staffId): void
{
    global $db;

    $staffId = trim($staffId);
    if ($staffId === '') {
        return;
    }

    if (!isset($db) || !$db instanceof mysqli) {
        require dirname(__DIR__) . '/db/connect.php';
    }
    if (isset($db) && $db instanceof mysqli && !$db->connect_errno) {
        wuc_hydrate_staff_roles($db, $staffId);
    }
}

function checkStaffAuth(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    syncLegacyStaffSessionKey();

    $userId = (string) ($_SESSION['user_id'] ?? '');
    $userRole = (string) ($_SESSION['user_role'] ?? '');
    if ($userId === '' || $userRole !== 'staff') {
        authRedirectToLogin();
    }
}

function checkAdminAuth(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    syncLegacyStaffSessionKey();

    $userId = trim((string) ($_SESSION['user_id'] ?? ''));
    if ($userId === '') {
        authRedirectToLogin();
    }

    $role = wuc_normalize_staff_role((string) ($_SESSION['role'] ?? ''), false);
    if ($role !== 'systems_admin') {
        hydrateStaffRolesFromDatabase($userId);
        $role = wuc_normalize_staff_role((string) ($_SESSION['role'] ?? ''), false);
    }

    if ($role !== 'systems_admin') {
        $_SESSION['errorMssg'] = 'Access denied. Admin privileges are required.';
        header('Location: ' . STAFF_DASHBOARD);
        exit;
    }
}
