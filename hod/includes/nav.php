<?php
require_once dirname(__DIR__, 2) . '/includes/session_guard.php';
wuc_enforce_session_guard([
	'context' => 'head-of-section',
	'session_keys' => ['staff_id', 'user_id'],
	'activity_keys' => ['last_activity', 'last_active_time'],
	'timeout' => 1800,
	'post_grace' => 30,
	'login_path' => '/wucportal/staff_login.php',
	'flash_key' => 'errorMessage',
	'timeout_message' => 'Your session has expired. Please log in again.',
	'login_message' => 'Please log in to access the Head of Section Portal.',
]);

require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/includes/role_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/portal_access.php';

wuc_require_portal_access($db, 'academic', 'You do not have permission to access Head of Section pages from the current portal.');

if (!hasRole(ROLE_HEAD_OF_DEPARTMENT) && !isSystemsAdmin()) {
    $_SESSION['errorMessage'] = 'Access denied. You do not have permission to access the Head of Section Portal.';
    wuc_safe_redirect('/wucportal/portal_selection.php');
}

require_once dirname(__DIR__, 2) . '/includes/hos_section_helpers.php';

$hosSections = [];
$activeHosSectionName = '';
$activeHosSectionId = '';
$activeHosSectionType = '';
$hodNavStaffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
if ($hodNavStaffId !== '' && isset($db) && $db instanceof mysqli) {
	$hosSections = hos_hydrate_section_session($db, $hodNavStaffId);
	hos_enforce_section_url_policy($db, $hodNavStaffId);
	$activeHosSectionName = (string)($_SESSION['hos_section_name'] ?? '');
	$activeHosSectionId = (string)($_SESSION['hos_section_id'] ?? '');
	$activeHosSectionType = (string)($_SESSION['hos_section_type'] ?? '');
}

$canSwitchHosSections = $hodNavStaffId !== ''
	&& isset($db)
	&& $db instanceof mysqli
	&& function_exists('hos_can_switch_sections')
	&& hos_can_switch_sections($db, $hodNavStaffId);

if (hos_active_session_role() === 'head_of_department') {
	$currentHodPage = basename((string)($_SERVER['PHP_SELF'] ?? ''));
	if ($currentHodPage !== 'index.php') {
		hos_require_assigned_section($db, $hodNavStaffId, '/wucportal/hod/index.php');
	}

	// Only lock (suppress workspace switcher + force single role) for *dedicated*
	// HOS accounts. ITC900-style accounts that also carry 'systems_admin' should
	// keep the ability to switch contexts even while viewing under the HOD role.
	$origAll = $_SESSION['all_roles'] ?? [];
	$isPrivileged = in_array('systems_admin', (array)$origAll, true) || hos_can_switch_sections($db, $hodNavStaffId);
	if (!$isPrivileged) {
		$_SESSION['all_roles'] = ['head_of_department'];
		$_SESSION['all_roles_raw'] = ['Head of Section'];
	}
}

// Section switcher — only for systems administrators who manage every section.
// Dedicated HOS accounts are permanently scoped to one section from the database.
$sectionMenu = [];
if ($canSwitchHosSections && count($hosSections) > 1) {
	$sectionItems = [];
	foreach ($hosSections as $section) {
		$sectionId = (string)($section['section_id'] ?? '');
		$sectionName = (string)($section['section_name'] ?? '');
		if ($sectionId === '' || $sectionName === '') {
			continue;
		}
		$isActiveSection = $sectionId === $activeHosSectionId;
		$sectionItems[] = [
			'href' => 'index.php?section=' . rawurlencode($sectionId),
			'icon' => $isActiveSection ? 'fas fa-circle-check' : 'far fa-circle',
			'label' => $sectionName,
			// Highlight the section the session is currently scoped to. The
			// switcher only highlights on the dashboard itself; the icon makes
			// the active section obvious everywhere else.
			'active_on' => $isActiveSection ? 'index.php' : '__hos_section_switch__',
		];
	}
	if (!empty($sectionItems)) {
		$sectionMenu[] = [
			'title' => 'My Sections',
			'items' => $sectionItems,
		];
	}
}

