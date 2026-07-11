<?php
include "includes/admin.php";
error_reporting(0);

if(isset($_POST['update'])){

	$St_id = trim($_POST["St_id"]);
    $Sid = trim($_POST["Sid"]);
    $program_code = trim($_POST["program_code"]);
    $intake = trim($_POST["intake"]);
    $mode = trim($_POST["mode"]);
    $startYear = trim($_POST["startYear"]);
    $endYear = trim($_POST["endYear"]);

    //Check if the student number has already been used.
    /*$check="SELECT * FROM student_program WHERE Sid='$Sid'";
    if ($check_query = mysqli_query($db, $check)) {
        $check_rs=mysqli_fetch_assoc($check_query);
        $index=$check_rs['Sid'];
    }

    if (isset($index)) {
            echo"<script>alert('This student ID already exist in the system.')</script>";
            echo"<script>window.open('students_by_admin.php','_self')</script>";
    die();

    }*/

   $sql = "update student_program set Sid='$Sid', program_code='$program_code', intake='$intake', mode='$mode', 
   startYear='$startYear', endYear='$endYear' 
   WHERE St_id='$St_id'";

    $result = mysqli_query($db, $sql);
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
							$Sid = ($_POST['Sid']);

                             if($results = $db->query("SELECT * FROM student_program
								WHERE Sid = '$Sid'")) {
									if($count = $results->num_rows) {
										while($row = $results->fetch_object()){
										$records_1[] = $row;
										}
										$results->free();
									} 
                                    else {
                                        echo"<script>alert('This student ID does not exist in the system.')</script>";
                                        echo"<script>window.open('editStud_by_prog.php','_self')</script>";
                                        die();
                                    }
								}
							}

                    ?>

                    <?php
						foreach($records_1 as $r) {
					?>
					<h3 class="w3-center"><strong>Edit student by program</strong></h3>
                    <form action="processEdit_student.php" method="post" class="#" role="form">
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
