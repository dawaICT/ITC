<?php
require "../db/connect.php";
error_reporting(0);
session_start();
?>
<!DOCTYPE html>
<html>
<title>wucportal</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<meta charset="UTF-8">
<link rel="icon" href="/wucportal/images/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/wucportal/images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/wucportal/images/favicon-16.png">
    <link rel="apple-touch-icon" href="/wucportal/images/apple-touch-icon.png">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<link rel="stylesheet" type="text/css" href="css_main/admin.css">
<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">
<link rel="stylesheet" type="text/css" href="assets/css/font-awesome.css">

<body>
	<div class="container">
		<div class="row">
            <hr>
			<div class="col-sm-1"></div>
			<div class="col-sm-8">
			        <h4 class="w3-center">Student Invoice</h4>
			      <div class="w3-container">
					 <form action="process_invoice_1.php" method="post" role="form">
		                <?php

		                    if($Results = $db->query("SELECT * FROM student_program INNER JOIN program_fees
                            ON student_program.program_code = program_fees.program_code
                            WHERE student_program.Sid = '".$_SESSION['Sid']."'")) {
		                              if($count = $Results->num_rows) {
		                              while($row = $Results->fetch_object()){
		                                $Record[] = $row;
		                            }
		                            $Results->free();
		                          }
		                        }
		                  ?>
	                  	<?php
	                    foreach($Record as $r) {
	                    ?>
		                <div class="form-group">
		                  <label for="Sid">Student ID:</label><br>
		                  <input type="text" class="form-control" name="Sid" autofocus id="Sid" 
		                  value="<?php echo ($r->Sid); ?>" autocomplete="off" readonly>
		                </div>
                        <input type="hidden" class="form-control" name="program_code" id="program_code" 
                          value="<?php echo ($r->program_code); ?>">
						  <input type="hidden" class="form-control" name="narration" id="narration" 
                          value="Invoice">
		                  <label for="#">Previous balance (ZMW):</label><br>
						  <input type="text" class="form-control"value="0.00"readonly>
		                  <label for="invoice">Invoice amount (ZMW):</label><br>
						  <input type="text" class="form-control" name="invoice" id="invoice" 
                          value="<?php echo ($r->YR_2_S2); ?>" autocomplete="off" readonly>
		                  <label for="balance">Total current balance (ZMW):</label><br>
						  <input type="text" class="form-control" name="balance" id="balance" 
                          value="<?php echo ($r->balance) + ($r->YR_2_S2); ?>" autocomplete="off" readonly>
		                  <label for="semester">Semester:</label><br>
						  <input type="text" class="form-control" name="semester" id="semester" 
                          value="<?php echo $semester; ?>" autocomplete="off" readonly>
		                  <label for="Year">Year:</label><br>
						  <input type="text" class="form-control" name="Year" id="Year" 
                          value="<?php echo $Year; ?>" autocomplete="off" readonly>
			            <?php 
	                    }  
	                    ?>

				              <br><br>
				                <div class="form-group">
				                  <!--input type="submit" value="Submit"-->
				                  <button class="btn w3-orange btn-block" type="submit"><b>PROCEED TO REGISTER</b></button>
				                </div>  
				              </div>

				        </form><!--registration form ends-->

				</div>
			</div>
			<div class="col-sm-2"></div>

            <hr>
		</div>
	</div>
</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>
