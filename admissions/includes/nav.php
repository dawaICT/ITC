<?php
// Admissions module navigation via unified include
require_once __DIR__ . '/session_handler.php';

// Check session timeout automatically for all pages using this nav
if (!checkSessionTimeout()) {
    setFlashMessage('error', 'Session expired due to inactivity.');
    header("Location: /wucportal/staff_login.php");
    exit();
}

$module_config = array(
	'role_label' => 'Admissions',
	'required_access' => 'admissions',
	'additional_css' => array(
		'admissions/css/admissions-modern.css',
		'admissions/css/module-reusable.css',
	),
	'brand_color_primary' => '#2E3190', // Admissions Blue
	'brand_color_secondary' => '#F9AD59', // WUC Orange
	'additional_scripts' => array(
		'admissions/js/modernScriptForm.js',
	),
	'menu_sections' => array(
		// 1. DASHBOARD
		array(
			'title' => 'Dashboard',
			'items' => array(
				array('href' => 'index.php', 'icon' => 'fas fa-home', 'label' => 'Dashboard', 'active_on' => 'index.php'),
			),
		),
		// 2. ADMISSIONS & APPLICATIONS (the application intake workflow + AI aids)
		array(
			'title' => 'Admissions & Applications',
			'items' => array(
				array('href' => 'applicants.php', 'icon' => 'fas fa-file-alt', 'label' => 'Online Applications', 'active_on' => 'applicants.php'),
				array('href' => 'processedApp.php', 'icon' => 'fas fa-clipboard-check', 'label' => 'Processed Applications', 'active_on' => 'processedApp.php'),
				array('href' => 'ai_applicant_assistant.php', 'icon' => 'fas fa-robot', 'label' => 'AI Applicant Assistant', 'active_on' => 'ai_applicant_assistant.php'),
				array('href' => 'ai_letter_drafter.php', 'icon' => 'fas fa-envelope-open-text', 'label' => 'AI Letter Drafter', 'active_on' => 'ai_letter_drafter.php'),
			),
		),
		// 3. STUDENT REGISTRATION & RECORDS
		array(
			'title' => 'Student Registration & Records',
			'items' => array(
				array('href' => 'regNewStud.php', 'icon' => 'fas fa-user-graduate', 'label' => 'Register New Student', 'active_on' => 'regNewStud.php'),
				array('href' => 'regOldStud.php', 'icon' => 'fas fa-exchange-alt', 'label' => 'Transfer Students', 'active_on' => 'regOldStud.php'),
				array('href' => 'manage_admitted_students.php', 'icon' => 'fas fa-user-cog', 'label' => 'Manage Admitted', 'active_on' => 'manage_admitted_students.php'),
				array('href' => 'recent_students.php', 'icon' => 'fas fa-user-clock', 'label' => 'Recently Admitted', 'active_on' => 'recent_students.php'),
				array('href' => 'students.php', 'icon' => 'fas fa-users', 'label' => 'All Students', 'active_on' => 'students.php'),
				array('href' => 'search_student.php', 'icon' => 'fas fa-search', 'label' => 'Search Students', 'active_on' => 'search_student.php'),
			),
		),
		// 4. ACADEMIC MANAGEMENT
		array(
			'title' => 'Academic Management',
			'items' => array(
				array('href' => 'semester.php', 'icon' => 'fas fa-layer-group', 'label' => 'Program Courses', 'active_on' => 'semester.php'),
			),
		),
		// 5. SHORT COURSES & VOCATIONAL (short-course + City & Guilds enrolment)
		array(
			'title' => 'Short Courses & Vocational',
			'items' => array(
				array('href' => 'short_courses.php', 'icon' => 'fas fa-certificate', 'label' => 'Short Course Registration', 'active_on' => 'short_courses.php'),
				// Transport trainees are registered through "Register New Student"
				// (transport programmes now appear in the registration dropdown).
				array('href' => '/wucportal/admissions/city_guilds_enrolment.php', 'icon' => 'fas fa-award', 'label' => 'City & Guilds Enrolment', 'active_on' => 'city_guilds_enrolment.php'),
			),
		),
		// 6. REPORTS
		array(
			'title' => 'Reports',
			'items' => array(
				array('href' => 'reports.php', 'icon' => 'fas fa-chart-bar', 'label' => 'Reports', 'active_on' => 'reports.php'),
				array('href' => 'training_reports.php', 'icon' => 'fas fa-filter', 'label' => 'Recruitment & Training', 'active_on' => 'training_reports.php'),
			),
		),
	),
	'footer_profile_href' => 'edit_profile.php',
);

require dirname(__DIR__, 2) . '/includes/nav_unified.php';
