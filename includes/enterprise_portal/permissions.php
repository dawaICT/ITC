<?php
declare(strict_types=1);

if (!function_exists('ep_is_systems_admin')) {
    function ep_is_systems_admin(): bool
    {
        return function_exists('isSystemsAdmin') && isSystemsAdmin();
    }
}

if (!function_exists('ep_is_student_session')) {
    function ep_is_student_session(): bool
    {
        return !empty($_SESSION['Sid']) || (($_SESSION['user_role'] ?? '') === 'student');
    }
}

if (!function_exists('ep_staff_can')) {
    function ep_staff_can(mysqli $db, string $permissionKey): bool
    {
        if (ep_is_systems_admin()) {
            return true;
        }
        $userId = ep_current_user_id();
        if ($userId <= 0) {
            return false;
        }
        $sql = "SELECT 1
                FROM user_roles ur
                JOIN role_permissions rp ON rp.role_id = ur.role_id
                JOIN permissions p ON p.permission_id = rp.permission_id
                WHERE ur.user_id = ? AND p.permission_key = ?
                LIMIT 1";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('is', $userId, $permissionKey);
        $stmt->execute();
        $ok = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($ok) {
            return true;
        }
        // Role shortcuts for management when RBAC incomplete
        $mgmt = [
            'enterprise.memberships.manage', 'enterprise.approve', 'enterprise.publish',
            'enterprise.unpublish', 'enterprise.interests.manage', 'enterprise.leads.assign',
            'enterprise.outcomes.manage', 'enterprise.reports.view', 'enterprise.settings.manage',
            'enterprise.organizations.manage',
        ];
        if (in_array($permissionKey, $mgmt, true) && function_exists('hasAnyRole')) {
            return hasAnyRole([ROLE_SYSTEMS_ADMIN, ROLE_REGISTRAR, ROLE_HEAD_OF_DEPARTMENT, ROLE_DEAN]);
        }
        if (str_starts_with($permissionKey, 'agriculture.') && function_exists('hasAnyRole')) {
            return hasAnyRole([ROLE_SYSTEMS_ADMIN, ROLE_REGISTRAR, ROLE_HEAD_OF_DEPARTMENT, ROLE_DEAN]);
        }
        $review = [
            'enterprise.review.access', 'enterprise.review.request_changes',
            'enterprise.review.verify', 'enterprise.review.reject',
        ];
        if (in_array($permissionKey, $review, true) && function_exists('hasRole')) {
            return hasRole(ROLE_LECTURER) || hasRole(ROLE_HEAD_OF_DEPARTMENT);
        }
        return false;
    }
}

if (!function_exists('ep_can')) {
    function ep_can(mysqli $db, string $capability, ?array $membership = null): bool
    {
        if (ep_is_systems_admin()) {
            return true;
        }

        $memberCaps = [
            'enterprise.portal.join',
            'enterprise.portal.access',
            'enterprise.portal.withdraw',
            'enterprise.profile.manage_own',
            'enterprise.skills.manage_own',
            'enterprise.opportunity.create',
            'enterprise.opportunity.edit_own',
            'enterprise.opportunity.submit',
            'enterprise.opportunity.archive_own',
            'enterprise.interests.view_own',
        ];

        if (in_array($capability, $memberCaps, true) && ep_is_student_session()) {
            if ($capability === 'enterprise.portal.join') {
                return true;
            }
            $status = (string)($membership['status'] ?? '');
            if ($capability === 'enterprise.portal.access') {
                return $status === 'active';
            }
            if ($capability === 'enterprise.portal.withdraw') {
                return in_array($status, ['pending', 'active', 'changes_requested'], true);
            }
            return $status === 'active';
        }

        return ep_staff_can($db, $capability);
    }
}
