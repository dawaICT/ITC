<?php
declare(strict_types=1);

/**
 * User & Role Management — RETIRED (consolidated 2026-07-08)
 * ------------------------------------------------------------------
 * This page read/assigned roles through the LEGACY `positions` /
 * `staff_positions` tables and displayed a "Permissions Overview" that
 * queried `role_permissions.PosID` / `role_permissions.permission_name` —
 * columns that no longer exist on the migrated `role_permissions` table,
 * so that tab threw a fatal SQL error against the live schema.
 *
 * The modern RBAC model (users → user_roles → role_permissions →
 * permissions) fully supersedes it. Staff/lecturer role management now
 * lives in a single hardened page.
 *
 * Retired in favour of:
 *   - Staff & Lecturer Roles ....... users_roles.php
 *   - Portal Permission Scope ...... portal_permission_scope.php
 *
 * Kept (not deleted) so existing bookmarks resolve to a clear notice
 * instead of a 404 / SQL fatal, and admin-guarded like its replacements.
 */

$page_title = 'User & Role Management (retired)';

require_once dirname(__DIR__) . '/config/auth_check.php';
checkAdminAuth(); // systems_admin only

require_once dirname(__DIR__) . '/db/connect.php';          // ensure $db for the chrome (idempotent)
require_once dirname(__DIR__) . '/includes/role_helpers.php'; // canAccess* helpers used by nav.php

require "includes/nav.php";
?>
<div class="container-fluid px-4">
    <h1 class="mt-4">User &amp; Role Management</h1>
    <ol class="breadcrumb mb-4">
        <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
        <li class="breadcrumb-item active">User &amp; Role Management (retired)</li>
    </ol>

    <div class="card border-warning mb-4">
        <div class="card-header bg-warning-subtle">
            <i class="fas fa-triangle-exclamation me-2"></i>This page has been retired
        </div>
        <div class="card-body">
            <p class="mb-2">
                <strong>User &amp; Role Management</strong> managed roles through the legacy
                <code>positions</code> / <code>staff_positions</code> tables, which have been consolidated
                into the portal's modern role-based access control. Its "Permissions Overview" also queried
                columns that no longer exist on the migrated schema.
            </p>
            <p class="text-muted mb-4">
                No action is needed &mdash; existing access is unchanged. Please use the pages below instead.
            </p>

            <div class="list-group">
                <a href="users_roles.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <div>
                        <span class="fw-bold">Staff &amp; Lecturer Roles</span>
                        <div class="small text-muted">Assign/revoke internal staff &amp; lecturer roles, grant per-user access, and map role permissions.</div>
                    </div>
                    <i class="fas fa-arrow-right"></i>
                </a>
                <a href="portal_permission_scope.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <div>
                        <span class="fw-bold">Portal Permission Scope</span>
                        <div class="small text-muted">Scope each role's permissions to a specific portal, or keep them global.</div>
                    </div>
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <div class="mt-4">
                <a href="users_roles.php" class="btn btn-primary">
                    <i class="fas fa-users-cog me-1"></i> Go to Staff &amp; Lecturer Roles
                </a>
            </div>
        </div>
    </div>
</div>

<?php require "includes/footer.php"; ?>
