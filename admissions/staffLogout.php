<?php

	require_once __DIR__ . '/includes/session_handler.php';

	$user_name = getSafeSessionValue('user_name', 'Unknown');
	logActivity($user_name, 'LOGOUT', 'User logged out');

	logoutUser();
	header('Location: /wucportal/staff_login.php');

?>