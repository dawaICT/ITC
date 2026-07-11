<?php
include "includes/admin.php";
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
			<div class="col-sm-10 w3-animate-right">
				<div class="row">
					<h3>Payments</h3>

					<ul class="w3-navbar">
					  <li><a class="w3-hover-blue" href="#"><span class="glyphicon glyphicon-user"></span> Students</a></li>
					  <li><a class="w3-hover-blue" href="payments.php"><span class="glyphicon glyphicon-usd"></span> Payments</a></li>
					  	<div class="w3-dropdown-hover">
						  <button class="w3-btn w3-light-grey w3-hover-blue w3-text-blue"><span class="glyphicon glyphicon-credit-card"></span> Create Invoice</button>
						  <div class="w3-dropdown-content w3-light-grey  w3-border">
						    <a href="InvoiceStudent.php">Returning student</a>
						    <a href="InvoiceNewStudent.php">New student</a>
						  </div>
						</div>
					  </li>
					</ul>
					<hr>

				</div>
				<div class="w3-card-4">
					<div class="w3-container">

					</div>
				</div>
			</div>
		</div>
	</div>
</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>
