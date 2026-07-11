<?php
include "includes/admin.php";
error_reporting(0);

?>
<!DOCTYPE html>
<html>
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
			<div class="col-sm-10 w3-animate-right">
				<div class="row">
					<h3>Uploaded Exam results</h3>
					<hr>

				</div>
				<div class="w3-card-4">
					<div class="w3-container">
						<div id="#" class="tab-pane active"><br>
                        <?php

						if(isset($_POST["import"]))
						  {

						    $Year = $_POST["Year"];
						    $number = 1;

						   $fileName = $_FILES["file"]["tmp_name"];
						     if ($_FILES["file"]["size"] > 0) {

						       $file = fopen($fileName,"r");
						       while(($column = fgetcsv($file, 10000, ",")) !== false)
						       {

						        $check="SELECT * FROM  exams WHERE Sid='".($column[0])."' AND Course_Code='".$column[1]."' AND Year='".$Year."'";
						        if ($check_query = mysqli_query($db, $check)) {
						          $check_rs=mysqli_fetch_assoc($check_query);
						          $index=$check_rs['Sid'];
						        }

						        if (isset($index)) 
						          {
						            echo'<p>'.$number++.". Exam results already submitted for student ID:".$index.' <span class="glyphicon glyphicon-remove"></span></p>';
						          }

						        $Total = $column[2];
						        $Total_marks = ($Total/100)*60;

						        if ($column[0]!==$index && $column[1]!==$index && $Year!==$index) 
						          {

						            $sql = "INSERT INTO  exams (Sid,Course_Code,Exam_marks,Total_marks,Year) VALUES ('".$column[0]."','".$column[1]."','".$column[2]."','".$Total_marks."','".$Year."')";

						            $result = mysqli_query($db, $sql);
						            if (!empty($result)) {
						              echo '<p>'.$number++.". Exam results uploaded successfully:".$column[0].' <span class="glyphicon glyphicon-ok"></span></p>';
						            } else {
						              echo "Problem encountered uploading exam results.". mysqli_error($db);
						            }

						          }
						        }
						     }
						  }

						?>
                            </div><br>
					</div>
				</div>
			</div>
		</div>
	</div>
</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>
