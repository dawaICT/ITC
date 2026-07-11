<?php
require "includes/admin.php";
error_reporting(0);
if(isset($_POST['update'])){

    $Co_id = trim($_POST["Co_id"]);
    $course_code = trim($_POST["course_code"]);
    $course_name = trim($_POST["course_name"]);

   $sql = "update courses set course_code = '$course_code', course_name = '$course_name' WHERE Co_id ='$Co_id'";

    $result = mysqli_query($db, $sql);
    if (!empty($result)) {
      echo "<script>alert('Course module updated successfully')</script>";
          echo"<script>window.open('view_all_courses.php','_self')</script>";
    } else {
      echo "<script>alert('Course module could not update')</script>";
      echo"<script>window.open('view_all_courses.php','_self')</script>";
    }

}
?>
<!DOCTYPE html>
<html>
<title>Edit Course - ITC</title>
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
					<h3>Edit Courses Module</h3>
					<hr>

				</div>
					<br>
				<div class="">
				<div class="container">
					<?php
						if (isset($_GET['update'])) {
							$update = ($_GET['update']);
								if($results = $db->query("SELECT * FROM courses 
									WHERE Co_id = '$update'")) {
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
					<h3 class="w3-center"><strong>Edit Course</strong></h3>
                    <form action="editCourse.php" method="post" class="#" role="form">
					<table id="myTable" class="table table-hover align-middle">
                        <thead class="table-light">
                        <tr>

	                        <td><input type="hidden" class="form-control" name="Co_id" autofocus id="Co_id" 
                                    value="<?php echo $r->Co_id?>" autocomplete="off"></td>
	                    </tr>
                        <tr>
                            <th>Course Code</th>
	                        <td><input type="text" class="form-control" name="course_code" autofocus id="course_code" 
                                    value="<?php echo $r->course_code?>" autocomplete="off"></td>
	                    </tr>
                        <tr>
                            <th>Course Name</th>
	                        <td>
                                <input type="text" class="form-control" name="course_name" autofocus id="course_name" 
                                    value="<?php echo $r->course_name?>" autocomplete="off">
                                </td>
	                    </tr>
                        </thead>
                      </table>
                      <br>
                      <a class="w3-btn w3-round w3-blue" href="view_all_courses.php">Cancel</a>
                      <button class="w3-btn w3-round w3-large w3-green" type="submit" name="update">Update</button>
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
