<?php
require "includes/nav.php";
error_reporting(0);

?>
<!DOCTYPE html>
<html>
<head>
<link rel="stylesheet" href="assets/vendor/sweetalert2/dist/sweetalert2.min.css">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<link rel="stylesheet" type="text/css" href="css_main/admin.css">
<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">
<link rel="stylesheet" type="text/css" href="assets/css/font-awesome.css">
</head>   
<body>
      <div class="w3-container">
        <div class="row">
            <div class="col-sm-1"></div>
                <div class="col-sm-8">
                    <div class="jumbotron">
                        <div class="container">
                            <h4 class="w3-center"><strong>Track your application.</strong></h4>
                        <form action="trackApp.php" method="post" class="#" role="form">

                            <div class="form-group">
                            <label for="search">NRC/Passport #:</label><br>
                            <input type="text" class="form-control" name="search" autofocus id="search" 
                            placeholder="Enter your ID">
                            </div>
                            <div class="form-group"><br>
							<button class="btn btn-block w3-btn w3-green" type="submit" name= "submit">Submit</button>
						</div>
                        </form><!-- form ends--> 
                        <?php

										if (isset($_POST['submit'])) {

											$search = $db->real_escape_string($_POST['search']);

											$resultSet = $db->query("SELECT * FROM processed_applicants WHERE nrc_pass = '$search'");
											if ($resultSet->num_rows > 0) {
												while ($rows = $resultSet->fetch_assoc()) 
												{
													$Fname = $rows['Fname'];
													$Lname = $rows['Lname'];
													$nrc_pass = $rows['nrc_pass'];
                                                    $program = $rows['program'];

												echo "<table class='table table-hover align-middle shadow-sm'>
                                                        <thead class='table-light'>
                                                        <tr class='w3-purple'>
                                                          <th>Full Names</th>
                                                          <th>NRC</th>
                                                          <th>Program</th>
                                                          <th>Status</th>
                                                        </tr>
                                                        </thead>
                                                        <tr>
                                                          <td>$Fname $Lname</td>
                                                          <td>$nrc_pass</td>
                                                          <td>$program</td>
                                                          <td>Accepted</td>
                                                        </tr>
                                                    </table><br>
                                                    <p>Congratulations!!! Check your mail for an acceptance letter or visit the admissions office.<p>";
												}
											}else{
                                                echo '<h4 class="alert alert-danger text-center">'."No results found. Your application has not yet been processed!".'</h4>';
											}
										}

										?>
                        </div>
                    </div>
                </div>
        <div class="col-sm-1"></div>
        </div>      
      </div>
</body>
<script src="assets/vendor/sweetalert2/dist/sweetalert2.min.js"></script>
<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>