<?php
require "includes/nav.php";
error_reporting(0);

if(isset($_POST['submit']))
{

	$title = $_POST['title'];
    $course_code = $_POST['course_code'];
    $dte = $_POST['dte'];
    $due_dte = $_POST['due_dte'];
    $tem= ($_FILES['image']['name']);
    $file = $_FILES["image"]["tmp_name"];
    $path = "uploads/assessments/".$tem;
    $imageFileType = pathinfo($path,PATHINFO_EXTENSION);
    $move = move_uploaded_file($file, $path);

	// Allow certain file formats
	if($imageFileType != "jpg" && $imageFileType != "docx" && $imageFileType != "doc"
	&& $imageFileType != "pdf" ) {
		echo "<script>alert('Sorry invalid file formart. Upload files in pdf, docx, doc or jpg')</script>";
	    echo"<script>window.open('assignments.php','_self')</script>";
        die();
	}

	if($move)
		{

		$query = "INSERT INTO posted_assessments (title,course_code,dte,due_dte,image) VALUES 
		('$title','$course_code','$dte','$due_dte','$tem')";
		$query_run = mysqli_query($db,$query);

		echo "<script>alert('Assignment submitted successfully.')</script>";
	    echo"<script>window.open('assignments.php','_self')</script>";
		}
		else
		{
			echo "File was not uploaded, Limit file size is 2mb " . $conn->error;
		}
}

?>
<!DOCTYPE html>
<html>
<title>Submit Assessment - ITC</title>
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
			<div class="col-sm-1"></div>
			<div class="col-sm-10">

				  <div id="upload_ca" class="w3-modal">
				    <div class="w3-modal-content w3-animate-zoom w3-card-8">
				      <header class="w3-container w3-black"> 
				        <span onclick="document.getElementById('upload_ca').style.display='none'" 
				        class="w3-closebtn">×</span>
				        <h3 class="w3-center">Post Student Assessments</h3>
				      </header>
				      <div class="w3-container">
				        <form  action="post_assign.php" method="post" enctype="multipart/form-data">
				        	<div class="form-group">
				                  <lable for="title">Title:</lable><br>
				                  <input type="text" class="form-control" id="title" name="title" required>
				             </div>
				                <div class="form-group">
				                  <lable for="course_code">Course name:</lable><br>
				                  <select class="form-control" name="course_code" id="course_code">
				                    <option disabled selected>Select course</option>
				                  <?php

				                    if($results = $db->query("SELECT * FROM course_lecturer INNER JOIN courses
										ON course_lecturer.course_code = courses.course_code
										WHERE staff_id = 'LVT24'")) {
				                              if($count = $results->num_rows) {

				                              while($row = $results->fetch_object()){

				                                $records[] = $row;
				                            }

				                            $results->free();
				                          }
				                        }
				                  ?>
				                  <?php
				                    foreach($records as $r) {
				                    ?>
				                    <option value="<?php echo ($r->course_code); ?>"><?php echo ($r->course_name); ?></option>

				                    <?php 
				                    }  
				                    ?>
				                </select>
				                </div>
				                <div class="form-group">
				                  <lable for="dte">Date:</lable><br>
				                  <input type="date" class="form-control" id="dte" name="dte" required>
				                </div>
				                <div class="form-group">
				                  <lable for="due_dte">Due date:</lable><br>
				                  <input type="date" class="form-control" id="due_dte" name="due_dte" required>
				                </div>
				                <div class="form-group">
				                  <lable for="image">Upload file:</lable><br>
				                  <input type="file" class="form-control" id="image" name="image">
				                </div>
				                <div class="form-group">
				                  <!--input type="submit" value="Submit"-->
				                  <button class="btn btn-sm btn-info btn-block" type="submit" name="submit">SUBMIT</button>
				                </div>  
				        	</form><!--registration form ends-->
				            </div>

				    </div>
				  </div>
		</div>
		<div class="col-sm-1"></div>
	</div>
</div>
</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>
