<?php
// Dean module navigation via unified include
$module_config = array(
	'role_label' => 'Dean',
	'required_access' => 'academics',
	// Ensure footer quick-actions point to valid pages in this module
	'footer_profile_href' => 'index.php',
	'footer_logout_href' => '/wucportal/logout.php?to=staff',
	// Only load dean-specific overrides; unified header provides the shared admin styles
	'additional_css' => array(
		'dean/css/dean-dashboard.css', // Dean-specific styling (overrides)
	),
	'menu_sections' => array(
		array(
			'title' => 'Main',
			'items' => array(
				array('href' => 'index.php', 'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard', 'active_on' => 'index.php'),
			),
		),
		array(
			'title' => 'Academic',
			'items' => array(
				array('href' => 'students.php', 'icon' => 'fas fa-user-graduate', 'label' => 'Students', 'active_on' => 'students.php'),
				array('href' => 'adminSlip.php', 'icon' => 'fas fa-file-alt', 'label' => 'Admin Slip', 'active_on' => 'adminSlip.php'),
			),
		),
		array(
			'title' => 'Reports',
			'items' => array(
				array('href' => 'reports.php', 'icon' => 'fas fa-chart-bar', 'label' => 'Reports & Analytics', 'active_on' => 'reports.php'),
			),
		),
	),
);

require dirname(__DIR__, 2) . '/includes/nav_unified.php';

