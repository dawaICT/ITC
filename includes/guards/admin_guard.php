<?php
declare(strict_types=1);
/**
 * admin_guard.php — restrict a page to systems_admin only.
 *
 * Delegates to the canonical checkAdminAuth(), which normalizes the session
 * role, re-hydrates from the database if needed, and redirects non-admins to the
 * staff hub (STAFF_DASHBOARD) with an access-denied flash. Use this at the top of
 * any admin-only page instead of re-implementing the role check inline.
 *
 *   require_once __DIR__ . '/../includes/guards/admin_guard.php';
 */

require_once __DIR__ . '/../../config/auth_check.php';

checkAdminAuth();
