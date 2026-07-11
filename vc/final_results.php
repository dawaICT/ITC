<?php

$page_title = 'Final Results';
include "student_nav.php";
session_start();

?>

<!DOCTYPE html>
<html>
	<head>
		<title>Final Results - ITC</title>
		<link rel="stylesheet" type="text/css" href="w3/w3.css">
		<link rel="stylesheet" type="text/css" href="css_main/student.css">
		<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
		<link rel="stylesheet" type="text/css" href="dist/css/bootstrap-theme.min.css">   
		<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.7.1/jquery.min.js"></script>
		<link rel="stylesheet" href="https://cdn.datatables.net/1.10.2/css/jquery.dataTables.min.css"></style>
		<script type="text/javascript" src="https://cdn.datatables.net/1.10.2/js/jquery.dataTables.min.js"></script>
		<script type="text/javascript" src="dist/js/bootstrap.min.js"></script>

	<body>
		<div class="container">
			<div class="row">
				<h4>Statement of results</h4>
				<hr>
		           			<table class="table table-hover align-middle">
		                        <thead class="table-light">
		                          <tr>
		                            <th>2021</th>
		                            <th>final exams</th>
		                            <th><a href="print_result_statement.php"><button class="w3-btn w3-blue w3-round-large"><span class="glyphicon glyphicon-open"></span> view</button></a></th>
		                          </tr>
		                          <tr>
		                            <th>2020</th>
		                            <th>semester exams</th>
		                            <th><a href="print_result_statement.php"><button class="w3-btn w3-blue w3-round-large"><span class="glyphicon glyphicon-open"></span> view</button></a></th>
		                          </tr>
		                        </thead>
		                        <tbody>
		                        </tbody>
		                      </table>
				<!-- placed at the end of the document so that the pages can load faster 
						============================================================================-->
					<script>

					</script>
					<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js">
					</script>
					<script src="dist/js/bootstrap.min.js"></script>
			</div>
		</div>
	</body>
</html>