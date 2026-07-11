<?php
declare(strict_types=1);

/**
 * Define Access — RETIRED (consolidated 2026-07-08)
 * ------------------------------------------------------------------
 * This page previously assigned staff roles into the LEGACY
 * `staff_positions` / `positions` tables. That parallel role store has
 * been fully superseded by the modern RBAC model (users → user_roles →
 * role_permissions → permissions), and every staff account is already
 * covered by an active `user_roles` row, so this editor no longer grants
 * any real access.
 *
 * It also carried real security defects (no CSRF token, no admin-role
 * re-check in its POST handler, unescaped output), so rather than patch a
 * dead page it is retired in favour of the canonical, hardened pages:
 *
 *   - Staff & Lecturer Roles ....... users_roles.php
 *   - Portal Access ................ portal_access.php
 *   - Portal Permission Scope ...... portal_permission_scope.php
 *
 * The file is kept (not deleted) so existing bookmarks/links resolve to a
 * clear notice instead of a 404, and it is admin-guarded like the pages it
 * replaces.
 */

$page_title = 'Define Access (retired)';

require_once dirname(__DIR__) . '/config/auth_check.php';
checkAdminAuth(); // systems_admin only — matches the canonical access-control pages

require_once dirname(__DIR__) . '/db/connect.php';          // ensure $db for the chrome (idempotent)
require_once dirname(__DIR__) . '/includes/role_helpers.php'; // canAccess* helpers used by nav.php

$canonical = 'users_roles.php';
require "includes/nav.php";
?>
<div class="container-fluid px-4">
    <h1 class="mt-4">Define Access</h1>
    <ol class="breadcrumb mb-4">
        <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
        <li class="breadcrumb-item active">Define Access (retired)</li>
    </ol>

    <div class="card border-warning mb-4">
        <div class="card-header bg-warning-subtle">
            <i class="fas fa-triangle-exclamation me-2"></i>This page has been retired
        </div>
        <div class="card-body">
            <p class="mb-2">
                <strong>Define Access</strong> managed the legacy staff&nbsp;&rarr;&nbsp;position role store
                (<code>staff_positions</code>), which has been consolidated into the portal's modern
                role-based access control. Staff and lecturer roles are now managed in one place, with CSRF
                protection, admin-only enforcement and audit logging.
            </p>
            <p class="text-muted mb-4">
                No action is needed &mdash; existing access is unchanged. Please use the pages below instead.
            </p>

            <div class="list-group">
                <a href="users_roles.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <div>
                        <span class="fw-bold">Staff &amp; Lecturer Roles</span>
                        <div class="small text-muted">Assign and revoke internal staff/lecturer roles and per-user access.</div>
                    </div>
                    <i class="fas fa-arrow-right"></i>
                </a>
                <a href="portal_access.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <div>
                        <span class="fw-bold">Portal Access</span>
                        <div class="small text-muted">Control which portals (Academic, eLearning, Library, Applicant, Alumni, Employer) a user may enter.</div>
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
                <a href="<?php echo htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary">
                    <i class="fas fa-users-cog me-1"></i> Go to Staff &amp; Lecturer Roles
                </a>
            </div>
        </div>
    </div>
</div>

<?php require "includes/footer.php"; ?>