$isTransportHosSection = function_exists('hos_is_transport_section') && hos_is_transport_section([
	'section_id' => $activeHosSectionId,
	'section_name' => $activeHosSectionName,
	'section_type' => $activeHosSectionType,
]);
$isNonAcademicHosSection = $isTransportHosSection || ($activeHosSectionType !== '' && $activeHosSectionType !== 'academic');
$baseMenuSections = $isNonAcademicHosSection ? array(
	array(
		'title' => 'Main',
		'items' => array(
			array('href' => 'index.php', 'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard', 'active_on' => 'index.php'),
		),
	),
	array(
		'title' => $isTransportHosSection ? 'Transport Operations' : 'Operations',
		'items' => $isTransportHosSection ? array(
			array('href' => '/wucportal/transport.php', 'icon' => 'fas fa-route', 'label' => 'Transport Hub', 'active_on' => 'transport.php'),
			array('href' => '/wucportal/transport/cohorts.php', 'icon' => 'fas fa-users-rectangle', 'label' => 'Cohorts & Trainees', 'active_on' => 'cohorts.php'),
			array('href' => '/wucportal/transport/sessions.php', 'icon' => 'fas fa-calendar-check', 'label' => 'Training Sessions', 'active_on' => 'sessions.php'),
			array('href' => '/wucportal/transport/fleet.php', 'icon' => 'fas fa-truck', 'label' => 'Fleet', 'active_on' => 'fleet.php'),
			array('href' => '/wucportal/transport/instructors.php', 'icon' => 'fas fa-id-card', 'label' => 'Instructors', 'active_on' => 'instructors.php'),
			array('href' => '/wucportal/transport/preuse_checks.php', 'icon' => 'fas fa-clipboard-check', 'label' => 'Compliance Checks', 'active_on' => 'preuse_checks.php'),
			array('href' => '/wucportal/transport/clients.php', 'icon' => 'fas fa-building', 'label' => 'Corporate Clients', 'active_on' => 'clients.php'),
			array('href' => '/wucportal/transport/reports.php', 'icon' => 'fas fa-chart-line', 'label' => 'Reports & Summaries', 'active_on' => 'reports.php'),
			array('href' => 'academic_reports.php', 'icon' => 'fas fa-chart-pie', 'label' => 'Academic Reports', 'active_on' => 'academic_reports.php'),
			array('href' => 'decision_support.php', 'icon' => 'fas fa-compass', 'label' => 'Decision Support', 'active_on' => 'decision_support.php'),
		) : array(
			array('href' => 'staff.php', 'icon' => 'fas fa-user-tie', 'label' => 'Staff Coordination', 'active_on' => 'staff.php'),
			array('href' => 'calendar.php', 'icon' => 'fas fa-calendar-alt', 'label' => 'Calendar', 'active_on' => 'calendar.php'),
			array('href' => 'reports.php', 'icon' => 'fas fa-chart-bar', 'label' => 'Operational Reports', 'active_on' => 'reports.php'),
			array('href' => 'academic_reports.php', 'icon' => 'fas fa-chart-pie', 'label' => 'Academic Reports', 'active_on' => 'academic_reports.php'),
		),
	),
) : array(
	array(
		'title' => 'Main',
		'items' => array(
			array('href' => 'index.php', 'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard', 'active_on' => 'index.php'),
		),
	),
	array(
		'title' => 'Department',
		'items' => array(
			array('href' => 'students.php', 'icon' => 'fas fa-user-graduate', 'label' => 'Students', 'active_on' => 'students.php'),
			array('href' => 'staff.php', 'icon' => 'fas fa-user-tie', 'label' => 'Staff', 'active_on' => 'staff.php'),
			array('href' => 'courses.php', 'icon' => 'fas fa-book', 'label' => 'Courses', 'active_on' => 'courses.php'),
		),
	),
	array(
		'title' => 'Assessment',
		'items' => array(
			array('href' => 'CAmanager.php', 'icon' => 'fas fa-tasks', 'label' => 'CA Manager', 'active_on' => 'CAmanager.php'),
			array('href' => 'approvedCA.php', 'icon' => 'fas fa-check-circle', 'label' => 'Approved CAs', 'active_on' => 'approvedCA.php'),
			array('href' => 'hod_dashboard.php', 'icon' => 'fas fa-clipboard-check', 'label' => 'Exam Approvals', 'active_on' => 'hod_dashboard.php'),
			array('href' => 'adminSlip.php', 'icon' => 'fas fa-file-alt', 'label' => 'Admin Slip', 'active_on' => 'adminSlip.php'),
		),
	),
	array(
		'title' => 'Management',
		'items' => array(
			array('href' => 'finance.php', 'icon' => 'fas fa-money-bill-wave', 'label' => 'Finance', 'active_on' => 'finance.php'),
			array('href' => 'decision_support.php', 'icon' => 'fas fa-compass', 'label' => 'Decision Support', 'active_on' => 'decision_support.php'),
			array('href' => 'reports.php', 'icon' => 'fas fa-chart-bar', 'label' => 'Reports', 'active_on' => 'reports.php'),
			array('href' => 'academic_reports.php', 'icon' => 'fas fa-chart-pie', 'label' => 'Academic Reports', 'active_on' => 'academic_reports.php'),
			array('href' => 'online_class_reports.php', 'icon' => 'fas fa-video', 'label' => 'Online Classes', 'active_on' => 'online_class_reports.php'),
			array('href' => 'calendar.php', 'icon' => 'fas fa-calendar-alt', 'label' => 'Calendar', 'active_on' => 'calendar.php'),
			array('href' => 'timetable_settings.php', 'icon' => 'fas fa-clock', 'label' => 'Timetable Settings', 'active_on' => 'timetable_settings.php'),
			array('href' => 'teaching_planner.php', 'icon' => 'fas fa-file-signature', 'label' => 'Teaching Planner', 'active_on' => 'teaching_planner.php'),
			array('href' => 'quality_assurance.php', 'icon' => 'fas fa-award', 'label' => 'QA Reports', 'active_on' => 'quality_assurance.php'),
		),
	),
);

// HOS module navigation via unified include
$module_config = array(
	'role_label' => trim('Head of Section' . ($activeHosSectionName !== '' ? ' - ' . $activeHosSectionName : '')),
	'required_access' => 'hod',
	'footer_profile_href' => 'view_staff.php?view=' . urlencode($_SESSION['staff_id'] ?? ''),
	'footer_logout_href' => '/wucportal/logout.php?to=staff',
	'additional_css' => array(
		'admin/css/admin-sidebar.css',
		'admin/css/sidebar-typography.css',
		'hod/css/hod-dashboard.css',
	),
	'brand_color_primary' => '#1B2A4A', // ITC navy (matches unified sidebar)
	'brand_color_secondary' => '#0B1530', // Deep navy (card-header gradient)
	'additional_scripts' => array(
		'admin/js/sidebar-toggle.js',
	),
	'menu_sections' => array_merge($sectionMenu, $baseMenuSections),
);

require dirname(__DIR__, 2) . '/includes/nav_unified.php';
