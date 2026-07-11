<?php

// POSTed to directly, outside the registrar/staff.php guard chain — must start
// its own session or hasRole() below always sees an empty $_SESSION and 403s.
require_once __DIR__ . '/../includes/auth_helpers.php';
wuc_secure_session_start();

include __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/security.php';
error_reporting(0);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if(!empty($_POST)){
			require_once __DIR__ . '/../includes/role_helpers.php';
			if (!hasRole(ROLE_SYSTEMS_ADMIN)) {
				http_response_code(403);
				exit('Account management is restricted to system administrators.');
			}
			if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
				echo "<script>alert('Invalid security token. Please refresh the page and try again.')</script>";
				echo "<script>window.open('staff.php','_self')</script>";
				exit;
			}
			if(isset($_POST["staff_id"], $_POST["pass"])) {

              	$staff_id = trim($_POST["staff_id"]);
              	$plain = trim((string) $_POST["pass"]);
              	$policy = get_security_policy($db);
              	if (!password_meets_policy($plain, $policy, $policyErr)) {
              		echo "<script>alert('Password policy failed: ".addslashes($policyErr)."')</script>";
              		echo "<script>window.open('staff.php','_self')</script>";
              		exit;
              	}
              	$pass = password_hash($plain, PASSWORD_DEFAULT);

				$check1 = $db->prepare("SELECT staff_id FROM staff WHERE staff_id = ? LIMIT 1");
				if ($check1) {
					$check1->bind_param("s", $staff_id);
					$check1->execute();
					$check1->bind_result($index1);
					$check1->fetch();
					$check1->close();
				}

				if (!isset($index1)) {
						echo"<script>alert('Staff ID does not exist in the database!')</script>";
						echo"<script>window.open('staff.php','_self')</script>";
						die();

					    }

				$check = $db->prepare("SELECT staff_id FROM user_credentials WHERE staff_id = ? LIMIT 1");
				if ($check) {
					$check->bind_param("s", $staff_id);
					$check->execute();
					$check->bind_result($index);
					$check->fetch();
					$check->close();
				}

				if (isset($index)) {
						echo"<script>alert('Staff ID already exist in the database!')</script>";
						echo"<script>window.open('staff.php','_self')</script>";

					    }

				else if (!empty($staff_id) && !empty($pass)) {
						$insert = $db->prepare("INSERT INTO user_credentials (staff_id, pass) VALUE(?,?)");
						$insert ->bind_param("ss", $staff_id, $pass);

						if ($insert->execute()) {
							record_password_history($db, $staff_id, $pass);
							require_once __DIR__ . '/../includes/helpers/staff_provisioning.php';
							$staffRoleStmt = $db->prepare('SELECT role FROM staff WHERE staff_id = ? LIMIT 1');
							$staffRole = 'staff';
							if ($staffRoleStmt) {
								$staffRoleStmt->bind_param('s', $staff_id);
								$staffRoleStmt->execute();
								$staffRoleStmt->bind_result($staffRole);
								$staffRoleStmt->fetch();
								$staffRoleStmt->close();
							}
							wuc_provision_staff_account($db, $staff_id, wuc_normalize_staff_role((string)$staffRole), $plain, (string)($_SESSION['staff_id'] ?? 'registrar'));
                            echo "<script>alert('Staff account created successful!')</script>";
							echo"<script>window.open('staff.php','_self')</script>";

							}
						}
						else {
							echo "<script>alert('Staff registration Failed!')</script>";
							echo"<script>window.open('staff.php','_self')</script>";

						}

				}
		      }

?>
<!DOCTYPE html>
<html>
<head>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>   
<body>
  <div id="staffAccount" class="w3-modal" style="display:none">
    <div class="w3-modal-content w3-animate-zoom w3-card-8">
      <header class="w3-container w3-blue">
        <span onclick="document.getElementById('staffAccount').style.display='none'"
        class="w3-closebtn">×</span>
        <h3 class="w3-center">Create staff account</h3>
      </header>
      <div class="jumbotron">
				<div class="w3-container">
					<div class="row">
					<form action="createAccount.php" method="post" class="form-inline w3-container" role="form">
						<input type="hidden" name="csrf_token" value="<?php if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } echo htmlspecialchars($_SESSION['csrf_token']); ?>">
						<div class="form-group">
					    		<lable for="staff_id">Staff number:</lable><br>
		                            <input type="text" class="form-control w3-input w3-border w3-sand" name="staff_id" autofocus id="staff_id" 
		                             autocomplete="off" required>

					    	</div>
					    	<div class="form-group">
					    		<lable for="pass">Password:</lable><br>
		                            <input type="password" class="form-control w3-input w3-border w3-sand" name="pass" autofocus id="pass" 
		                            autocomplete="off" required>

					    	</div><br><br>
					    	<div class="form-group">
									<!--input type="submit" value="Submit"-->
									<button class="btn btn-sm  btn-block w3-btn w3-green form-control" type="submit" name="submit">Create account</button>
							</div>
					</form>
					</div>
				</div>
			</div>
  </div>
</body>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>
