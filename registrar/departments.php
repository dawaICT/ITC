<?php
$page_title = 'Departments';
include "includes/admin.php";
include 'add_depart.php';
error_reporting(0);

?>
<!DOCTYPE html>
<html>
<title>Departments - ITC</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
		<meta charset="UTF-8">
		<link rel="stylesheet" type="text/css" href="w3/w3.css">
		<link rel="stylesheet" type="text/css" href="css_main/admin.css">
		<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
		<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">

<body>
	<div class="w3-container">
		<div class="row">
			<div class="col-sm-2"></div>
			<div class="col-sm-9 w3-card-4 w3-animate-right">
				<div class="w3-container">
					<h3>Departments</h3>
					<button class="w3-btn w3-round w3-green w3-right" onclick="document.getElementById('dept').style.display='block'">
					<span class="glyphicon glyphicon-plus"></span> Add department</button><br>
					<hr>

					<table class="table table-hover align-middle">
						<thead class="table-light">
							<tr>
								<th>Department ID</th>
								<th>Department Name</th>
								<th>Faculty</th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody>
							<?php
							$deptResult = mysqli_query($db, "SELECT department_id, department_name, faculty, status FROM departments ORDER BY department_name");
							if ($deptResult && mysqli_num_rows($deptResult) > 0) {
								while ($deptRow = mysqli_fetch_assoc($deptResult)) {
									echo "<tr>";
									echo "<td>" . htmlspecialchars($deptRow['department_id']) . "</td>";
									echo "<td>" . htmlspecialchars($deptRow['department_name']) . "</td>";
									echo "<td>" . htmlspecialchars($deptRow['faculty'] ?? '') . "</td>";
									echo "<td>" . htmlspecialchars($deptRow['status']) . "</td>";
									echo "</tr>";
								}
							} else {
								echo "<tr><td colspan='4' class='w3-center'>No departments found.</td></tr>";
							}
							?>
						</tbody>
					</table>
				</div>
			</div>
			<div class="col-sm-1"></div>
		</div>
	</div>
</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>
