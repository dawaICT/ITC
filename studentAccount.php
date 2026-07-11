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
		<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
		<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">
		<script src="jquery-3.3.1.min.js"></script>
  		<script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert/2.1.2/sweetalert.min.js"></script>
</head>

	</head>

	<body>   

		<div class="container w3-center">   
		    <div class="row"> <br>

                <div class="col-lg-12">
                    <div class="tab-content">
                        <div id="student" class="tab-pane w3-animate-zoom in active">
                            <?php
                            if (isset($_SESSION['AccSucces'])){

                                echo '<h4 class="alert alert-success">'. $_SESSION['AccSucces'].'</h4>';
                                session_unset(); 
                            }
                                ?>
                        <h3 class="w3-text-black">To login click here. <a class="w3-text-blue" href="index.php" 
                        target="_blank">Sign in</a></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>

			<!-- placed at the end of the document so that the pages can load faster 
				============================================================================-->
			<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js">
			</script>
			<script src="dist/js/bootstrap.min.js"></script>

	</body>
</html>