<?php
$page_title = 'View Staff';
include "includes/admin.php";
error_reporting(0);

?>
<!DOCTYPE html>
<html>
<title>View Staff - ITC</title>
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
			<div class="col-sm-9 w3-animate-right">
				<div class="row">
					<div class="jumbotron w3-card-4">
						<div class="container w3-white w3-card-4">
					<h3>View Staff</h3>
					<hr>
				<div class="container">
					<?php
						if (isset($_GET['view'])) {
							$view = $_GET['view'];
								if($results = $db->query("SELECT * FROM staff 
									WHERE staff_id = '$view'")) {
										if($count = $results->num_rows) {
										while($row = $results->fetch_object()){
										$records_1[] = $row;
										}
										$results->free();
									}
								}
							}

                    ?>

                    <?php
						foreach($records_1 as $r) {
					?>
					<h3><strong>Staff Personal Information</strong></h3>
					<table id="myTable" class="table table-hover align-middle">
                        <thead class="table-light">
                            <th>Staff ID</th>
	                        <td>:<?php echo $r->staff_id?></td>
	                       </tr>
						<tr>
                            <th>Title</th>
	                        <td>:<?php echo $r->title?></td>
	                       </tr>
                          <tr>
	                       <tr>
	                          <th>First name</th>
	                          <td>:<?php echo $r->Fname?></td>
	                        </tr>
	                        <tr>
	                          <th>Last name</th>
	                          <td>:<?php echo $r->Lname?></td>
	                        </tr>
	                        <tr>
	                          <th>Gender</th>
	                          <td>:<?php echo $r->sex?></td>
                          </tr>
                          <tr>
                            <th>Country</th>
	                        <td>:<?php echo $r->country?></td>
	                       </tr>
	                       <tr>
	                          <th>NRC/Passport</th>
	                          <td>:<?php echo $r->nrc_pass?></td>
	                        </tr>
	                        <tr>
	                          <th>Mobile</th>
	                          <td>:<?php echo $r->mobile?></td>
                          </tr>
	                       <tr>
	                          <th>Email</th>
	                          <td>:<?php echo $r->email?></td>
	                        </tr>
	                        <tr>
	                          <th>Home address</th>
	                          <td>:<?php echo $r->address?></td>
	                        </tr>
	                        <tr>
	                          <th>Highest qualification</th>
	                          <td>:<?php echo $r->qualification?></td>
                          </tr>
                        </thead>
                      </table>
                      <br>
                      <button onclick="history.back()" class="w3-btn w3-round w3-blue">Back</button>
					<?php	
						}  
					?>
				</div>
                <br>
			</div>
			</div>
			</div>

			<div class="col-sm-1"></div>
		</div>
	</div>
</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>
