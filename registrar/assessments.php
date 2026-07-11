<?php
require "includes/admin.php";
error_reporting(0);

?>
<!DOCTYPE html>
<html>
<title>Assessments - ITC</title>
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
			<div class="col-sm-9 w3-animate-right">
				<div class="row">
					<div class="jumbotron w3-card-4">
						<div class="container w3-white w3-card-4">
					<h3>Approved Continous Assessment</h3>
					<hr>
						  <div class="container">
								<?php
									$number = 1;
									$records = [];
									// Approved CA lives in semester_assessment (surfaced via the
									// `exams` compatibility view); approved_assessments never existed.
									if($results = $db->query("SELECT * FROM exams WHERE LOWER(status) IN ('approved','published')")) {
											if($count = $results->num_rows) {

											while($row = $results->fetch_object()){

											$records[] = $row;
											}

											$results->free();
											}
                                            else {
                                            echo '<h4 class="alert alert-danger text-center">'."No CAs have been approved currently!".'</h4>';

                                              }
										}

									?>
						            <table id="myTable" class="table table-hover align-middle">
						                        <thead class="table-light">
						                          <tr>
						                            <th>No.</th>
						                            <th>Student No</th>
						                              <th>Course code</th>
						                              <th>A1</th>
						                              <th>A2</th>
						                              <th>T1</th>
						                              <th>T2</th>
						                              <th>Total</th>
						                              <th>Year</th>
						                              <th>Update</th>
						                          </tr>
						                        </thead>
						                        <tbody>
						                          <?php
						                          foreach($records as $r) {
						                            ?>
						                              <tr>
						                                <td><?php echo $number++; ?>.</td>
						                                <td><?php echo ($r->Sid); ?></td>
						                                <td><?php echo ($r->Course_Code); ?></td>
						                                <td><?php echo ($r->A1); ?></td>
						                                <td><?php echo ($r->A2); ?></td>
						                                <td><?php echo ($r->T1); ?></td>
						                                <td><?php echo ($r->T2); ?></td>
						                                <td><strong><?php echo ($r->Total_CA); ?></strong></td>
						                                <td><?php echo ($r->Year); ?></td>
						                                <td>
															<a class='btn w3-green' href="#?view=<?php echo htmlspecialchars((string)$r->Sid, ENT_QUOTES, 'UTF-8')?>">
															<span class="glyphicon glyphicon-edit"></span>
															</a>
														</td>
						                               </tr>
						                          <?php 
						                          }  
						                          ?>
						                        </tbody>
						                      </table>
                                              <br>
						    </div>
					</div>
				</div>
			</div>
			</div>

			<div class="col-sm-1"></div>
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
