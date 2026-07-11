<?php
/**
 * Student Account: Set / Reset Password
 *
 * Used for BOTH first-time activation (no row in student_login yet) AND
 * normal password reset (row exists). Identity is verified by Student ID +
 * NRC/Passport matched against the `students` table (admin-managed).
 */

require_once __DIR__ . '/includes/auth_helpers.php';
wuc_secure_session_start();
$csrf_token = wuc_csrf_token();
wuc_security_headers();

const WUC_SETPW_MAX_ATTEMPTS = 5;
const WUC_SETPW_LOCKOUT_SECONDS = 900;
const WUC_PASSWORD_MIN_LENGTH = 8;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_once __DIR__ . '/db/connect.php';

    $sid       = trim((string) ($_POST['Sid'] ?? ''));
    $nrcPass   = trim((string) ($_POST['nrc_pass'] ?? ''));
    $password  = (string) ($_POST['new_password'] ?? '');
    $confirm   = (string) ($_POST['confirm_password'] ?? '');

    if (wuc_is_login_locked($db, 'setpw_student', $sid, WUC_SETPW_MAX_ATTEMPTS, WUC_SETPW_LOCKOUT_SECONDS)) {
        $_SESSION['errorMessage'] = 'Too many attempts. Please try again in 15 minutes.';
        wuc_redirect('studentPasswordReset.php');
    }

    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        wuc_record_failed_login($db, 'setpw_student', $sid);
        $_SESSION['errorMessage'] = 'Security token mismatch. Please refresh and try again.';
        wuc_redirect('studentPasswordReset.php');
    }

    if ($sid === '' || $nrcPass === '' || $password === '' || $confirm === '') {
        $_SESSION['errorMessage'] = 'All fields are required.';
        wuc_redirect('studentPasswordReset.php');
    }

    if (!preg_match('/^[A-Za-z0-9_.@-]{3,50}$/', $sid) || strlen($nrcPass) > 50) {
        wuc_record_failed_login($db, 'setpw_student', $sid);
        $_SESSION['errorMessage'] = 'Invalid Student ID or NRC/Passport.';
        wuc_redirect('studentPasswordReset.php');
    }

    if (strlen($password) < WUC_PASSWORD_MIN_LENGTH) {
        $_SESSION['errorMessage'] = 'Password must be at least ' . WUC_PASSWORD_MIN_LENGTH . ' characters.';
        wuc_redirect('studentPasswordReset.php');
    }

    if ($password !== $confirm) {
        $_SESSION['errorMessage'] = 'Passwords do not match.';
        wuc_redirect('studentPasswordReset.php');
    }

    // Verify identity against admin-managed students table
    $hasNrcColumn = false;
    if ($colRes = $db->query("SHOW COLUMNS FROM students LIKE 'nrc_pass'")) {
        $hasNrcColumn = $colRes->num_rows > 0;
        $colRes->free();
    }
    if (!$hasNrcColumn) {
        error_log('Student set-password blocked: students.nrc_pass column is missing.');
        $_SESSION['errorMessage'] = 'Password reset is not available yet. Please contact the system administrator.';
        wuc_redirect('studentPasswordReset.php');
    }

    $stmt = $db->prepare('SELECT SID, status FROM students WHERE SID = ? AND nrc_pass = ? LIMIT 1');
    if (!$stmt) {
        error_log('Student set-password prepare failed: ' . $db->error);
        $_SESSION['errorMessage'] = 'Service temporarily unavailable. Please try again shortly.';
        wuc_redirect('studentPasswordReset.php');
    }
    $stmt->bind_param('ss', $sid, $nrcPass);
    $stmt->execute();
    $matched = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$matched) {
        wuc_record_failed_login($db, 'setpw_student', $sid);
        $_SESSION['errorMessage'] = 'No student record matches that Student ID and NRC/Passport. Contact the system administrator if this is wrong.';
        wuc_redirect('studentPasswordReset.php');
    }

    $status = strtolower(trim((string) ($matched['status'] ?? 'active')));
    if (in_array($status, ['inactive', 'suspended', 'blocked', 'disabled', 'withdrawn', 'deleted'], true)) {
        $_SESSION['errorMessage'] = 'Your student account is not active. Please contact the system administrator.';
        wuc_redirect('studentPasswordReset.php');
    }

    $matchedSid = (string) $matched['SID'];
    $hashed     = password_hash($password, PASSWORD_DEFAULT);

    // INSERT or UPDATE — works for first-time activation and reset.
    // FIX: also clear must_change_password. The student just chose this
    // password themselves, so forcing another change at next login (the flag
    // is set on admin-issued initial NRC passwords) would be a trap.
    $hasMustChange = false;
    if ($colRes = $db->query("SHOW COLUMNS FROM student_login LIKE 'must_change_password'")) {
        $hasMustChange = $colRes->num_rows > 0;
        $colRes->free();
    }
    $upsert = $db->prepare(
        $hasMustChange
            ? 'INSERT INTO student_login (Sid, Password, must_change_password) VALUES (?, ?, 0)
               ON DUPLICATE KEY UPDATE Password = VALUES(Password), must_change_password = 0'
            : 'INSERT INTO student_login (Sid, Password) VALUES (?, ?)
               ON DUPLICATE KEY UPDATE Password = VALUES(Password)'
    );
    if (!$upsert) {
        error_log('Student set-password upsert prepare failed: ' . $db->error);
        $_SESSION['errorMessage'] = 'Service temporarily unavailable. Please try again shortly.';
        wuc_redirect('studentPasswordReset.php');
    }
    $upsert->bind_param('ss', $matchedSid, $hashed);
    if (!$upsert->execute()) {
        error_log('Student set-password upsert failed: ' . $upsert->error);
        $upsert->close();
        $_SESSION['errorMessage'] = 'Could not save the new password. Please try again.';
        wuc_redirect('studentPasswordReset.php');
    }
    $isFirstTime = $upsert->affected_rows === 1; // 1 = insert, 2 = update on duplicate
    $upsert->close();

    // studentLogin.php verifies users.password — keep RBAC in sync.
    wuc_sync_student_password($db, $matchedSid, $hashed);
    require_once __DIR__ . '/includes/helpers/student_provisioning.php';
    wuc_provision_student_account($db, $matchedSid, [
        'plain_password' => $password,
        'only_create_login' => true,
        'assigned_by' => 'password_reset',
    ]);

    // Confirm the new password is readable from student_login before declaring success.
    $verifyStmt = $db->prepare('SELECT Password FROM student_login WHERE Sid = ? LIMIT 1');
    if (!$verifyStmt) {
        error_log('Student set-password verify prepare failed: ' . $db->error);
        $_SESSION['errorMessage'] = 'Could not save the new password. Please try again.';
        wuc_redirect('studentPasswordReset.php');
    }
    $verifyStmt->bind_param('s', $matchedSid);
    $verifyStmt->execute();
    $verifyRow = $verifyStmt->get_result()->fetch_assoc();
    $verifyStmt->close();
    if (!$verifyRow || !password_verify($password, (string) $verifyRow['Password'])) {
        error_log('Student set-password verify failed for Sid ' . $matchedSid);
        $_SESSION['errorMessage'] = 'Could not save the new password. Please try again.';
        wuc_redirect('studentPasswordReset.php');
    }

    if (wuc_auth_table_exists($db, 'users')) {
        $userVerify = $db->prepare('SELECT password FROM users WHERE student_id = ? OR username = ? LIMIT 1');
        if ($userVerify) {
            $userVerify->bind_param('ss', $matchedSid, $matchedSid);
            $userVerify->execute();
            $userVerify->bind_result($usersPw);
            $hasUsersRow = $userVerify->fetch();
            $userVerify->close();
            if ($hasUsersRow && !password_verify($password, (string) $usersPw)) {
                error_log('Student set-password users.password verify failed for Sid ' . $matchedSid);
                $_SESSION['errorMessage'] = 'Could not save the new password. Please try again.';
                wuc_redirect('studentPasswordReset.php');
            }
        }
    }

    wuc_clear_login_attempts($db, 'setpw_student', $sid);

    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['resetSuccess'] = $isFirstTime
        ? 'Your account has been activated. You can now sign in with your new password.'
        : 'Your password has been updated. You can now sign in with your new password.';

    wuc_redirect('passwordResetSuccess.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Activate your account or reset your password for the Industrial Training Centre student portal.">
    <title>Set / Reset Password | ITC Portal</title>
<?php require_once __DIR__ . '/includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/wuc-premium.css?v=20260613">
    <link rel="stylesheet" href="assets/css/login.css?v=20260702">
    <script src="js/wuc-premium.js?v=20260613" defer></script>
    <style>
        .info-banner {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-left: 3px solid var(--login-accent);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 0.76rem;
            color: rgba(255,255,255,0.7);
            line-height: 1.5;
            margin-bottom: 0.85rem;
        }
        .info-banner strong { color: #fff; }
    </style>
</head>
<body class="login-page reset-page">
    <a href="student_login.php" class="corner-switch" aria-label="Back to student login">
        <i class="fas fa-arrow-left"></i> Student Login
    </a>

    <div class="page-wrapper">
        <div class="login-card">
            <div class="card-logo">
                <img src="images/itc_logo.png" alt="Industrial Training Centre - Ministry of Technology and Science">
            </div>

            <div class="card-header">
                <div class="logo-mark"><i class="fas fa-key"></i></div>
                <div class="card-brand">
                    <h1>Set / Reset Password</h1>
                    <span>First-time activation or password change</span>
                </div>
            </div>

            <div class="info-banner">
                <strong>New here?</strong> Your account is created by the system administrator. Enter your Student ID and NRC/Passport to set your password for the first time, or to reset a forgotten one.
            </div>

            <?php if (!empty($_SESSION['errorMessage'])): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?= htmlspecialchars($_SESSION['errorMessage']) ?></div>
                </div>
                <?php unset($_SESSION['errorMessage']); ?>
            <?php endif; ?>

            <section class="form-section" aria-label="Set or reset password form">
                <form method="post" action="studentPasswordReset.php" id="setpwForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                    <div class="form-group">
                        <label for="Sid">Student ID</label>
                        <div class="input-wrap">
                            <i class="fas fa-id-badge"></i>
                            <input type="text" id="Sid" name="Sid" placeholder="e.g. CSE26456789" required autocomplete="username" minlength="3" maxlength="50" pattern="[A-Za-z0-9_.@\-]{3,50}" title="3-50 letters, digits, or _ . @ -">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="nrc_pass">NRC or Passport Number</label>
                        <div class="input-wrap">
                            <i class="fas fa-id-card"></i>
                            <input type="text" id="nrc_pass" name="nrc_pass" placeholder="Enter your ID number" required maxlength="50">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <div class="input-wrap">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="new_password" name="new_password" placeholder="At least 8 characters" required minlength="8" autocomplete="new-password">
                            <button type="button" class="toggle-pw" id="toggleNew" aria-label="Toggle password visibility"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <div class="input-wrap">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your password" required minlength="8" autocomplete="new-password">
                            <button type="button" class="toggle-pw" id="toggleConfirm" aria-label="Toggle password visibility"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>

                    <button type="submit" class="btn-login">
                        <i class="fas fa-key"></i>&nbsp; Save Password
                    </button>

                    <div class="helper-row">
                        <a href="student_login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
                        <span style="font-size:0.74rem;color:rgba(255,255,255,0.4);">Don't have a record? Contact the system administrator.</span>
                    </div>
                </form>
            </section>

            <p class="footer-note">Ministry of Technology &amp; Science · TEVETA Accredited · Est. 1986 Lusaka, Zambia</p>
        </div>
    </div>

    <script>
        (function () {
            function wireToggle(btnId, inputId) {
                var btn = document.getElementById(btnId);
                var input = document.getElementById(inputId);
                if (!btn || !input) return;
                btn.addEventListener('click', function () {
                    var icon = btn.querySelector('i');
                    input.type = input.type === 'password' ? 'text' : 'password';
                    if (icon) { icon.classList.toggle('fa-eye'); icon.classList.toggle('fa-eye-slash'); }
                });
            }
            wireToggle('toggleNew', 'new_password');
            wireToggle('toggleConfirm', 'confirm_password');

            var form = document.getElementById('setpwForm');
            if (form) {
                form.addEventListener('submit', function (e) {
                    var p1 = document.getElementById('new_password').value;
                    var p2 = document.getElementById('confirm_password').value;
                    if (p1 !== p2) {
                        e.preventDefault();
                        alert('Passwords do not match.');
                    }
                });
            }
        })();
    </script>
</body>
</html>
