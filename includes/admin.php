<?php

error_reporting(0);
// Absolute path: the old `require "../db/connect.php"` resolved against the
// ENTRY script's directory, so this chrome fataled (blank 500) whenever it was
// included from a root-level page like list_programs.php.
require dirname(__DIR__) . '/db/connect.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Legacy chrome had no auth check at all; these pages expose student data.
if (empty($_SESSION['user_id']) && empty($_SESSION['staff_id'])) {
    header('Location: /wucportal/staff_login.php');
    exit;
}

require_once __DIR__ . '/page_meta.php';
$wucDocTitle = wuc_portal_title(isset($page_title) ? (string)$page_title : '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo htmlspecialchars($wucDocTitle, ENT_QUOTES, 'UTF-8'); ?></title>
	<?php wuc_portal_favicon_links(); ?>
	<link rel="stylesheet" type="text/css" href="w3/w3.css">
	<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
	<link rel="stylesheet" type="text/css" href="dist/css/bootstrap-theme.min.css">
	<link rel="stylesheet" type="text/css" href="assets/css/font-awesome.css">
</head>

<body class="w3-light-grey">
	<div class="w3-container">
		<div class="w3-container w3-blue">
				<span class="w3-opennav w3-xlarge w3-hide-large w3-left" onclick="w3_open()">☰ Menu</span>

			<p class="w3-right">
				<strong><span class="glyphicon glyphicon-user"></span> User: <small><?php echo htmlspecialchars((string)($_SESSION['user_name'] ?? $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></small></strong>
				 <a href="index.php" class="btn btn-danger btn-xs"><span class="glyphicon glyphicon-log-out"></span> Logout</a>
			</p>
			</div>
				<div style="width:25%">
					<nav class="w3-sidenav w3-collapse w3-card-2 w3-light-grey w3-left">
						<a href="javascript:void(0)" onclick="w3_close()" 
  						class="w3-closenav w3-large w3-hide-large w3-right">Close X</a>
  						<h5 class="w3-center"><strong>Student Portal Information System</strong></h5>
						<ul class="w3-ul w3-hoverable"><br>
							<a href="dashboard_admin.php"><span class="glyphicon glyphicon-dashboard"></span> Dashboard</a>
							<!--<a href="departments.php"><span class="glyphicon glyphicon-th-large"></span> Departments</a>
							<a href="messages.php"><span class="glyphicon glyphicon-envelope"></span> Messages</a>-->
							<a href="students_by_admin.php"><span class="glyphicon glyphicon-user"></span> Students</a>
							<a href="staff.php"><span class="glyphicon glyphicon-gift"></span> Staff</a>
							<a href="programs.php"><span class="glyphicon glyphicon-th-list"></span> Programs</a>
							<a href="courses.php"><span class="glyphicon glyphicon-check"></span> Courses</a>
							<a href="semester.php"><span class="glyphicon glyphicon-list-alt"></span> Semester courses</a>
							<a href="assessments.php"><span class="glyphicon glyphicon-tasks"></span> Assessments</a>
							<a href="exams.php"><span class="glyphicon glyphicon-edit"></span> Exams</a>
							<a href="payments.php"><span class="glyphicon glyphicon-usd"></span> Payments</a>
							<a href="library.php"><span class="glyphicon glyphicon-book"></span> Library</a>
							<a href="hostels.php"><span class="glyphicon glyphicon-home"></span> Hostels</a>
							<a href="news_events.php"><span class="glyphicon glyphicon-calendar"></span> News & Events</a>
							<a href="#"><span class="glyphicon glyphicon-cog"></span> Admin controls</a>
						</ul>

					</nav>
				</div>
				<div style="margin-left:17%">
				</div>
	</div>
	<script>
	function w3_open() {
	    document.getElementsByClassName("w3-sidenav")[0].style.display = "block";
	}
	function w3_close() {
	    document.getElementsByClassName("w3-sidenav")[0].style.display = "none";
	}
	</script>
</body>

</html>
