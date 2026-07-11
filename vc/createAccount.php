<?php
/**
 * VC staff credential creation — mirrors admin/createAccount.php provisioning.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth_helpers.php';
wuc_secure_session_start();

include __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/role_helpers.php';
error_reporting(0);

// Minting/resetting a staff login credential is privileged: it can set any
// existing staff member's password (via wuc_provision_staff_account) and create
// their user_credentials row. This endpoint is POSTed to directly, so it MUST
// enforce its own authorization — previously it had none, allowing any visitor
// with a self-issued CSRF token to take over any staff account.
if (!empty($_POST) && !hasRole(ROLE_SYSTEMS_ADMIN)) {
    http_response_code(403);
    exit('Account management is restricted to system administrators.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!empty($_POST) && isset($_POST['staff_id'], $_POST['pass'])) {
    $staff_id = trim((string)$_POST['staff_id']);
    $plain = trim((string)$_POST['pass']);

    if (!isset($_POST['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        echo "<script>alert('Invalid security token. Please refresh and try again.')</script>";
        echo "<script>window.open('staff.php','_self')</script>";
        exit;
    }

    $policy = get_security_policy($db);
    if (!password_meets_policy($plain, $policy, $policyErr)) {
        echo "<script>alert('Password policy failed: " . addslashes($policyErr) . "')</script>";
        echo "<script>window.open('staff.php','_self')</script>";
        exit;
    }

    $pass = password_hash($plain, PASSWORD_DEFAULT);

    $check1 = $db->prepare('SELECT staff_id FROM staff WHERE staff_id = ? LIMIT 1');
    $staffExists = false;
    if ($check1) {
        $check1->bind_param('s', $staff_id);
        $check1->execute();
        $check1->store_result();
        $staffExists = $check1->num_rows > 0;
        $check1->close();
    }
    if (!$staffExists) {
        echo "<script>alert('Staff ID does not exist in the database!')</script>";
        echo "<script>window.open('staff.php','_self')</script>";
        exit;
    }

    $check = $db->prepare('SELECT staff_id FROM user_credentials WHERE staff_id = ? LIMIT 1');
    $hasCreds = false;
    if ($check) {
        $check->bind_param('s', $staff_id);
        $check->execute();
        $check->store_result();
        $hasCreds = $check->num_rows > 0;
        $check->close();
    }
    if ($hasCreds) {
        echo "<script>alert('Staff ID already has an account!')</script>";
        echo "<script>window.open('staff.php','_self')</script>";
        exit;
    }

    $insert = $db->prepare('INSERT INTO user_credentials (staff_id, pass) VALUE(?, ?)');
    if ($insert) {
        $insert->bind_param('ss', $staff_id, $pass);
        if ($insert->execute()) {
            record_password_history($db, $staff_id, $pass);
            require_once __DIR__ . '/../includes/helpers/staff_provisioning.php';
            $staffRole = 'staff';
            if ($staffRoleStmt = $db->prepare('SELECT role FROM staff WHERE staff_id = ? LIMIT 1')) {
                $staffRoleStmt->bind_param('s', $staff_id);
                $staffRoleStmt->execute();
                $staffRoleStmt->bind_result($staffRole);
                $staffRoleStmt->fetch();
                $staffRoleStmt->close();
            }
            $provision = wuc_provision_staff_account(
                $db,
                $staff_id,
                wuc_normalize_staff_role((string)$staffRole),
                $plain,
                (string)($_SESSION['staff_id'] ?? 'vc')
            );
            if (!$provision['ok']) {
                error_log('vc/createAccount.php provision failed: ' . implode('; ', $provision['messages']));
                echo "<script>alert('Account created but provisioning incomplete. Contact administrator.')</script>";
            } else {
                echo "<script>alert('Staff account created successfully!')</script>";
            }
            echo "<script>window.open('staff.php','_self')</script>";
            exit;
        }
        $insert->close();
    }

    echo "<script>alert('Staff registration failed!')</script>";
    echo "<script>window.open('staff.php','_self')</script>";
    exit;
}

?>
<!DOCTYPE html>
<html>
<head>
  <link rel="stylesheet" href="assets/vendor/sweetalert2/dist/sweetalert2.min.css">
</head>   
<body>
  <div id="staffAccount" class="w3-modal">
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
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
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
									<button class="btn btn-sm  btn-block w3-btn w3-green form-control" type="submit" name="submit">Create account</button>
							</div>
					</form>
					</div>
				</div>
			</div>
  </div>
</body>
<script src="assets/vendor/sweetalert2/dist/sweetalert2.min.js"></script>
</html>
