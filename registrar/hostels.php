<?php
$page_title = 'Hostels';
include "includes/admin.php";
include 'add_student.php';
error_reporting(0);

?>
<!DOCTYPE html>
<html>
<title>Hostels - ITC</title>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<meta charset="UTF-8">   
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.7.1/jquery.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.10.2/css/jquery.dataTables.min.css">
<script type="text/javascript" src="https://cdn.datatables.net/1.10.2/js/jquery.dataTables.min.js"></script>
<script type="text/javascript" src="dist/js/bootstrap.min.js"></script>
<link rel="stylesheet" type="text/css" href="w3/w3.css">
    <link rel="stylesheet" type="text/css" href="css_main/admin.css">
    <link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">   

<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
	<div class="w3-container">
		<div class="row">
			<div class="col-sm-2"></div>
			<div class="col-sm-9 w3-card-4 w3-animate-right">
					<div class="w3-container">
					<h3>Student boarding applicants</h3>
					<hr>
					<br>
						<br>
							<?php
								$number = 1;

								if($results = $db->query("SELECT * FROM boarding_applicants INNER JOIN students
								ON boarding_applicants.Sid= students.SID INNER JOIN student_program
								ON students.SID = student_program.Sid INNER JOIN programs
								ON student_program.program_code = programs.program_code")) {
										if($count = $results->num_rows) {

										while($row = $results->fetch_object()){

										$records[] = $row;
									}

									$results->free();
									}
									else {
										echo '<h4 class="alert alert-danger">'."No student record found.".'</h4>'.'<br>';
									}
								}

							?>
							<h3 class="text-danger">
								<?php
								if (isset($_SESSION['successDel'])){

									echo $_SESSION['successDel'];
									session_unset(); 
								}
								 ?>
								<?php
								if (isset($_SESSION['successDrop'])){

									echo $_SESSION['successDrop'];
									session_unset(); 
								}
								 ?>
							</h3>
							<table id="myTable" class="table table-hover align-middle">
								<thead class="table-light">
									<tr>
									<th>No.</th>
									<th>Student No</th>
										<th>Names</th>
										<th>Gender</th>
										<th>Program</th>
										<th>Reason</th>
										<th>Date applied</th>
										<th class="w3-center">Action</th>
									</tr>
								</thead>
								<tbody>
									<?php
									foreach($records as $r) {
									?>
										<tr>
										<td><?php echo $number++; ?>.</td>
										<td><?php echo ($r->SID); ?></td>
										<td><?php echo ($r->Fname); ?> <?php echo ($r->Lname); ?></td>
										<td><?php echo ($r->sex); ?></td>
										<td><?php echo ($r->program_name); ?></td>
										<td><?php echo ($r->reason); ?></td>
										<td><?php echo ($r->dte); ?></td>

										<td class="w3-center">
											<a class='btn w3-red' href="deleteStudent.php?del=<?php echo $r->SID?>">
											<span class="glyphicon glyphicon-remove"></span>
											</a>

										</td>
										</tr>
									<?php 
									}  
									?>
								</tbody>
								</table>
					</div>
			</div>
			<div class="col-sm-2"></div>
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
