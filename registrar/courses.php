<?php
require "includes/admin.php";
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
$csrfToken = wuc_csrf_token();
include 'add_courses.php';
include 'assign_course_lecturer.php';
// student_course_registration.php was never shipped; omit so the page can load.
include 'semester_courses.php';
error_reporting(0);

?>
<!DOCTYPE html>
<html>
<title>Courses - ITC</title>
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
					<h3>Courses</h3>
					<a class="w3-btn w3-round w3-orange" onclick="document.getElementById('assign').style.display='block'">
					<span class="glyphicon glyphicon-tags"></span> assign lecturer</a>
					<a class="w3-btn w3-round w3-orange w3-right" onclick="document.getElementById('semester').
					style.display='block'"><span class="glyphicon glyphicon"></span>semester courses</a>
					<a class="w3-btn w3-round w3-green w3-right" onclick="document.getElementById('Course').style.display='block'">
					<span class="glyphicon glyphicon-plus"></span> add course</a><br>
					<hr>

				</div>
					<br>
				<div class="">
					<div class="w3-container">
						<!--Staff table starts here -->
				<?php
                      	$number = 1;

                       	if($results = $db->query("SELECT * FROM courses")) {
                              if($count = $results->num_rows) {

                              while($row = $results->fetch_object()){

	                                $records[] = $row;
	                            }

	                            $results->free();
	                          }
	                          else {
	                            echo '<h4 class="alert alert-danger">'."No course record found. Please add some courses".'</h4>'.'<br>';
	                            die();
	                          }
	                        }

	                   	?>

							<h3 class="w3-center"><strong>Programs Courses</strong></h3>
			            		<table id="myTable" class="table table-hover align-middle">
			                        <thead class="table-light">
			                          <tr>
			                            <th>No.</th>
			                            <th>Code</th>
			                              <th>Module Name</th>
			                              <th class="w3-center">Remove</th>
			                          </tr>
			                        </thead>
			                        <tbody>
			                          <?php
			                          foreach($records as $r) {
			                            ?>
			                              <tr>
			                                <td><?php echo $number++; ?>.</td>
			                                <td><?php echo ($r->course_code); ?></td>
			                                <td><?php echo ($r->course_name); ?></td>
			                                <td class="w3-center">
											<a class='btn w3-green' href="editCourseLecturer.php?update=<?php echo $r->course_code?>">
												<span class="glyphicon glyphicon-eye-open"></span>
			                                    </a>
												<a class='btn w3-blue' href="editCourse.php?update=<?php echo (int)($r->id ?? 0); ?>">
												<span class="glyphicon glyphicon-edit"></span>
			                                    </a>
										<form action="deleteCourse.php" method="post" style="display:inline" onsubmit="return confirm('Delete this course?')">
										<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
										<input type="hidden" name="id" value="<?php echo (int)($r->id ?? 0); ?>">
										<button class="btn w3-red" type="submit"><span class="glyphicon glyphicon-remove"></span></button>
										</form>

			                                </td>

			                               </tr>
			                          <?php 
			                          }  
			                          ?>
			                        </tbody>
			                      </table><br>
								<!--Staff table ends here-->
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
