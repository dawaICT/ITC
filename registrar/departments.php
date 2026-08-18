<?php
/**
 * Legacy Registrar departments list.
 * Columns verified against live schema: id, department_code, department_name, faculty, status.
 * Full department management lives in admin/departments.php (Admin + Registrar via portal access).
 */
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
					<a class="w3-btn w3-round w3-blue" href="/wucportal/admin/departments.php">Open full department manager</a>
					<button class="w3-btn w3-round w3-green w3-right" onclick="document.getElementById('dept').style.display='block'">
					<span class="glyphicon glyphicon-plus"></span> Add department</button><br>
					<hr>

					<table class="table table-hover align-middle">
						<thead class="table-light">
							<tr>
								<th>Code</th>
								<th>Department Name</th>
								<th>Faculty</th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody>
							<?php
							$deptResult = $db->query(
								"SELECT id, department_code, department_name, faculty, status
								 FROM departments
								 ORDER BY department_name"
							);
							if ($deptResult && $deptResult->num_rows > 0) {
								while ($deptRow = $deptResult->fetch_assoc()) {
									$code = trim((string)($deptRow['department_code'] ?? ''));
									if ($code === '') {
										$code = (string)($deptRow['id'] ?? '');
									}
									echo "<tr>";
									echo "<td>" . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . "</td>";
									echo "<td>" . htmlspecialchars((string)($deptRow['department_name'] ?? ''), ENT_QUOTES, 'UTF-8') . "</td>";
									echo "<td>" . htmlspecialchars((string)($deptRow['faculty'] ?? ''), ENT_QUOTES, 'UTF-8') . "</td>";
									echo "<td>" . htmlspecialchars((string)($deptRow['status'] ?? ''), ENT_QUOTES, 'UTF-8') . "</td>";
									echo "</tr>";
								}
								$deptResult->free();
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
</html>
