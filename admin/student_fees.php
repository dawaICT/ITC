<?php

$page_title = 'Student Fees';
include "student_nav.php";
session_start();

?>

<!DOCTYPE html>
<html>
	<head>
		<title>Student Fees - ITC</title>
		<link rel="stylesheet" type="text/css" href="w3/w3.css">
		<link rel="stylesheet" type="text/css" href="css_main/student.css">
		<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
		<link rel="stylesheet" type="text/css" href="dist/css/bootstrap-theme.min.css">   
		<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.7.1/jquery.min.js"></script>
		<link rel="stylesheet" href="https://cdn.datatables.net/1.10.2/css/jquery.dataTables.min.css"></style>
		<script type="text/javascript" src="https://cdn.datatables.net/1.10.2/js/jquery.dataTables.min.js"></script>
		<script src="dist/js/bootstrap.min.js"></script>

	<body>
		<div class="container">
			<div class="row">
				<h4>My School Fees</h4>
				<br>
			        <ul id="tab" class="nav nav-tabs">
			        	<li class="active"><a data-toggle="tab" href="#activities"><strong>Payments</strong></a></li>
	                    <li><a data-toggle="tab" href="#statement"><strong>Statement</strong></a></li>
	                </ul><br>
	                <div class="tab-content">

						<div id="activities" class="tab-pane active">
							<h4>Payments History</h4>

		           			<table id="myTable" class="table table-hover align-middle">
		                        <thead class="table-light">
		                          <tr>
		                            <th>No.</th>
		                            <th>Reference</th>
		                            <th>Trans name</th>
		                              <th>Pay mode</th>
		                              <th>Amount paid</th>
		                              <th>Date</th>
		                              <th class="w3-center">Action</th>
		                          </tr>
		                        </thead>
		                        <tbody>
		                        </tbody>
		                      </table>
						</div>
						<div id="statement" class="tab-pane">
								<div class="col-md-4">
								<h4>Choose Dates</h4>
									<form role="form" method="post" action="#" class="inline"> 
				                            <div class="form-group"  > 
				                            	<lable for="user_name" class="control-label"><strong>From:</strong></lable>
				                            	<div class="">  
				                                	<input class="form-control" placeholder="Enter your student ID" name="user_name" type="Date" required>
				                                </div>   
				                            </div>   
				                            <div class="form-group"> 
				                            	<lable for="pass" class="control-label"><strong>To:</strong></lable> 
				                            	<div class=""> 
				                                	<input class="form-control" placeholder="Enter password" name="pass" type="Date" value="" required>
				                                </div>   
				                            </div>   

				   							<div class="form-group">
					   							<div class="">
					                            	<input class="btn btn-lg btn-danger btn-block" type="submit" value="Submit" name="submit" > 
					                            </div> 
				                            </div>   
				                    </form>
				                </div>
				            <div class="col-md-8 w3-center w3-bordered"><h4>Generated Statement</h4></div>
						</div>
					</div>
				<!-- placed at the end of the document so that the pages can load faster 
						============================================================================-->
					<script>
						$(document).ready(function(){
						    $('#myTable').dataTable();
						});
					</script>
					<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js">
					</script>
					<script src="dist/js/bootstrap.min.js"></script>
			</div>
		</div>
	</body>
</html>