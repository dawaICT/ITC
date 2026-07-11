<?php

error_reporting(0);

?>
<!DOCTYPE html>
<html>
<title>My Courses - ITC</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<meta charset="UTF-8">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<link rel="stylesheet" type="text/css" href="css_main/admin.css">
<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">
<link rel="stylesheet" type="text/css" href="assets/css/font-awesome.css">

<body>
	<div class="container">
		<div class="row">
			<div class="w3-card-4">
			<h3>MyCourses <span class="glyphicon glyphicon-chevron-right"></span></h3>
			<hr>
			<a href="view_ca.php"><button class="w3-btn w3-border w3-round bg-primary">View uploaded CAs</button></a>
			<div class="w3-container"><br>
				<?php
				$number = 1;

				if($results = $db->query("SELECT * FROM semester_registration INNER JOIN course_levels
					ON semester_registration.program_code = course_levels.program_code INNER JOIN courses
					ON course_levels.course_code = courses.course_code 
					WHERE semester_registration.Sid = '".$_SESSION['Sid']."' AND semester_registration.semester = course_levels.semester
					AND semester_registration.Year = course_levels.Year")) {
					if($count = $results->num_rows) {

						while($row = $results->fetch_object()){

								$records[] = $row;
							}

								$results->free();
							}
							else {
						echo '<h4 class="alert alert-danger">'."Complete semester or course registration process to access 
						your courses.".'</h4>'.'<br>';

					}
				}

				?>
				<table class="table table-striped table-bordered table-hover align-middle">
					<tr>
						<th>No.</th>
						<th>Course code</th>
						<th>Course name</th>
						<th>Action</th>
					</tr>

				<?php
	              foreach($records as $r) {
	              ?>
					<tr>
					  <td><?php echo $number++; ?>.</td>
					  <td><?php echo $r->course_code; ?></td>
					  <td><?php echo $r->course_name; ?></td>
					  <td>
						<a href="course.php?view=<?php echo $r->course_code;?>">
						<button class="w3-btn w3-round bg-primary">Open</button></a>
					  </td>
					</tr>

					<?php 
                      }  
                      ?>
					</table>
				</div>
			</div>
		</div>
	</div>
</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>
