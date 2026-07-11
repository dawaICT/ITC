<?php

include "includes/admin.php";
$erros = array();

if(!empty($_POST)){
			if(isset($_POST["Sid"], $_POST["amount_paid"], $_POST["balance"], $_POST["narration"], $_POST["semester"], $_POST["Year"], $_POST["dte_time"])) {

              	$Sid = trim($_POST["Sid"]);
				$amount_paid = trim($_POST["amount_paid"]);
				$invoice = trim($_POST["invoice"]);
                $balance1 = trim($_POST["balance"]);
				$narration = trim($_POST["narration"]);
		        $semester = trim($_POST["semester"]);
		        $Year = trim($_POST["Year"]);
				$dte_time = trim($_POST["dte_time"]);
				$balance2 = $balance1 + $invoice;
				$balance = $balance2 - $amount_paid;

				if (!empty($Sid) && !empty($amount_paid) OR empty($balance) OR empty($invoice) && !empty($narration) && !empty($semester) && !empty($Year) && !empty($dte_time)) {
						$insert = $db->prepare("INSERT INTO student_payments (Sid, amount_paid, balance, invoice, narration, semester, Year, dte_time) VALUE(?,?,?,?,?,?,?,?)");
						$insert ->bind_param("ssssssss", $Sid, $amount_paid, $balance, $invoice, $narration, $semester, $Year, $dte_time);

						if ($insert->execute()) {
                            echo "<script>alert('Student payments updated successfully!')</script>";
							echo"<script>window.open('payments.php','_self')</script>";

							}
						}
						else {
							echo "<script>alert('Payment Failed!')</script>";
							echo"<script>window.open('payments.php','_self')</script>";

						}

				}
		      }
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
    <link rel="stylesheet" type="text/css" href="home.css">
	<link rel="stylesheet" type="text/css" href="w3/w3.css">
	<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
	<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">

	</head>

	<body>
		     <div class="col-md-2">
                </div>
                <div class="col-md-10"> 
	   				<div class="panel-body">
						<div class="w3-container">
							<ul class="w3-navbar">
								<li><a class="w3-hover-light-grey w3-text-blue" href="view_students.php">| Students</a></li> 
								  <li><a class="w3-hover-light-grey w3-text-blue" href="payments.php">| Payments</a></li>
								  	<div class="w3-dropdown-hover">
									  <button class="w3-btn w3-white w3-hover-light-grey w3-text-blue">| Create Invoice</button>
									  <div class="w3-dropdown-content w3-light-grey  w3-border">
									    <a href="invoiceStudent.php">Returning student</a>
									    <a href="invoiceNewStudent.php">New student</a>
									  </div>
									</div>
								  </li>
								</ul>

	   							<h3>Payments</h3>
								<hr>
		   					<form role="form" method="POST" action="payments.php" class="w3-row-padding">
								<!-- add class="tcal" to your input field -->
									<label>Search student </label><br>
									<input type="text" class="w3-input w3-border col-xs-8" name="SID" value="" id="SID" placeholder="Enter student ID"/>
									<input type="submit" class="w3-btn w3-large w3-blue" name= "search" value="search">
							</form><br>
		 							<?php
		 							if(isset($_POST['search'])){
		 							$SID = $_POST['SID'];
									 if($results = $db->query("SELECT * FROM students INNER JOIN student_payments
									 ON students.SID = student_payments.SID 
									 WHERE student_payments.id = (SELECT MAX(id) FROM student_payments where Sid = '$SID')")) {
											if($count = $results->num_rows) {

												while($row = $results->fetch_object()){

														$records[] = $row;
													}

														$results->free();
													}
													else {
												echo "<script>alert('Please invoice this student for the current semester to proceed.')</script>";
												echo"<script>window.open('payments.php','_self')</script>";

											  }
													}
												} 
												else {

													die();
												}

												?>

											<?php
											foreach($records as $r) {
												?>
													<div class="w3-card-4">
														<div class="w3-container w3-blue w3-center"><h4><strong><?php echo ($r->title); ?> <?php echo ($r->Fname); ?></strong> <strong><?php echo ($r->Lname); ?> -</strong> <strong><?php echo ($r->nrc_pass); ?></strong></h4>
								                         </div>
															<form action="payments.php" method="post" class="form-inline w3-container" role="form">
															    	<div class="form-group">
															    		<lable for="Sid">Student ID<i class="w3-text-red">*</i></lable><br>
										                                    <input type="text" class="form-control w3-input w3-border w3-sand" name="Sid" autofocus id="Sid" 
										                                    value="<?php echo ($r->SID); ?>" autocomplete="off" readonly>

															    	</div>
										                             <div class="form-group">
															    		<lable for="amount_paid">Amount (ZMW)<i class="w3-text-red">*</i></lable><br>
																    			<input type="number" step="any" class="form-control w3-input w3-border w3-sand" name="amount_paid" id="amount_paid" 
																    			placeholder="Enter amount to be paid" autocomplete="off" required>
															    	</div>
										                             <div class="form-group">
															    		<lable for="balance">Account balance(ZMW):</lable><br>
																    			<input type="number" step="any" class="form-control w3-input w3-border w3-sand" name="balance" id="balance" 
																    			value="<?php echo ($r->balance + $r->invoice); ?>" readonly>
															    	</div>
															    	<div class="form-group">
								                                        <input type="hidden" step="any" class="form-control w3-input w3-border w3-sand" name="invoice" id="invoice" 
								                                        value="0.00">
								                                  </div>

										                             <div class="form-group">
															    		<lable for="narration">Narration<i class="w3-text-red">*</i></lable><br>
															    		<input type="text" class="form-control w3-input w3-border w3-sand" name="narration" autofocus id="narration" 
															    		placeholder="Enter narration" autocomplete="off" required>
															    	</div><br>

									                                  <div class="form-group">
									                                    <lable for="semester">Semester<i class="w3-text-red">*</i></lable><br>
									                                    <select class="form-control w3-sand" name="semester" id="semester">
									                                      <option disabled selected>select semester</option>
									                                      <option>1</option>
									                                      <option>2</option>
									                                      <option>3</option>
									                                      <option>4</option>
									                                      <option>5</option>
									                                      <option>6</option>
									                                      <option>7</option>
									                                      <option>8</option>
									                                      <option>9</option>
									                                      <option>10</option>
									                                    </select> 
									                                  </div>
									                                  <div class="form-group">
									                                  <lable for="Year">Year<i class="w3-text-red">*</i></lable><br>
									                                    <select class="w3-input w3-border col-xs-3 form-control w3-sand"  id="year" name="Year">
									                                      <option disabled selected>Select year</option>
									                                    </select>
									                                        <script type="text/javascript"
									                                            src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.min.js"> 
									                                        </script>
									                                        <script type="text/javascript">
									                                        let startYear = 2000;
									                                        let endYear = new Date().getFullYear();
									                                        for (i = endYear; i > startYear; i--)
									                                        {
									                                          $('#year').append($('<option />').val(i).html(i));
									                                        }
									                                        </script>

									                                </div>
															    	<div class="form-group">
															    		<lable for="dte_time">Date<i class="w3-text-red">*</i></lable><br>
																    			<input type="date" class="form-control w3-input w3-border w3-sand" name="dte_time" id="dte_time" 
																    			placeholder="Date of application" autocomplete="off" required>
															    	</div><br><br>

															    	<div class="form-group">
																			<!--input type="submit" value="Submit"-->
																			<button class="btn btn-sm  btn-block w3-btn w3-green" type="submit" name="submit">Submit</button>
																	</div>
															</form>
														<?php	
														}

														?>
													</div>
												</div>

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
