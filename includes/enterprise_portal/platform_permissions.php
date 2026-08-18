<?php
declare(strict_types=1);

if (!function_exists('ep_is_platform_admin')) {
    function ep_is_platform_admin(mysqli $db): bool
    {
        if (function_exists('ep_is_systems_admin') && ep_is_systems_admin()) {
            return true;
        }
        return function_exists('ep_staff_can') && ep_staff_can($db, 'platform.admin');
    }
}

if (!function_exists('ep_is_network_support')) {
    function ep_is_network_support(mysqli $db): bool
    {
        return ep_is_platform_admin($db)
            || (function_exists('ep_staff_can') && ep_staff_can($db, 'platform.support'));
    }
}

if (!function_exists('ep_can_access_network_app')) {
    /** Participant / org officer access to the signed-in network app (not public). */
    function ep_can_access_network_app(mysqli $db): bool
    {
        if (!function_exists('wuc_network_is_authenticated') || !wuc_network_is_authenticated()) {
            return false;
        }
        if (ep_is_network_support($db)) {
            return true;
        }
        if (function_exists('ep_staff_can') && ep_staff_can($db, 'network.member.access')) {
            return true;
        }
        $uid = function_exists('ep_current_user_id') ? ep_current_user_id() : (int)($_SESSION['user_id'] ?? 0);
        if ($uid > 0 && function_exists('ep_get_membership_for_user')) {
            $m = ep_get_membership_for_user($db, $uid, null);
            if ($m && ($m['status'] ?? '') === 'active') {
                return true;
            }
        }
        if ($uid > 0 && function_exists('ep_user_organization_ids') && ep_user_organization_ids($db, $uid) !== []) {
            return true;
        }
        return false;
    }
}
