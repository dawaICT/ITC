<?php

session_start();
?>
<!DOCTYPE html>
<html lang="en-us">
	<head>
		<meta charset="UTF-8">
		<meta http-equiv="x-ua-compatible" content="IE edge">
		<link rel="icon" href="/wucportal/images/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/wucportal/images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/wucportal/images/favicon-16.png">
    <link rel="apple-touch-icon" href="/wucportal/images/apple-touch-icon.png">
		<link rel="stylesheet" type="text/css" href="w3/w3.css">
		<link rel="stylesheet" type="text/css" href="css_main/style.css">
		<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
		<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">
        <style>
			body { 
				background: no-repeat center fixed; 
			  	background-size: cover;
			  	color: white; 
			}
			.nav-link {
				text-decoration: none;
			}
			.nav-link:hover {
				text-decoration: none;
			}
			.login-container {
				max-width: 400px;
				margin: 0 auto;
				padding: 20px;
				background: white;
				border-radius: 10px;
				box-shadow: 0 0 20px rgba(0,0,0,0.1);
			}
			.form-control {
				border-radius: 25px;
				padding: 10px 15px;
				border: 1px solid #ddd;
				margin-bottom: 15px;
			}
			.btn-login {
				border-radius: 25px;
				padding: 10px;
				font-weight: bold;
				background: #ff6b6b;
				border: none;
				color: white;
				transition: all 0.3s ease;
			}
			.btn-login:hover {
				background: #ff5252;
				transform: translateY(-2px);
			}
			.links-container {
				display: flex;
				justify-content: center;
				margin-top: 15px;
			}
			.links-container a {
				color: #666;
				text-decoration: none;
				font-size: 14px;
			}
			.links-container a:hover {
				color: #ff6b6b;
			}
			.error-message {
				color: #dc3545;
				font-size: 14px;
				margin-bottom: 15px;
			}
        </style>
	</head>
	<body>   
		<div class="container">   
		    <div class="row"> 
		    <div class="col-lg-12">
			<div style="position: absolute; left: 15px; top: 15px;">
				<a href="staff_login.php" class="w3-text-black nav-link"><strong>Back to Login</strong></a>
			</div>

			<div class="login-container" style="margin-top: 50px;">
				<div class="text-center mb-4">
					<img src="images/LOGO2.jpeg" alt="ITC Logo" class="mb-4" style="height:72px;width:auto;max-width:100%">
					<h3 class="text-dark mb-3">Staff Password Reset</h3>
				</div>

				<?php
				if (isset($_SESSION['errorMessage'])){
					echo '<div class="error-message text-center">'. $_SESSION['errorMessage'].'</div>';
					session_unset(); 
				}
				?>

				<form role="form" method="post" action="resetStaffPassword.php">   
					<div class="form-group">
						<input class="form-control" placeholder="Enter Staff ID" name="staff_id" type="text" required>
					</div>
					<div class="form-group">
						<input class="form-control" placeholder="Enter NRC/Passport Number" name="nrc" type="text" required>
					</div>
					<div class="form-group">
						<input class="form-control" placeholder="New Password" name="password" type="password" required>
					</div>
					<div class="form-group">
						<input class="form-control" placeholder="Confirm New Password" name="confirm_password" type="password" required>
					</div>
					<button type="submit" class="btn btn-login btn-block" name="reset">Reset Password</button>

					<div class="links-container">
						<a href="staff_login.php">Back to Login</a>
					</div>
				</form>
			</div>
		    </div>   
		</div>
	</div>

	<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js"></script>
	<script src="dist/js/bootstrap.min.js"></script>
	</body>
</html> 