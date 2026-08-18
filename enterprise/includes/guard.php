<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth_helpers.php';
if (function_exists('ep_is_standalone_mode') && ep_is_standalone_mode()) {
    require_once dirname(__DIR__, 2) . '/network/includes/session.php';
    wuc_network_session_start();
} else {
    wuc_secure_session_start();
}
wuc_security_headers();

require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/includes/portal_access.php';
require_once dirname(__DIR__, 2) . '/includes/portal_context.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_portal/bootstrap.php';

if (empty($_SESSION['logged_in'])) {
    if (function_exists('ep_is_standalone_mode') && ep_is_standalone_mode()) {
        wuc_redirect('/wucportal/network/login.php');
    }
    $to = !empty($_SESSION['Sid']) ? 'student' : 'staff';
    wuc_redirect('/wucportal/' . ($to === 'student' ? 'student_login.php' : 'staff_login.php'));
}

if (function_exists('ep_is_standalone_mode') && ep_is_standalone_mode()) {
    if (function_exists('wuc_network_auth_realm') && wuc_network_auth_realm() !== 'network') {
        wuc_redirect('/wucportal/network/login.php');
    }
}

if (!ep_portal_enabled($db)) {
    $_SESSION['errorMessage'] = 'The Skills and Enterprise Portal is currently unavailable.';
    if (function_exists('ep_is_standalone_mode') && ep_is_standalone_mode()) {
        wuc_redirect('/wucportal/network/');
    }
    wuc_redirect('/wucportal/portal_selection.php');
}

wuc_set_portal_context('enterprise');

$userId = ep_current_user_id();
ep_ensure_organization_context($db, $userId);

$epMembership = ep_resolve_membership_context($db, $userId, ep_current_student_id());
$epMembershipStatus = ep_membership_effective_status($epMembership);

$epWorkspaceOrganizations = ep_list_accessible_organizations($db, $userId);
$epCurrentOrgId = ep_current_organization_id($db);
$epCurrentOrganization = $epCurrentOrgId ? ep_get_organization($db, $epCurrentOrgId) : null;
$epUserMemberships = ep_get_memberships_for_user($db, $userId, ep_current_student_id());

/** @var string $epGuardMode join|status|member|reviewer|management|agriculture */
$epGuardMode = $epGuardMode ?? 'member';

if ($epGuardMode === 'join' || $epGuardMode === 'status') {
    // Authenticated only
} elseif ($epGuardMode === 'reviewer') {
    if (!ep_can($db, 'enterprise.review.access')) {
        $_SESSION['errorMessage'] = 'You do not have permission to access enterprise reviews.';
        wuc_redirect('/wucportal/portal_selection.php');
    }
} elseif ($epGuardMode === 'management') {
    if (!ep_can($db, 'enterprise.memberships.manage') && !ep_can($db, 'enterprise.approve') && !ep_can($db, 'enterprise.reports.view')) {
        $_SESSION['errorMessage'] = 'You do not have permission to access enterprise management.';
        wuc_redirect('/wucportal/portal_selection.php');
    }
} elseif ($epGuardMode === 'agriculture') {
    $agriOk = ep_staff_can($db, 'agriculture.farmers.create')
        || ep_staff_can($db, 'agriculture.farmers.manage_assigned')
        || ep_staff_can($db, 'agriculture.prices.create')
        || ep_staff_can($db, 'agriculture.matches.review')
        || ep_staff_can($db, 'agriculture.reports.view')
        || ep_staff_can($db, 'agriculture.demands.review');
    if (!$agriOk) {
        $_SESSION['errorMessage'] = 'You do not have permission to access Agriculture and Market Access.';
        wuc_redirect('/wucportal/portal_selection.php');
    }
    if (function_exists('ep_agriculture_enabled') && !ep_agriculture_enabled($db)) {
        $_SESSION['errorMessage'] = 'Agriculture and Market Access is currently disabled.';
        wuc_redirect('/wucportal/portal_selection.php');
    }
} else {
    if ($epMembershipStatus === 'suspended') {
        $_SESSION['errorMessage'] = 'Your Skills and Enterprise Portal access is suspended.';
        wuc_redirect('/wucportal/enterprise/membership_status.php');
    }
    if ($epMembershipStatus !== 'active') {
        $_SESSION['errorMessage'] = 'Active membership is required to use the Skills and Enterprise Portal.';
        wuc_redirect('/wucportal/enterprise/membership_status.php');
    }
}

$epProfile = null;
if ($epMembership) {
    $epProfile = ep_get_profile_by_membership($db, (int)$epMembership['id']);
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = function_exists('wuc_csrf_token') ? wuc_csrf_token() : (string)$_SESSION['csrf_token'];

$flashSuccess = '';
$flashError = '';
if (!empty($_SESSION['flash_success'])) {
    $flashSuccess = (string)$_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $flashError = (string)$_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
if (!empty($_SESSION['errorMessage'])) {
    $flashError = (string)$_SESSION['errorMessage'];
    unset($_SESSION['errorMessage']);
}
