<?php
require "includes/admin.php";
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
$csrfToken = wuc_csrf_token();
error_reporting(0);
if(isset($_POST['update'])){
	if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request.'); }

    $id = trim($_POST["id"]);
    $course_code = trim($_POST["course_code"]);
    $staff_id = trim($_POST["staff_id"]);

    $id = filter_var($id, FILTER_VALIDATE_INT);
    if (!$id) {
      echo "<script>alert('Invalid assignment record')</script>";
      echo"<script>window.open('courses.php','_self')</script>";
      exit;
    }

    $dupStmt = $db->prepare('SELECT id FROM course_lecturer WHERE course_code = ? AND staff_id = ? AND id <> ? LIMIT 1');
    if ($dupStmt) {
      $dupStmt->bind_param('ssi', $course_code, $staff_id, $id);
      $dupStmt->execute();
      if ($dupStmt->get_result()->num_rows > 0) {
        $dupStmt->close();
        echo "<script>alert('This lecturer is already assigned to this course.')</script>";
        echo"<script>window.open('courses.php','_self')</script>";
        exit;
      }
      $dupStmt->close();
    }

    $stmt = $db->prepare('UPDATE course_lecturer SET course_code = ?, staff_id = ? WHERE id = ?');
    $stmt->bind_param('ssi', $course_code, $staff_id, $id);
    $result = $stmt->execute();
	$stmt->close();
    if (!empty($result)) {
      echo "<script>alert('Course lecturer updated successfully')</script>";
          echo"<script>window.open('courses.php','_self')</script>";
    } else {
      echo "<script>alert('Course lecturer could not update')</script>";
      echo"<script>window.open('courses.php','_self')</script>";
    }

}
?>
<!DOCTYPE html>
<html>
<title>Edit Course Lecturer - ITC</title>
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
					<h3>Edit Course Lecturer</h3>
					<hr>

				</div>
					<br>
				<div class="">
				<div class="container">
					<?php
						if (isset($_GET['update'])) {
							$update = trim((string)$_GET['update']);
								$stmt = $db->prepare('SELECT * FROM course_lecturer WHERE course_code = ?');
								$stmt->bind_param('s', $update);
								$stmt->execute();
								if($Results = $stmt->get_result()) {
										if($count = $Results->num_rows) {
										while($row = $Results->fetch_object()){
										$records_2[] = $row;
										}
										$Results->free();
										$stmt->close();
									}
								}else {
                                    echo '<h4 class="alert alert-danger">'."Course module has no lecturer assigned. 
                                    Please assign lecturer".'</h4>'.'<br>';

                                  }
							}

                    ?>

                    <?php
						foreach($records_2 as $r) {
					?>
					<h3 class="w3-center"><strong>Edit Course Lecturer</strong></h3>
                    <form action="editCourseLecturer.php" method="post" class="#" role="form">
					<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
					<table id="myTable" class="table table-hover align-middle">
                        <thead class="table-light"><tr>

	                        <td><input type="hidden" class="form-control" name="id" autofocus id="id" 
                                    value="<?php echo $r->id?>" autocomplete="off"></td>
	                    </tr>
                        <tr>
                            <th>Course Code</th>
	                        <td><input type="text" class="form-control" name="course_code" autofocus id="course_code" 
                                    value="<?php echo $r->course_code?>" autocomplete="off"></td>
	                    </tr>
                        <tr>
                            <th>Lecturer ID</th>
	                        <td>
                                <input type="text" class="form-control" name="staff_id" autofocus id="staff_id" 
                                    value="<?php echo $r->staff_id?>" autocomplete="off">
                                </td>
	                    </tr>
                        </thead>
                      </table>
                      <br>
                      <a class="w3-btn w3-round w3-blue" href="courses.php">Cancel</a>
                      <button class="w3-btn w3-round w3-large w3-green" type="submit" name="update">Update</button>
                      </form>
					  <form action="deleteCourseLecturer.php" method="post" style="display:inline" onsubmit="return confirm('Remove this assignment?')">
						<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
						<input type="hidden" name="id" value="<?php echo (int)$r->id; ?>">
						<button class="w3-btn w3-round w3-red" type="submit">Remove</button>
					  </form>
					<?php	
						}  
					?>
				</div>
				</div>
			</div>
		</div>
	</div>
	<!-- placed at the end of the document so that the pages can load faster 
	============================================================================-->
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js">
	</script>
	<script src="dist/js/bootstrap.min.js"></script>
</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>
