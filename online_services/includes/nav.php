<?php
require "../db/connect.php";
require_once dirname(__DIR__, 2) . '/includes/audit.php';
error_reporting(0);

// Automatic page view audit for Online Services module
if (function_exists('audit_log_page_view') && isset($db) && $db instanceof mysqli) {
    audit_log_page_view($db);
}
?>
<!DOCTYPE html>
<html>
<title>student.portal</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
		<meta charset="UTF-8">
		<link rel="stylesheet" type="text/css" href="w3/w3.css">
		<link rel="stylesheet" type="text/css" href="css_main/style.css">
		<link rel="stylesheet" type="text/css" href="css_main/admin.css">
		<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
		<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">
		<link rel="stylesheet" type="text/css" href="assets/css/font-awesome.css">

		<!-- Admin theme styles -->
		<link rel="stylesheet" href="/wucportal/css/admin-style.css">
		<link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

		<style>
		/* Align legacy topbar to admin theme */
		.w3-navbar, .w3-navbar.w3-purple, .w3-navbar.bg-primary { background: var(--sidebar-bg) !important; color: var(--sidebar-text) !important; }
		.w3-navbar a, .w3-navbar .w3-dropdown-hover > a, .w3-navbar .w3-right a { color: var(--sidebar-text) !important; }
		.w3-navbar a:hover, .w3-navbar .w3-dropdown-hover:hover > a { background: var(--sidebar-hover) !important; color: var(--sidebar-text) !important; }
		.w3-dropdown-content { background: var(--sidebar-bg) !important; color: var(--sidebar-text) !important; border: 1px solid var(--sidebar-border); }
		.w3-dropdown-content a { color: var(--sidebar-text) !important; }
		.w3-dropdown-content a:hover { background: var(--sidebar-hover) !important; }
		</style>

<body>
	<ul class="w3-navbar w3-large w3-left-align dark w3-purple w3-card-4">
	  <li class="w3-hide-medium w3-hide-large w3-black w3-opennav w3-right">
	    <a href="javascript:void(0);" onclick="myFunction()">Menu☰</a>
	  </li>
	  <li><a href="#"><span class="glyphicon glyphicon-globe"></span> Website</a></li>
	  <li class="w3-hide-small"><a href="../"><span class="glyphicon glyphicon-home"></span> Home</a></li>
	  <li class="w3-dropdown-hover w3-hide-small">
	    <a href="#"><span class="glyphicon glyphicon-download-alt"></span> Downloads<i class="fas fa-caret-down"></i></a>
	    <div class="w3-dropdown-content w3-card-4 w3-purple">
	      <a href="uploads/WUC APPLICATION FORM NEW.pdf" target="_blank">Application form</a>
	      <a href="#" target="_blank">Program fees</a>
	    </div>
	  </li>
	  <li class="w3-hide-small"><a href="index.php"><span class="glyphicon glyphicon-edit"></span> Apply online</a></li>
	  <li class="w3-hide-small"><a href="trackApp.php"><span class="glyphicon glyphicon-random"></span> Track application</a></li>
	</ul>

	<div id="demo" class="w3-hide w3-hide-large w3-hide-medium">
	  <ul class="w3-navbar w3-left-align w3-large w3-purple w3-card-4">
	    <li><a href="../"><span class="glyphicon glyphicon-home"></span> Home</a></li>
		<li class="w3-dropdown-hover">
	    <a href="#"><span class="glyphicon glyphicon-download-alt"></span> Downloads<i class="fas fa-caret-down"></i></a>
	    <div class="w3-dropdown-content w3-card-4 w3-purple">
	      <a href="uploads/WUC APPLICATION FORM NEW.pdf" target="_blank">Application form</a>
	      <a href="#">Program fees</a>
	    </div>
	  </li>
	    <li><a href="online_services/"><span class="glyphicon glyphicon-edit"></span> Apply online</a></li>
	    <li><a href="fees.php"><span class="glyphicon glyphicon-random"></span> Track application</a></li>
	  </ul>
	</div>
	<script>
	function myFunction() {
	    document.getElementById("demo").classList.toggle("w3-show");
	}
	</script>
</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>

