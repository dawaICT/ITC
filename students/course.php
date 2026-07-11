<?php

error_reporting(0);

?>
<!DOCTYPE html>
<html>
<title>Course - ITC</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<meta charset="UTF-8">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<link rel="stylesheet" type="text/css" href="css_main/admin.css">
<link rel="stylesheet" type="text/css" href="css/styles.css">
<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">
<link rel="stylesheet" type="text/css" href="assets/css/font-awesome.css">

<body>
	<div class="container">
		<div class="row">
			<?php
			if (isset($_GET['view'])) {
            $view = $_GET['view'];

			 if($results = $db->query("SELECT * FROM courses INNER JOIN lesson_notes
				ON courses.course_code = lesson_notes.course_code 
			 	where courses.course_code = '$view'")) {
							if($count = $results->num_rows) {

							while($row = $results->fetch_object()){

							$records[] = $row;
							}

							$results->free();
						}
						else {
							echo '<h4 class="alert alert-danger w3-center">'."No content for this course has been posted by the lectuerer currently.".'</h4>'.'<br>';
							die();
						}
					}
				}
				foreach($records as $r)
			?>
			<br>
			<a class="w3-btn w3-orange" href="assignments.php?view=<?php echo $r->course_code; ?>">
				Continous Assessments</a>
			<hr>
			<div class="col-md-4">
			<div id=section>
				<img src="images/Library.jpg"><br>
				<button class="w3-btn bg-primary">
				<?php echo $r->course_name; ?> - <?php echo $r->course_code; ?></button>

			</div>
			</div>
			<div class="col-md-8">
				<h3>Course content</h3>
				<table class="table table-hover align-middle">
					<tr>
						<th>Date posted</th>
						<th>Lesson Topic</th>
						<th>Video link</th>
						<th>Notes</th>
					</tr>
					<?php
					foreach($records as $r) {
					?>
					<tr>
					  <td><?php echo $r->dte; ?></td>
					  <td><?php echo $r->topic; ?></td>
					  <td>
						<a href="<?php echo $r->url; ?>" target="_blank" class="w3-btn w3-round bg-primary"><span class="glyphicon glyphicon-link"></span></a>
					</td>
					  <td>
					  	<a href="../lecturers/uploads/materials/<?php echo $r->notes?>" target="_blank">
						<button class="w3-btn w3-orange w3-round"><span class="glyphicon glyphicon-cloud-download"></span></button>
		                </a>
		            </td>
					</tr>
			</div>
				<?php 
                  }  
                  ?>

				</table>
		</div>
	</div>
</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>
