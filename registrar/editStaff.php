<?php
$page_title = 'Edit Staff';
include "includes/admin.php";
require_once dirname(__DIR__) . '/includes/role_helpers.php';
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
wuc_require_systems_admin('/wucportal/portal_selection.php');
error_reporting(0);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if(isset($_POST['update'])){
    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        echo "<script>alert('Invalid or expired form token. Please try again.')</script>";
        echo "<script>window.open('staff.php','_self')</script>";
        exit;
    }

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

    $stmt = $db->prepare(
        'UPDATE staff SET deptId = ?, title = ?, Fname = ?, Lname = ?, sex = ?, country = ?, nrc_pass = ?, mobile = ?, email = ?, address = ?, qualification = ? WHERE staff_id = ?'
    );
    if ($stmt) {
        $stmt->bind_param('ssssssssssss', $deptId, $title, $Fname, $Lname, $sex, $country, $nrc_pass, $mobile, $email, $address, $qualification, $staff_id);
        $result = $stmt->execute();
        $stmt->close();
    } else {
        $result = false;
    }

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
							$update = trim($_GET['update']);
                            $records_1 = [];
                            if ($stmt = $db->prepare('SELECT * FROM staff WHERE staff_id = ?')) {
                                $stmt->bind_param('s', $update);
                                $stmt->execute();
                                $results = $stmt->get_result();
                                if ($results && $results->num_rows) {
                                    while ($row = $results->fetch_object()) {
                                        $records_1[] = $row;
                                    }
                                }
                                $stmt->close();
                            }
							}

                    ?>

                    <?php
						foreach($records_1 as $r) {
					?>
					<h3 class="w3-center"><strong>Edit Staff Personal Information</strong></h3>
                    <form action="editStaff.php" method="post" class="#" role="form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
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
