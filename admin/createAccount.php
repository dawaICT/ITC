<?php
// Creates a staff login credential — a privileged action. Must run behind the
// admin session guard with CSRF, never as an open POST endpoint.
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth_helpers.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        $_SESSION['errorMssg'] = 'Request verification failed. Please refresh and try again.';
        header('Location: staff.php');
        exit;
    }
    // Only systems administrators may mint staff logins.
    if (!in_array(ROLE_SYSTEMS_ADMIN, (array)($_SESSION['all_roles'] ?? []), true)) {
        http_response_code(403);
        $_SESSION['errorMssg'] = 'You are not authorised to create staff accounts.';
        header('Location: staff.php');
        exit;
    }
    if (isset($_POST['staff_id'], $_POST['pass'])) {
        $staff_id = trim($_POST['staff_id']);
        $plain = trim($_POST['pass']);
        $policy = get_security_policy($db);
        if (!password_meets_policy($plain, $policy, $err)) {
            $_SESSION['errorMssg'] = 'Password policy failed: ' . $err;
            header('Location: staff.php');
            exit;
        }
        $pass = password_hash($plain, PASSWORD_BCRYPT);

        // Ensure staff exists
        $checkStaff = $db->prepare('SELECT staff_id FROM staff WHERE staff_id = ? LIMIT 1');
        $checkStaff->bind_param('s', $staff_id);
        $checkStaff->execute();
        $staffResult = $checkStaff->get_result();
        if ($staffResult->num_rows === 0) {
            $_SESSION['errorMssg'] = 'Staff ID does not exist in the database!';
            header('Location: staff.php');
            exit;
        }

        // Ensure not duplicate account
        $checkAccount = $db->prepare('SELECT staff_id FROM user_credentials WHERE staff_id = ? LIMIT 1');
        $checkAccount->bind_param('s', $staff_id);
        $checkAccount->execute();
        $acctResult = $checkAccount->get_result();
        if ($acctResult->num_rows > 0) {
            $_SESSION['errorMssg'] = 'Staff ID already has an account!';
            header('Location: staff.php');
            exit;
        }

        $insert = $db->prepare('INSERT INTO user_credentials (staff_id, pass) VALUE(?, ?)');
        $insert->bind_param('ss', $staff_id, $pass);
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
            wuc_provision_staff_account($db, $staff_id, wuc_normalize_staff_role((string)$staffRole), $plain, (string)($_SESSION['staff_id'] ?? 'admin'));
            $_SESSION['successMssg'] = 'Staff account created successfully!';
            header('Location: staff.php');
            exit;
        }

        $_SESSION['errorMssg'] = 'Staff account creation failed!';
        header('Location: staff.php');
        exit;
    }
}
?>
<?php // No UI; handled via Bootstrap modal in staff.php ?>