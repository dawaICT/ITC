<?php
declare(strict_types=1);

require_once __DIR__ . '/portal_access.php';
require_once __DIR__ . '/portal_context.php';

if (!function_exists('wuc_portal_switch_user_kind')) {
    function wuc_portal_switch_user_kind(): string
    {
        if (!empty($_SESSION['Sid']) || ($_SESSION['user_role'] ?? '') === 'student') {
            return 'student';
        }
        if ((string)($_SESSION['role'] ?? '') === 'lecturer'
            || (defined('ROLE_LECTURER') && function_exists('hasRole') && hasRole(ROLE_LECTURER))) {
            return 'lecturer';
        }
        return 'staff';
    }
}

if (!function_exists('wuc_portal_switch_context')) {
    function wuc_portal_switch_context(string $targetPortal, ?string $userKind = null): string
    {
        $targetPortal = strtolower(trim($targetPortal));
        $userKind = strtolower(trim((string)($userKind ?? wuc_portal_switch_user_kind())));

        if ($targetPortal === 'elearning') {
            if ($userKind === 'student') {
                return 'student_elearning';
            }
            if ($userKind === 'lecturer') {
                return 'lecturer_elearning';
            }
            return 'staff_elearning';
        }

        if ($userKind === 'student') {
            return 'student_academic';
        }
        if ($userKind === 'lecturer') {
            return 'lecturer_academic';
        }
        return 'staff_academic';
    }
}

if (!function_exists('wuc_portal_switch_landing_url')) {
    function wuc_portal_switch_landing_url(string $context): string
    {
        $context = strtolower(trim($context));
        $map = [
            'student_academic' => '/wucportal/students/index.php',
            'student_elearning' => '/wucportal/students/elearning/index.php',
            'lecturer_academic' => '/wucportal/lecturers/index.php',
            'lecturer_elearning' => '/wucportal/lecturers/elearning/index.php',
            'staff_elearning' => '/wucportal/staff/elearning/index.php',
        ];
        if (isset($map[$context])) {
            return $map[$context];
        }

        // staff_academic: the generic staff dashboard hub was removed. Staff land
        // directly on the module dashboard their role guard accepts; multi-role
        // accounts go to the workspace chooser. Throwing (instead of falling back
        // to portal_selection.php) is what prevents a redirect loop, because
        // portal_selection.php resolves its landing through this function.
        require_once __DIR__ . '/helpers/redirect_helper.php';
        $allRoles = array_values(array_unique(array_filter((array)($_SESSION['all_roles'] ?? []))));
        if (count($allRoles) > 1) {
            return '/wucportal/role_selection.php';
        }

        $landing = wuc_staff_landing_url((string)($_SESSION['role'] ?? ($allRoles[0] ?? '')));
        if (strpos($landing, 'portal_selection.php') !== false) {
            throw new DomainException('Your account has no staff workspace assigned. Please contact the system administrator.');
        }
        return $landing;
    }
}

if (!function_exists('wuc_portal_switch_url')) {
    function wuc_portal_switch_url(string $targetPortal, ?string $userKind = null): string
    {
        $query = ['portal' => strtolower(trim($targetPortal))];
        if ($userKind !== null && trim($userKind) !== '') {
            $query['as'] = strtolower(trim($userKind));
        }
        return '/wucportal/portal_switch.php?' . http_build_query($query);
    }
}

if (!function_exists('wuc_apply_portal_switch')) {
    function wuc_apply_portal_switch(mysqli $db, string $targetPortal, ?string $userKind = null): string
    {
        $targetPortal = strtolower(trim($targetPortal));

        $directLanding = wuc_portal_direct_landing_url($targetPortal);
        if ($directLanding !== null) {
            wuc_require_portal_access($db, $targetPortal);

            return $directLanding;
        }

        if (!in_array($targetPortal, ['academic', 'elearning'], true)) {
            throw new DomainException('The selected portal is not available yet. Please contact the system administrator.');
        }

        $context = wuc_portal_switch_context($targetPortal, $userKind);
        wuc_require_portal_access($db, $targetPortal);
        wuc_set_portal_context($context);

        if ($context === 'student_academic' && !empty($_SESSION['Sid'])) {
            require_once __DIR__ . '/student_program_portal.php';
            return wuc_student_program_portal_url($db, (string)$_SESSION['Sid']);
        }

        return wuc_portal_switch_landing_url($context);
    }
}
