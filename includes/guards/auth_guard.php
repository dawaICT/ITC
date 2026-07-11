<?php
declare(strict_types=1);
/**
 * auth_guard.php — generic "must be a logged-in staff member" guard.
 *
 * Include this at the very top of any staff page that is NOT restricted to a
 * single module/role. It delegates to the canonical checkStaffAuth(), which
 * verifies $_SESSION['user_role'] === 'staff' and redirects to staff_login.php
 * otherwise. No role-specific logic lives here.
 *
 *   require_once __DIR__ . '/../includes/guards/auth_guard.php';
 */

require_once __DIR__ . '/../../config/auth_check.php';

checkStaffAuth();
