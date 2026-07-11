<?php
require "includes/admin.php";
error_reporting(0);
if(isset($_POST['update'])){

    $Sid = trim($_POST["Sid"]);
    $program_code = trim($_POST["program_code"]);
    $intake = trim($_POST["intake"]);
    $mode = trim($_POST["mode"]);
    $startYear = trim($_POST["startYear"]);
    $endYear = trim($_POST["endYear"]);

   $sql = "update student_program set program_code = '$program_code', intake = '$intake',
   mode = '$mode', startYear = '$startYear', endYear = '$endYear' WHERE Sid ='$Sid'";

    $result = mysqli_query($db, $sql);
    if (!empty($result)) {
      echo "<script>alert('Student Program information updated successfully')</script>";
          echo"<script>window.open('students_by_admin.php','_self')</script>";
    } else {
      echo "<script>alert('Student Program information could not update')</script>";
      echo"<script>window.open('students_by_admin','_self')</script>";
    }

}
?>
<!DOCTYPE html>
<html>
<title>Edit Program - ITC</title>
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
					<h3>Edit student program</h3>
					<hr>

				</div>
					<br>
				<div class="">
				<div class="container">
					<?php
						if (isset($_GET['update'])) {
							$update = ($_GET['update']);
								if($results = $db->query("SELECT * FROM student_program 
									WHERE Sid = '$update'")) {
										if($count = $results->num_rows) {
										while($row = $results->fetch_object()){
										$records_1[] = $row;
										}
										$results->free();
									}
								}
							}

                    ?>

                    <?php
						foreach($records_1 as $r) {
					?>
					<h3 class="w3-center"><strong>Edit student program of study</strong></h3>
                    <form action="editProgram.php" method="post" class="#" role="form">
					<table id="myTable" class="table table-hover align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>Student ID</th>
	                        <td><input type="text" class="form-control" name="Sid" autofocus id="Sid" 
                            value="<?php echo $r->Sid?>" autocomplete="off" readonly></td>
	                    </tr>
                        <tr>
                            <th>Program of study</th>
	                        <td>
                                <input type="text" class="form-control" name="program_code" autofocus id="program_code" 
                                    value="<?php echo $r->program_code?>" autocomplete="off">
                                </td>
	                    </tr>
                        <tr>
                            <th>Intake</th>
	                        <td><input type="text" class="form-control" name="intake" autofocus id="intake" 
                                    value="<?php echo $r->intake?>" autocomplete="off"></td>
	                    </tr>
                        <tr>
                            <th>Mode</th>
	                        <td>
                                <input type="text" class="form-control" name="mode" autofocus id="mode" 
                                    value="<?php echo $r->mode?>" autocomplete="off">
                                </td>
	                    </tr>
                        <tr>
                            <th>Start Year</th>
	                        <td><input type="text" class="form-control" name="startYear" autofocus id="startYear" 
                                    value="<?php echo $r->startYear?>" autocomplete="off"></td>
	                    </tr>
                        <tr>
                            <th>End Year</th>
	                        <td>
                                <input type="text" class="form-control" name="endYear" autofocus id="endYear" 
                                    value="<?php echo $r->endYear?>" autocomplete="off">
                                </td>
	                    </tr>
                        </thead>
                      </table>
                      <br>
                      <a class="w3-btn w3-round w3-blue" href="students_by_admin.php">Cancel</a>
                      <button class="w3-btn w3-round w3-large w3-green" type="submit" name="update">Update</button>
                      <a class="w3-btn w3-round w3-orange" href="students_by_admin.php">Clear</a>
                      <a class="w3-btn w3-round w3-red" href="deleteStudentProgram.php?del=<?php echo $r->Sid?>">Drop</a>
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
