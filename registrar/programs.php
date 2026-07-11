<?php
include "includes/admin.php";
include 'add_class.php';
error_reporting(0);

?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<meta charset="UTF-8">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<link rel="stylesheet" type="text/css" href="css_main/admin.css">
<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">
</head>
<body>
	<div class="w3-container">
		<div class="row">
			<div class="col-sm-2"></div>
			<div class="col-sm-9 w3-card-4 w3-animate-right">
				<div class="w3-container">
					<h3>Programs</h3>
					<button class="w3-btn w3-round w3-green w3-right" onclick="document.getElementById('Class').style.display='block'"><span class="glyphicon glyphicon-plus"></span> Add program</button><br>
					<hr>

				</div>
					<br>
				<!--Staff table starts here -->
				<?php
                      	$number = 1;

                       	if($results = $db->query("SELECT * FROM programs")) {
                              if($count = $results->num_rows) {

                              while($row = $results->fetch_object()){

	                                $records[] = $row;
	                            }

	                            $results->free();
	                          }
	                          else {
	                            echo "<script>alert('No records found!')</script>";
	                            die();
	                          }
	                        }

	                   	?>

							<h3 class="w3-center"><strong>Programs Offered</strong></h3>
			            		<table id="myTable" class="table table-hover align-middle">
			                        <thead class="table-light">
			                          <tr>
			                            <th>No.</th>
			                            <th>Progra Code</th>
			                              <th>Program Name</th>
			                              <th class="w3-center">Action</th>
			                          </tr>
			                        </thead>
			                        <tbody>
			                          <?php
			                          foreach($records as $r) {
			                            ?>
			                              <tr>
			                                <td><?php echo $number++; ?>.</td>
			                                <td><?php echo ($r->program_code); ?></td>
			                                <td><?php echo ($r->program_name); ?></td>
			                                <td class="w3-center">
			                                  <a class='btn w3-green' href="view_staff.php?view=<?php echo $r->staff_id?>">
											  <span class="glyphicon glyphicon-eye-open"></span>
			                                    </a>
			                                    <a class='btn w3-light-blue' href="termly_progress.php?view=<?php echo $r->SID?>">
												<span class="glyphicon glyphicon-edit"></span>
			                                    </a>
			                                    <a class='btn w3-red' href="termly_progress.php?view=<?php echo $r->SID?>">
												<span class="glyphicon glyphicon-trash"></span>
			                                    </a>

			                                </td>

			                               </tr>
			                          <?php 
			                          }  
			                          ?>
			                        </tbody>
			                      </table>
								<!--Staff table ends here-->
			</div>
			<div class="col-sm-1"></div>
		</div>
	</div>
</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>
