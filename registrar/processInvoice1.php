<?php
require "../db/connect.php";
error_reporting(0);

if(!empty($_POST)){
      if(isset($_POST["Sid"], $_POST["amount_paid"], $_POST["Balance"], $_POST["invoice"], $_POST["narration"], $_POST["semester"], $_POST["Year"], $_POST["dte_time"])) {

        $Sid = trim($_POST["Sid"]);
        $amount_paid = trim($_POST["amount_paid"]);
        $Balance = trim($_POST["Balance"]);
        $narration = trim($_POST["narration"]);
        $invoice = trim($_POST["invoice"]);
        $semester = trim($_POST["semester"]);
        $Year = trim($_POST["Year"]);
        $dte_time = trim($_POST["dte_time"]);

        //Checking for duplication start.
          $check="SELECT * FROM  student_payments WHERE Sid='$Sid' AND invoice!='' AND semester='$semester' AND Year='$Year'";
          if ($check_query = mysqli_query($db, $check)) {
            $check_rs=mysqli_fetch_assoc($check_query);
            $index=$check_rs['Sid'];
          }

          if (isset($index)) 
            {

              echo "<script>alert('Student already invoiced for this semester. Please use returning to continue')</script>";
              echo"<script>window.open('invoiceNewStudent.php','_self')</script>";
              die();
            }
          // checking ends

          //inserting invoice payment for the new student.
        if (!empty($Sid) OR empty($amount_paid) OR empty($Balance) && !empty($invoice) OR empty($narration) OR empty($semester) OR empty($Year) OR empty($dte_time)) {
            // Guard: ensure no duplicate student_payments for same student/term
            if ($dup = $db->prepare("SELECT id FROM student_payments WHERE Sid = ? AND Year = ? AND semester = ? LIMIT 1")) {
                $dup->bind_param('sss', $Sid, $Year, $semester);
                $dup->execute();
                $dup->store_result();
                if ($dup->num_rows > 0) {
                    $dup->close();
                    echo "<script>alert('Student already invoiced for this semester. Please use returning to continue')</script>";
                    echo"<script>window.open('invoiceNewStudent.php','_self')</script>";
                    die();
                }
                $dup->close();
            }

            $insert = $db->prepare("INSERT INTO student_payments (Sid, amount_paid, Balance, invoice, narration, semester, Year, dte_time) VALUE(?,?,?,?,?,?,?,?)");
            $insert ->bind_param("ssssssss", $Sid, $amount_paid, $Balance, $invoice, $narration, $semester, $Year, $dte_time);

            if ($insert->execute()) {
              echo "<script>alert('Student invoice was successfull')</script>";
              echo"<script>window.open('invoiceNewStudent.php','_self')</script>";

              } else {
                echo "<script>alert('Error! invoice was unsuccessfull')</script>";
                echo"<script>window.open('invoiceNewStudent.php','_self')</script>";
              }

        }
          }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Example of Auto Loading Bootstrap Modal on Page Load</title>
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.min.js"></script>
<script>
	$(document).ready(function(){
		$("#myModal").modal('show');
	});
</script>
</head>
<body>
	<div class="w3-container">
		<div class="row">
			<div class="col-md-10">
				<div id="myModal" class="modal fade">
				    <div class="modal-dialog">
				        <div class="modal-content">
				            <div class="modal-header">
				                <h5 class="modal-title">Invoice new student</h5>
				                <button type="button" class="close" data-dismiss="modal">&times;</button>
				            </div>
				            <div class="modal-body w3-container">
				                <form>
				                    <?php
				                  if(isset($_POST['search'])){
				                  $Sid = $_POST['Sid'];
				                  if($stmt = $db->prepare("SELECT * FROM students WHERE Sid = ?")) {
				                      $stmt->bind_param('s', $Sid);
				                      $stmt->execute();
				                      $results = $stmt->get_result();
				                      if($count = $results->num_rows) {

				                        while($row = $results->fetch_object()){

				                            $records[] = $row;
				                          }

				                            $results->free();
				                          }
				                          else {
				                        echo "<script>alert('Student has not yet completed semester registration.')</script>";
				                        echo"<script>window.open('invoiceStudent.php','_self')</script>";

				                        }
				                        $stmt->close();
				                          }
				                        } 
				                        else {

				                          die();
				                        }

				                        ?>

				                      <?php
				                      foreach($records as $r) {
				                        ?>

				                          <div class="w3-container w3-blue"><p><strong><?php echo ($r->title); ?> <?php echo ($r->Fname); ?></strong> <strong><?php echo ($r->Lname); ?> -</strong> <strong><?php echo ($r->nrc_pass); ?></strong></p><br>
				                            <p><strong><u><?php echo ($r->program); ?></u></strong></p>
				                          </div>
				                            <form action="processInvoice1.php" method="post" class="form-horizontal w3-container" role="form">
				                                  <div class="form-group">
				                                    <lable for="Sid">Student ID*:</lable><br>
				                                        <input type="text" class="form-control w3-input w3-border w3-sand" name="Sid" autofocus id="Sid" 
				                                        value="<?php echo ($r->SID); ?>" autocomplete="off" readonly>

				                                  </div>

				                                  <div class="form-group">
				                                        <input type="hidden" step="any" class="form-control w3-input w3-border w3-sand" name="amount_paid" id="amount_paid" 
				                                        value="0.00">
				                                  </div>
				                                  <div class="form-group">
				                                        <input type="hidden" step="any" class="form-control w3-input w3-border w3-sand" name="Balance" id="Balance" value="0.00" >
				                                  </div>
				                                  <div class="form-group">
				                                  <lable for="invoice">Invoice Amount (ZMW)*:</lable><br>
				                                        <input type="number" step="any" class="form-control w3-input w3-border w3-sand" name="invoice" id="invoice" value="0.00" autocomplete="off" required>
				                                  </div>

				                                  <div class="form-group">
				                                    <lable for="narration">Narration*:</lable><br>
				                                    <input type="text" class="form-control w3-input w3-border w3-sand" name="narration" autofocus id="narration" 
				                                    placeholder="Enter narration" autocomplete="off" required>
				                                  </div><br>

				                                  <div class="form-group">
										    		<lable for="semester">Semester:</lable><br>
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
				                                  <lable for="Year">Year:</lable><br>
								                    <input type="text" class="form-control w3-input w3-border w3-sand" name="Year" id="Year" value="<?php echo date('Y')?>" 
				                                        placeholder="Date of application" autocomplete="off" required>

				                                </div>
				                                  <div class="form-group">
				                                    <lable for="dte_time">Date</lable><br>
				                                        <input type="date" class="form-control w3-input w3-border w3-sand" name="dte_time" id="dte_time" 
				                                        placeholder="Date of application" autocomplete="off" required>
				                                  </div><br>

				                                  <div class="form-group">
				                                    <!--input type="submit" value="Submit"-->
				                                    <button class="btn btn-sm  btn-block w3-btn w3-green" type="submit" name="submit">Submit</button>
				                                </div>
				                            </form>
				                          <?php 
				                          }

				                          ?>
				                </form>
				            </div>
				        </div>
				    </div>
				</div>
			</div>
		</div>
	</div>
</body>
</html>
