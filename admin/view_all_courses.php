<?php
require "includes/admin.php";
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
$csrfToken = wuc_csrf_token();
error_reporting(0);

?>
<!DOCTYPE html>
<html>
<title>All Courses - ITC</title>
<meta name="viewport" content="width=device-width, initial-scale=1"><meta charset="UTF-8">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<link rel="stylesheet" type="text/css" href="css_main/admin.css">
<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">   
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.7.1/jquery.min.js"></script>
	<link rel="stylesheet" href="https://cdn.datatables.net/1.10.2/css/jquery.dataTables.min.css"></style>
	<script type="text/javascript" src="https://cdn.datatables.net/1.10.2/js/jquery.dataTables.min.js"></script>
	<script src="dist/js/bootstrap.min.js"></script>
	<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
	<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">
<body>
	<div class="w3-container">
		<div class="row">
			<div class="col-sm-2"></div>
			<div class="col-sm-9 w3-card-4 w3-animate-zoom">
				<div class="w3-container">
					<p>Academics | view courses</p>
					<hr>

				</div>
						<!--Staff table starts here -->
				<?php
                      	$number = 1;

                       	if($results = $db->query("SELECT * FROM courses")) {
                              if($count = $results->num_rows) {
                              while($row = $results->fetch_object()){

	                                $Records2[] = $row;
	                            }

	                            $results->free();
	                          }
	                          else {
	                            echo '<h4 class="alert alert-danger">'."No course record found. Please add some courses".'</h4>'.'<br>';
	                            die();
	                          }
	                        }

	                   	?>
					<h3 class="w3-center"><strong>Available Courses</strong></h3>
					<hr>
					<table id="myTable" class="table table-hover align-middle">
						<thead class="table-light">
							<tr>
								<th>No.</th>
								<th>Module Code</th>
								<th>Module Name</th>
								<th class="w3-center">Action</th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach($Records2 as $r) {
							?>
								<tr>
									<td><?php echo $number++; ?>.</td>
									<td><?php echo ($r->course_code); ?></td>
									<td><?php echo ($r->course_name); ?></td>
									<td class="w3-center">
									<a class='btn w3-blue' href="editCourse.php?update=<?php echo $r->Co_id?>">
									<span class="glyphicon glyphicon-edit"></span>
									</a>
									<form action="deleteCourse.php" method="post" style="display:inline" onsubmit="return confirm('Are you sure you want to delete this course from the system permanently?')">
									<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
									<input type="hidden" name="id" value="<?php echo (int)$r->Co_id; ?>">
									<button class="btn w3-red" type="submit"><span class="glyphicon glyphicon-remove"></span></button>
									</form>

									</td>

								</tr>
							<?php 
							}  
							?>
						</tbody>
						</table>
			</div>
		</div>
	</div>
	<script>
	$(document).ready(function(){
		$('#myTable').dataTable();
	});
	</script>

</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>
