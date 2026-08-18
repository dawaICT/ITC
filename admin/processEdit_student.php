<?php
include "includes/admin.php";
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
error_reporting(0);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if(isset($_POST['update'])){
    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        echo "<script>alert('Invalid or expired form token. Please try again.')</script>";
        echo "<script>window.open('students_by_admin.php','_self')</script>";
        exit;
    }

	$St_id = trim($_POST["St_id"]);
    $Sid = trim($_POST["Sid"]);
    $program_code = trim($_POST["program_code"]);
    $intake = trim($_POST["intake"]);
    $mode = trim($_POST["mode"]);
    $startYear = trim($_POST["startYear"]);
    $endYear = trim($_POST["endYear"]);

   $stmt = $db->prepare(
       'UPDATE student_program SET Sid = ?, program_code = ?, intake = ?, mode = ?, startYear = ?, endYear = ? WHERE St_id = ?'
   );
   if ($stmt) {
       $stmt->bind_param('sssssss', $Sid, $program_code, $intake, $mode, $startYear, $endYear, $St_id);
       $result = $stmt->execute();
       $stmt->close();
   } else {
       $result = false;
   }

    if (!empty($result)) {
		echo "<script>alert('Student information by program was successfully upadated')</script>";
		echo"<script>window.open('students_by_admin.php','_self')</script>";
    } else {
      echo "<script>alert('Student information by program could not update')</script>";
      echo"<script>window.open('students_by_admin.php','_self')</script>";
    }

}

?>
<!DOCTYPE html>
<html>
<status></status>
<meta name="viewport" content="width=device-width, initial-scale=1"><meta charset="UTF-8">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<link rel="stylesheet" type="text/css" href="css_main/admin.css">
<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">

<body>
<div class="w3-container">
		<div class="row">
			<div class="col-sm-2"></div>
			<div class="col-sm-9 w3-animate-right">
				<div class="row">
					<div class="jumbotron w3-card-4">
						<div class="container w3-white w3-card-4">
					<h3>Edit student information</h3>
					<hr>
				<div class="container">
					<?php
						if (isset($_POST['search'])) {
							$Sid = trim($_POST['Sid']);
                            $records_1 = [];
                            if ($stmt = $db->prepare('SELECT * FROM student_program WHERE Sid = ?')) {
                                $stmt->bind_param('s', $Sid);
                                $stmt->execute();
                                $results = $stmt->get_result();
                                if ($results && $count = $results->num_rows) {
                                    while ($row = $results->fetch_object()) {
                                        $records_1[] = $row;
                                    }
                                } else {
                                    echo"<script>alert('This student ID does not exist in the system.')</script>";
                                    echo"<script>window.open('editStud_by_prog.php','_self')</script>";
                                    die();
                                }
                                $stmt->close();
                            }
							}

                    ?>

                    <?php
						foreach($records_1 as $r) {
					?>
					<h3 class="w3-center"><strong>Edit student by program</strong></h3>
                    <form action="processEdit_student.php" method="post" class="#" role="form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
					<table id="myTable" class="table table-hover align-middle">
                        <thead class="table-light">

                        	<tr><input type="hidden" class="form-control" name="St_id" id="St_id" 
										value="<?php echo $r->St_id?>">
                            <th>Enter New Student ID</th>
								<td><input type="text" class="form-control" name="Sid" autofocus id="Sid" 
										value="<?php echo $r->Sid?>" autocomplete="off">
								</td>
	                       </tr>
                           <th>Program</th>
								<td><input type="text" class="form-control" name="program_code" id="program_code" 
										value="<?php echo $r->program_code?>" autocomplete="off">
								</td>
	                       </tr>
                           <th>Intake</th>
								<td><input type="text" class="form-control" name="intake" id="intake" 
										value="<?php echo $r->intake?>" autocomplete="off">
								</td>
	                       </tr>
                           <th>Mode of study</th>
								<td><input type="text" class="form-control" name="mode" id="mode" 
										value="<?php echo $r->mode?>" autocomplete="off">
								</td>
	                       </tr>
                           <th>Year of commencement</th>
								<td><input type="text" class="form-control" name="startYear" id="startYear" 
										value="<?php echo $r->startYear?>" autocomplete="off">
								</td>
	                       </tr>
                           <th>Year of completion</th>
								<td><input type="text" class="form-control" name="endYear" autofocus id="endYear" 
										value="<?php echo $r->endYear?>" autocomplete="off">
								</td>
	                       </tr>

                        </thead>
                      </table>
                      <br>
                      <a class="w3-btn w3-round w3-blue" href="students_by_admin.php">Cancel</a>
                      <button class="w3-btn w3-round w3-large w3-green" type="submit" name="update">Update</button>
                      </form>
					<?php	
						}  
					?>
				</div>
                <br>
			</div>
			</div>
			</div>

			<div class="col-sm-1"></div>
		</div>
	</div>
</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>
