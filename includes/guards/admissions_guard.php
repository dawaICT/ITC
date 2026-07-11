<?php
declare(strict_types=1);
/**
 * admissions_guard.php — restrict a page to the Admissions area.
 *
 * Two-step gate, both delegated to canonical helpers:
 *   1. checkStaffAuth()      — must be a logged-in staff member.
 *   2. canAccessAdmissions() — role must be systems_admin, admission_officer,
 *                              or registrar (same rule the admissions nav uses).
 *
 * On failure step 1 redirects to staff_login.php; step 2 redirects to the staff
 * hub with an access-denied flash — never to an admin page. This is the backend
 * counterpart to the admissions nav's required_access => 'admissions' guard, so
 * admissions processors can enforce access WITHOUT pulling in admin-only files.
 *
 *   require_once __DIR__ . '/../includes/guards/admissions_guard.php';
 */

require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../role_helpers.php';

checkStaffAuth();

if (!canAccessAdmissions()) {
    $_SESSION['errorMessage'] = 'Access denied. Admissions access is required.';
    header('Location: ' . STAFF_DASHBOARD);
    exit;
}
