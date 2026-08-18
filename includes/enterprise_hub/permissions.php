<?php
declare(strict_types=1);

/**
 * Enterprise Hub authorization.
 *
 * Capability keys follow the specification (enterprise.*). Students are
 * granted own-record capabilities by authenticated student session; staff
 * capabilities resolve through RBAC with systems_admin override.
 */

if (!function_exists('eh_capability_map')) {
    /** @return array<string, list<string>> capability => permission keys */
    function eh_capability_map(): array
    {
        return [
            'enterprise.profile.manage_own' => ['enterprise.profile.manage_own', 'enterprise_hub.manage'],
            'enterprise.item.create' => ['enterprise.item.create', 'enterprise_hub.manage'],
            'enterprise.item.edit_own' => ['enterprise.item.edit_own', 'enterprise_hub.manage'],
            'enterprise.item.submit' => ['enterprise.item.submit', 'enterprise_hub.manage'],
            'enterprise.item.view_own_interests' => ['enterprise.item.view_own_interests', 'enterprise_hub.manage'],
            'enterprise.review.lecturer' => ['enterprise.review.lecturer', 'enterprise_hub.manage'],
            'enterprise.review.request_changes' => ['enterprise.review.request_changes', 'enterprise_hub.manage'],
            'enterprise.review.verify' => ['enterprise.review.verify', 'enterprise_hub.manage'],
            'enterprise.review.reject' => ['enterprise.review.reject', 'enterprise_hub.manage'],
            'enterprise.approve' => ['enterprise.approve', 'enterprise_hub.manage'],
            'enterprise.publish' => ['enterprise.publish', 'enterprise_hub.manage'],
            'enterprise.unpublish' => ['enterprise.unpublish', 'enterprise_hub.manage'],
            'enterprise.interests.manage' => ['enterprise.interests.manage', 'enterprise_hub.manage'],
            'enterprise.reports.view' => ['enterprise.reports.view', 'enterprise_hub.manage'],
            'enterprise.settings.manage' => ['enterprise.settings.manage', 'enterprise_hub.manage'],
        ];
    }
}

if (!function_exists('eh_is_student_session')) {
    function eh_is_student_session(): bool
    {
        return !empty($_SESSION['Sid']) || (($_SESSION['user_role'] ?? '') === 'student');
    }
}

if (!function_exists('eh_is_systems_admin')) {
    function eh_is_systems_admin(): bool
    {
        return function_exists('isSystemsAdmin') && isSystemsAdmin();
    }
}

if (!function_exists('eh_staff_has_permission_key')) {
    function eh_staff_has_permission_key(mysqli $db, string $permissionKey): bool
    {
        if (eh_is_systems_admin()) {
            return true;
        }
        $userIdDb = (int)($_SESSION['user_id_db'] ?? 0);
        if ($userIdDb <= 0) {
            return false;
        }
        if (str_contains($permissionKey, '.')) {
            $parts = explode('.', $permissionKey, 2);
            if (count($parts) === 2 && function_exists('wuc_has_permission_unified')) {
                // Try module.action style first for enterprise_hub.*
                if ($parts[0] === 'enterprise_hub') {
                    return wuc_has_permission_unified($db, $userIdDb, 'enterprise_hub', $parts[1]);
                }
            }
        }

        // Direct permission_key lookup via role_permissions
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
        $stmt->bind_param('is', $userIdDb, $permissionKey);
        $stmt->execute();
        $ok = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($ok) {
            return true;
        }

        // Fallback: enterprise_hub.manage
        if ($permissionKey !== 'enterprise_hub.manage' && function_exists('wuc_has_permission_unified')) {
            return wuc_has_permission_unified($db, $userIdDb, 'enterprise_hub', 'manage');
        }
        return false;
    }
}

if (!function_exists('eh_can')) {
    function eh_can(mysqli $db, string $capability): bool
    {
        $studentCaps = [
            'enterprise.profile.manage_own',
            'enterprise.item.create',
            'enterprise.item.edit_own',
            'enterprise.item.submit',
            'enterprise.item.view_own_interests',
        ];

        if (in_array($capability, $studentCaps, true) && eh_is_student_session()) {
            return true;
        }

        if (eh_is_systems_admin()) {
            return true;
        }

        // Role-based shortcuts for lecturers / admins when RBAC seed is incomplete
        if (in_array($capability, [
            'enterprise.review.lecturer',
            'enterprise.review.request_changes',
            'enterprise.review.verify',
            'enterprise.review.reject',
        ], true)) {
            if (function_exists('hasRole') && (hasRole(ROLE_LECTURER) || hasRole(ROLE_HEAD_OF_DEPARTMENT))) {
                return true;
            }
        }

        if (in_array($capability, [
            'enterprise.approve',
            'enterprise.publish',
            'enterprise.unpublish',
            'enterprise.interests.manage',
            'enterprise.reports.view',
            'enterprise.settings.manage',
        ], true)) {
            if (function_exists('isAdmin') && isAdmin()) {
                return true;
            }
            if (function_exists('hasAnyRole') && hasAnyRole([ROLE_SYSTEMS_ADMIN, ROLE_REGISTRAR, ROLE_HEAD_OF_DEPARTMENT, ROLE_DEAN])) {
                return true;
            }
        }

        $map = eh_capability_map()[$capability] ?? [$capability];
        foreach ($map as $key) {
            if (eh_staff_has_permission_key($db, $key)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('eh_require')) {
    function eh_require(mysqli $db, string $capability, string $redirect = ''): void
    {
        if (eh_can($db, $capability)) {
            return;
        }
        if (function_exists('wuc_permission_denied')) {
            wuc_permission_denied('You do not have permission for this Skills-to-Trade Hub action.', $redirect);
        }
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }
}

if (!function_exists('eh_assert_owns_profile')) {
    function eh_assert_owns_profile(array $profile): void
    {
        $ownerId = (int)($profile['owner_user_id'] ?? 0);
        $studentId = (string)($profile['student_id'] ?? '');
        $currentOwner = eh_current_owner_user_id();
        $currentSid = eh_current_student_id() ?? '';

        $ok = ($currentOwner > 0 && $ownerId === $currentOwner)
            || ($currentSid !== '' && $studentId !== '' && hash_equals($studentId, $currentSid));

        if (!$ok && !eh_is_systems_admin()) {
            http_response_code(403);
            throw new RuntimeException('You can only access your own enterprise profile.');
        }
    }
}

if (!function_exists('eh_assert_owns_item')) {
    function eh_assert_owns_item(mysqli $db, array $item): void
    {
        $profileId = (int)($item['enterprise_profile_id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM enterprise_profiles WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $profileId);
        $stmt->execute();
        $profile = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$profile) {
            throw new RuntimeException('Enterprise profile not found.');
        }
        eh_assert_owns_profile($profile);
    }
}
