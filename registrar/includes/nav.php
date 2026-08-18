<?php
// Registrar module navigation via unified include
$module_config = array(
	'role_label' => 'Registrar',
	'required_access' => 'registrar',
	'menu_sections' => array(
		array(
			'title' => 'Dashboard',
			'items' => array(
				array('href' => 'index.php', 'icon' => 'fas fa-home', 'label' => 'Dashboard', 'active_on' => 'index.php'),
			),
		),
		array(
			'title' => 'Student Management',
			'items' => array(
				array('href' => 'search_student.php', 'icon' => 'fas fa-search', 'label' => 'Search Students', 'active_on' => 'search_student.php'),
			),
		),
		array(
			'title' => 'Academic',
			'items' => array(
				array('href' => 'upload_ca.php', 'icon' => 'fas fa-upload', 'label' => 'Upload CA', 'active_on' => 'upload_ca.php'),
				array('href' => 'upload_exam_results.php', 'icon' => 'fas fa-file-upload', 'label' => 'Upload Exam Results', 'active_on' => 'upload_exam_results.php'),
				array('href' => 'teaching_planner.php', 'icon' => 'fas fa-file-signature', 'label' => 'Teaching Plan Monitor', 'active_on' => 'teaching_planner.php'),
				array('href' => 'test_timetable.php', 'icon' => 'fas fa-calendar-check', 'label' => 'Test Timetable', 'active_on' => 'test_timetable.php'),
			),
		),
		array(
			'title' => 'Reports',
			'items' => array(
				array('href' => 'academic_reports.php', 'icon' => 'fas fa-chart-pie', 'label' => 'Academic Reports', 'active_on' => 'academic_reports.php'),
				array('href' => 'risk_watchlist.php', 'icon' => 'fas fa-heart-pulse', 'label' => 'Risk Watchlist', 'active_on' => 'risk_watchlist.php'),
			),
		),
	),
);

require dirname(__DIR__, 2) . '/includes/nav_unified.php';

?>


