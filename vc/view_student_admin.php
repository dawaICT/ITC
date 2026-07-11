<?php
$page_title = 'View Student';
include "includes/admin.php";
error_reporting(0);

?>
<!DOCTYPE html>
<html>
<title>View Student - ITC</title>
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
					<h3>Students</h3>
					<hr>
				<div class="container">
					<?php
						if (isset($_GET['view'])) {
							$view = (string)$_GET['view'];
							$stmt = $db->prepare("SELECT * FROM students INNER JOIN student_program
			                       	ON students.SID = student_program.Sid INNER JOIN programs
			                       	ON student_program.program_code = programs.program_code
									WHERE students.SID = ?");
							if ($stmt) {
								$stmt->bind_param('s', $view);
								$stmt->execute();
								$results = $stmt->get_result();
								while ($row = $results->fetch_object()) {
									$records_1[] = $row;
								}
								$stmt->close();
							}
						}

                    ?>

                    <?php
						foreach($records_1 as $r) {
					?>
					<h3><strong>Student details</strong></h3>
					<table id="myTable" class="table table-hover align-middle">
                        <thead class="table-light">
                          <tr>
                            <th>Student ID</th>
	                        <td>:<?php echo $r->SID?></td>
	                       </tr>
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
	                          <th>Date of Birth</th>
	                          <td>:<?php echo $r->dob?></td>
	                        </tr>
	                        <tr>
	                          <th>Mobile</th>
	                          <td>:<?php echo $r->mobile?></td>
                          </tr>
                          <tr>
                            <th>Status</th>
	                        <td>:<?php echo $r->status?></td>
	                       </tr>
	                       <tr>
	                          <th>Email</th>
	                          <td>:<?php echo $r->email?></td>
	                        </tr>
	                        <tr>
	                          <th>Home address</th>
	                          <td>:<?php echo $r->h_addre?></td>
	                        </tr>
	                        <tr>
	                          <th>Next of Kin</th>
	                          <td>:<?php echo $r->next_kin?></td>
                          </tr>
	                        <tr>
	                          <th>Next of Kin Contact</th>
	                          <td>:<?php echo $r->next_kin_mobile?></td>
	                        </tr>
	                        <tr>
	                          <th>Relationship</th>
	                          <td>:<?php echo $r->relat?></td>
                          </tr>
	                        <tr>
	                          <th>Program of study</th>
	                          <td>:<?php echo $r->program_name?></td>
                          </tr>
	                        <tr>
	                          <th>Intake</th>
	                          <td>:<?php echo $r->intake?></td>
	                        </tr>

	                        <tr>
	                          <th>Date of semester commencement</th>
	                          <td>:<?php echo $r->startYear?></td>
                          </tr>
	                        <tr>
	                          <th>Duration</th>
	                          <td>:<?php echo $r->duration?></td>
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
