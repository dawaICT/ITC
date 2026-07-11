<?php
require "includes/admin.php";
error_reporting(0);

if(!empty($_POST)){
    if(isset($_POST["course_code"], $_POST["staff_id"])) {

      $course_code = trim($_POST["course_code"]);
      $staff_id = trim($_POST["staff_id"]);

      $checkStmt = $db->prepare("SELECT course_code FROM course_lecturer WHERE course_code = ? AND staff_id = ? LIMIT 1");
      $index = null;
      if ($checkStmt) {
          $checkStmt->bind_param("ss", $course_code, $staff_id);
          $checkStmt->execute();
          $checkStmt->bind_result($index);
          $checkStmt->fetch();
          $checkStmt->close();
      }

      if (isset($index)) {
          echo"<script>alert('Failed! Course Module already assigned to the selected lecturer!')</script>";
          echo"<script>window.open('courses.php','_self')</script>";

            }

      else  if (!empty($course_code) && !empty($staff_id)) {
          $insert = $db->prepare("INSERT INTO  course_lecturer (course_code, staff_id) VALUE(?,?)");
          $insert ->bind_param("ss", $course_code, $staff_id);

          if ($insert->execute()) {
            echo "<script>alert('Lecturer assigned course module successfully!')</script>";
            echo"<script>window.open('courses.php','_self')</script>";
            }
          }
          else {
            echo "<script>alert('Process failed!')</script>";
            echo"<script>window.open('courses.php','_self')</script>";

          }

      }
        }

?>
<!DOCTYPE html>
<html>
<title>Course Lecturers - ITC</title>
<meta name="viewport" content="width=device-width, initial-scale=1"><meta charset="UTF-8">
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
					<p>Courses | assign Course Lecturer</p>
					<hr>

				</div>
					<br>
				<div class="">
				<div class="container">
					<?php
						if (isset($_GET['update'])) {
							$update = ($_GET['update']);
								if($Results = $db->query("SELECT * FROM courses 
									WHERE course_code = '$update'")) {
										if($count = $Results->num_rows) {
										while($row = $Results->fetch_object()){
										$records_2[] = $row;
										}
										$Results->free();
									}
								}
							}

                    ?>

                    <?php
						foreach($records_2 as $r) {
					?>
					<h3 class="w3-center"><strong>Assign Course Lecturer</strong></h3>
                    <form action="courseLecturer.php" method="post" class="#" role="form">
					<table id="myTable" class="table table-hover align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>Course Code</th>
	                        <td><input type="text" class="form-control" name="course_code" autofocus id="course_code" 
                                    value="<?php echo $r->course_code?>" readonly></td>
	                    </tr>
                        <?php	
						}  
					    ?>
                        <tr>
                            <th>Lecturer ID</th>
	                        <td>
                            <div class="form-group">
                                <label for="staff_id">Lecturer ID:</label><br>
                                <select class="form-control" name="staff_id" id="staff_id">
                                    <option disabled selected>--select lecturer--</option>
                                    <?php

                                        if($results1 = $db->query("SELECT * FROM staff INNER JOIN staff_positions
                                            ON staff.staff_id = staff_positions.staff_id
                                            INNER JOIN positions ON staff_positions.PosID = positions.PosID
                                            WHERE positions.PosName = 'Lecturer'")) {
                                                if($count1 = $results1->num_rows) {

                                                while($row = $results1->fetch_object()){

                                                    $records1[] = $row;
                                                }

                                                $results1->free();
                                            }
                                            }
                                    ?>
                                    <?php
                                    foreach($records1 as $r) {
                                    ?>
                                    <option value="<?php echo ($r->staff_id); ?>"><?php echo ($r->title); ?> <?php echo ($r->Fname); ?> <?php echo ($r->Lname); ?>-(<?php echo ($r->staff_id); ?>)</option>

                                    <?php 
                                    }  
                                    ?>
                                </select> 
                                </div>
                            </td>
	                    </tr>
                        </thead>
                      </table>
                      <br>
                      <a class="w3-btn w3-round w3-blue" href="courses.php">Cancel</a>
                      <button class="w3-btn w3-round w3-large w3-green" type="submit">Assign</button>
                      </form>

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
