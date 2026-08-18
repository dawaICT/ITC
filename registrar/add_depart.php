<?php

require_once dirname(__DIR__) . '/db/connect.php';
error_reporting(0);

if (!empty($_POST) && isset($_POST['deptId'], $_POST['deptName'])) {
	$deptCode = trim((string)$_POST['deptId']);
	$deptName = trim((string)$_POST['deptName']);

	if ($deptCode !== '' && $deptName !== '') {
		$exists = false;
		$check = $db->prepare(
			'SELECT id FROM departments WHERE department_code = ? OR department_name = ? LIMIT 1'
		);
		if ($check) {
			$check->bind_param('ss', $deptCode, $deptName);
			$check->execute();
			$exists = (bool)$check->get_result()->fetch_assoc();
			$check->close();
		}

		if ($exists) {
			echo "<script>alert('Failed! This department has already been added')</script>";
			echo "<script>window.open('departments.php','_self')</script>";
		} else {
			$insert = $db->prepare(
				"INSERT INTO departments (department_code, department_name, status) VALUES (?, ?, 'active')"
			);
			if ($insert) {
				$insert->bind_param('ss', $deptCode, $deptName);
				if ($insert->execute()) {
					echo "<script>alert('New department added successfully')</script>";
					echo "<script>window.open('departments.php','_self')</script>";
				} else {
					echo "<script>alert('Process failed!')</script>";
					echo "<script>window.open('departments.php','_self')</script>";
				}
				$insert->close();
			}
		}
	} else {
		echo "<script>alert('Process failed!')</script>";
		echo "<script>window.open('departments.php','_self')</script>";
	}
}

?>
<!DOCTYPE html>
<html>
<head>
  <link rel="stylesheet" href="assets/vendor/sweetalert2/dist/sweetalert2.min.css">
</head>
<body>
  <div id="dept" class="w3-modal">
    <div class="w3-modal-content w3-animate-zoom w3-card-8">
      <header class="w3-container w3-blue">
        <span onclick="document.getElementById('dept').style.display='none'"
        class="w3-closebtn">×</span>
        <h3 class="w3-center">Add New Department</h3>
      </header>
      <div class="w3-container">
        <form action="add_depart.php" method="post" role="form">
                <div class="form-group">
                  <label for="deptId">Department code:</label><br>
                  <input type="text" class="form-control" name="deptId" autofocus id="deptId"
                   placeholder="Enter department code" autocomplete="off" required>
                </div>
                <div class="form-group">
                  <label for="deptName">Department Name:</label><br>
                  <input type="text" class="form-control" name="deptName" id="deptName"
                  placeholder="Enter department name" autocomplete="off" required>
                </div>
              <br><br>
                <div class="form-group">
                  <button class="btn btn-sm btn-success btn-block" type="submit">Add</button>
                </div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
