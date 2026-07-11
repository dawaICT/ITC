<?php
$page_title = 'Edit Staff';
include "includes/admin.php";
require_once dirname(__DIR__) . '/includes/role_helpers.php';
wuc_require_systems_admin('/wucportal/portal_selection.php');
error_reporting(0);

if(isset($_POST['update'])){

    $staff_id = trim($_POST["staff_id"]);
    $deptId = trim($_POST["deptId"]);
    $title = trim($_POST["title"]);
    $Fname = trim($_POST["Fname"]);
    $Lname = trim($_POST["Lname"]);
    $sex = trim($_POST["sex"]);
    $country = trim($_POST["country"]);
    $nrc_pass = trim($_POST["nrc_pass"]);
    $mobile = trim($_POST["mobile"]);
    $email = trim($_POST["email"]);
    $address = trim($_POST["address"]);
    $qualification = trim($_POST["qualification"]);

   $sql = "update staff set deptId = '$deptId', title = '$title', Fname='$Fname', 
   Lname='$Lname', sex='$sex', country='$country', nrc_pass ='$nrc_pass', mobile = '$mobile', 
   email = '$email', address = '$address', qualification = '$qualification' 
   WHERE staff_id ='$staff_id'";

    $result = mysqli_query($db, $sql);
    if (!empty($result)) {
      echo "<script>alert('Staff information was successfully upadated')</script>";
          echo"<script>window.open('staff.php','_self')</script>";
    } else {
      echo "<script>alert('Staff information could not update')</script>";
      echo"<script>window.open('staff.php','_self')</script>";
    }

}

?>
<!DOCTYPE html>
<html>
<title>Edit Staff - ITC</title>
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
						if (isset($_GET['update'])) {
							$update = ($_GET['update']);
								if($results = $db->query("SELECT * FROM staff 
									WHERE staff_id = '$update'")) {
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
					<h3 class="w3-center"><strong>Edit Staff Personal Information</strong></h3>
                    <form action="editStaff.php" method="post" class="#" role="form">
					<table id="myTable" class="table table-hover align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>Staff ID</th>
	                        <td><input type="text" class="form-control" name="staff_id" autofocus id="staff_id" 
                                    value="<?php echo $r->staff_id?>" autocomplete="off" readonly></td>
	                    </tr>
                        <tr>
                            <th>Department ID</th>
	                        <td><input type="text" class="form-control" name="deptId" autofocus id="deptId" 
                                    value="<?php echo $r->deptId?>" autocomplete="off"></td>
	                    </tr>
						<tr>
                            <th>Title</th>
	                        <td><input type="text" class="form-control" name="title" autofocus id="title" 
                                    value="<?php echo $r->title?>" autocomplete="off"></td>
	                       </tr>
                          <tr>
	                       <tr>
	                          <th>First name</th>
	                          <td><input type="text" class="form-control" name="Fname" autofocus id="Fname" 
                                    value="<?php echo $r->Fname?>" autocomplete="off"></td>
	                        </tr>
	                        <tr>
	                          <th>Last name</th>
	                          <td><input type="text" class="form-control" name="Lname" autofocus id="Lname" 
                                    value="<?php echo $r->Lname?>" autocomplete="off"></td>
	                        </tr>
	                        <tr>
	                          <th>Gender</th>
	                          <td><input type="text" class="form-control" name="sex" autofocus id="sex" 
                                    value="<?php echo $r->sex?>" autocomplete="off"></td>
                          </tr>
                          <tr>
                            <th>Country</th>
	                        <td><input type="text" class="form-control" name="country" autofocus id="country" 
                                    value="<?php echo $r->country?>" autocomplete="off"></td>
	                       </tr>
	                       <tr>
	                          <th>NRC/Passport</th>
	                          <td><input type="text" class="form-control" name="nrc_pass" autofocus id="nrc_pass" 
                                    value="<?php echo $r->nrc_pass?>" autocomplete="off"></td>
	                        </tr>
	                        <tr>
	                          <th>Mobile</th>
	                          <td><input type="text" class="form-control" name="mobile" autofocus id="mobile" 
                                    value="<?php echo $r->mobile?>" autocomplete="off"></td>
                          </tr>
	                       <tr>
	                          <th>Email</th>
	                          <td><input type="text" class="form-control" name="email" autofocus id="email" 
                                    value="<?php echo $r->email?>" autocomplete="off"></td>
	                        </tr>
	                        <tr>
	                          <th>Home address</th>
	                          <td><input type="text" class="form-control" name="address" autofocus id="address" 
                                    value="<?php echo $r->address?>" autocomplete="off"></td>
	                        </tr>
	                        <tr>
	                          <th>Highest qualification</th>
	                          <td><input type="text" class="form-control" name="qualification" autofocus id="qualification" 
                                    value="<?php echo $r->qualification?>" autocomplete="off"></td>
                          </tr>
                        </thead>
                      </table>
                      <br>
                      <a class="w3-btn w3-round w3-blue" href="staff.php">Cancel</a>
                      <button class="w3-btn w3-round w3-large w3-green" type="submit" name="update">Update</button>
                      </form>
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
