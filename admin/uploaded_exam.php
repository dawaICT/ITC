<?php
include "includes/admin.php";
require_once dirname(__DIR__) . '/includes/role_helpers.php';
require_once dirname(__DIR__) . '/includes/academic_risk_engine.php';
require_once dirname(__DIR__) . '/includes/exam_upload_service.php';
error_reporting(0);

if (function_exists('canEnterExamMarks') && !canEnterExamMarks()) {
    $_SESSION['errorMsg'] = 'Access denied. You do not have permission to upload exam marks.';
    header('Location: index.php');
    exit();
}

?>
<!DOCTYPE html>
<html>
<meta name="viewport" content="width=device-width, initial-scale=1">		<meta charset="UTF-8">
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

						    $Year = (string)($_POST["Year"] ?? '');
						    $semester = (string)($_POST["semester"] ?? '');
						    $actor = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
						    $number = 1;

						   $fileName = $_FILES["file"]["tmp_name"];
						     if ($_FILES["file"]["size"] > 0 && is_uploaded_file($fileName)) {

						       $file = fopen($fileName,"r");
						       while(($column = fgetcsv($file, 10000, ",")) !== false)
						       {
						        if (count($column) < 3) {
						          echo '<p>'.$number++.". Missing required columns. <span class=\"glyphicon glyphicon-remove\"></span></p>";
						          continue;
						        }

						        $rowSemester = isset($column[3]) && trim((string)$column[3]) !== '' ? (string)$column[3] : $semester;
						        $save = wuc_exam_upload_save_mark(
						          $db,
						          (string)$column[0],
						          (string)$column[1],
						          $column[2],
						          $rowSemester,
						          $Year,
						          $actor
						        );

						        $studentLabel = htmlspecialchars((string)$column[0], ENT_QUOTES, 'UTF-8');
						        $message = htmlspecialchars((string)($save['message'] ?? 'Unable to save result.'), ENT_QUOTES, 'UTF-8');
						        if (!empty($save['ok'])) {
						          echo '<p>'.$number++.". Exam result saved for student ID: ".$studentLabel.' <span class="glyphicon glyphicon-ok"></span></p>';
						        } else {
						          echo '<p>'.$number++.". ".$studentLabel.": ".$message.' <span class="glyphicon glyphicon-remove"></span></p>';
						        }
						        }
						        fclose($file);
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
