<?php

$page_title = 'Student Dashboard';
include "student_nav.php";
session_start();

?>

<!DOCTYPE html>
<html>
	<head>
		<title>Student Dashboard - ITC</title>
		<link rel="stylesheet" type="text/css" href="w3/w3.css">
		<link rel="stylesheet" type="text/css" href="css_main/student.css">
		<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
		<link rel="stylesheet" type="text/css" href="dist/css/bootstrap-theme.min.css">

	<body>
		<div class="container">
			<div class="row">
					<h5><strong>Announcements and Notifications</strong></h5>

						<div class="login-panel panel panel-info w3-text-black">  
							<div class="panel-body">  
								<fieldset> 
								    <p><i>Posted by:.....  <span class="glyphicon glyphicon-calendar"></span>:.....</i></p>
								</fieldset> 
							</div>   
						</div><br>
						<h5><strong>Academic Events</strong></h5>
						<hr>  
				<!-- placed at the end of the document so that the pages can load faster 
						============================================================================-->
					<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js">
					</script>
					<script src="dist/js/bootstrap.min.js"></script>
			</div>
		</div>
	</body>
</html>