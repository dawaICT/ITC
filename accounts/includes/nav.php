<?php
// Accounts module navigation via unified include
$module_config = array(
	'role_label' => 'Accountant',
	'required_access' => 'finance',
	'footer_profile_href' => 'profile.php',
	'footer_logout_href' => '/wucportal/logout.php?to=staff',
	'additional_css' => array(
		'accounts/css/accounts-sidebar.css',
		'accounts/css/admin-dashboard.css',
	),
	'menu_sections' => array(
		array(
			'title' => 'Main',
			'items' => array(
				array('href' => 'index.php', 'icon' => 'fas fa-home', 'label' => 'Dashboard', 'active_on' => 'index.php'),
			),
		),
		array(
			'title' => 'Transactions',
			'items' => array(
				array('href' => 'fees_student_payments.php', 'icon' => 'fas fa-cash-register', 'label' => 'Process Payments', 'active_on' => 'fees_student_payments.php'),
				array('href' => 'pendingPayments.php', 'icon' => 'fas fa-university', 'label' => 'Review Bank Transfers', 'active_on' => array('pendingPayments.php', 'paymentProof.php')),
				array('href' => 'invoice_student.php', 'icon' => 'fas fa-file-invoice', 'label' => 'Invoice', 'active_on' => 'invoice_student.php'),
			),
		),
		array(
			'title' => 'Fees System',
			'items' => array(
				array('href' => 'fees_departments.php', 'icon' => 'fas fa-building', 'label' => 'Departments', 'active_on' => 'fees_departments.php'),
				array('href' => 'fees_courses.php', 'icon' => 'fas fa-graduation-cap', 'label' => 'Courses', 'active_on' => 'fees_courses.php'),
				array('href' => 'fees_training_modes.php', 'icon' => 'fas fa-book-open', 'label' => 'Training Modes', 'active_on' => 'fees_training_modes.php'),
				array('href' => 'fees_course_fees.php', 'icon' => 'fas fa-money-check-alt', 'label' => 'Base Course Fees', 'active_on' => 'fees_course_fees.php'),
				array('href' => 'fees_items.php', 'icon' => 'fas fa-tags', 'label' => 'Fee Items', 'active_on' => 'fees_items.php'),
				array('href' => 'fees_course_fee_breakdown.php', 'icon' => 'fas fa-chart-pie', 'label' => 'Fee Breakdowns', 'active_on' => 'fees_course_fee_breakdown.php'),
				array('href' => 'fees_student_accounts.php', 'icon' => 'fas fa-user-circle', 'label' => 'Student Accounts', 'active_on' => 'fees_student_accounts.php'),
			),
		),
		array(
			'title' => 'Reports',
			'items' => array(
				array('href' => 'payments_report.php', 'icon' => 'fas fa-credit-card', 'label' => 'Online Payments', 'active_on' => 'payments_report.php'),
				array('href' => 'bankTransaction.php', 'icon' => 'fas fa-receipt', 'label' => 'Bank Transactions', 'active_on' => 'bankTransaction.php'),
				array('href' => 'unpaidBalance.php', 'icon' => 'fas fa-user-graduate', 'label' => 'Student Payments', 'active_on' => 'unpaidBalance.php'),
				array('href' => 'receivables.php', 'icon' => 'fas fa-hand-holding-usd', 'label' => 'Total Receivables', 'active_on' => 'receivables.php'),
				array('href' => 'ageReceivables.php', 'icon' => 'fas fa-hourglass-half', 'label' => 'Receivables by Age', 'active_on' => 'ageReceivables.php'),
				array('href' => 'fees_reports.php', 'icon' => 'fas fa-chart-line', 'label' => 'Fee Reports', 'active_on' => 'fees_reports.php'),
			),
		),
	),
);

require dirname(__DIR__, 2) . '/includes/nav_unified.php';
