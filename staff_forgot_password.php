<?php
/**
 * Staff Account: Set / Reset Password
 *
 * Used for BOTH first-time activation (NULL/empty staff.password) AND
 * normal password reset. Identity is verified by Staff ID + NRC/Passport
 * matched against the admin-managed `staff` table.
 */

require_once __DIR__ . '/includes/auth_helpers.php';
wuc_secure_session_start();
$csrf_token = wuc_csrf_token();
wuc_security_headers();

const WUC_STAFF_SETPW_MAX_ATTEMPTS = 5;
const WUC_STAFF_SETPW_LOCKOUT_SECONDS = 900;
const WUC_STAFF_PASSWORD_MIN_LENGTH = 8;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_once __DIR__ . '/db/connect.php';

    $staffId   = trim((string) ($_POST['user_id'] ?? ''));
    $nrcPass   = trim((string) ($_POST['nrc_pass'] ?? ''));
    $password  = (string) ($_POST['new_password'] ?? '');
    $confirm   = (string) ($_POST['confirm_password'] ?? '');

    if (wuc_is_login_locked($db, 'setpw_staff', $staffId, WUC_STAFF_SETPW_MAX_ATTEMPTS, WUC_STAFF_SETPW_LOCKOUT_SECONDS)) {
        $_SESSION['errorMessage'] = 'Too many attempts. Please try again in 15 minutes.';
        wuc_redirect('staff_forgot_password.php');
    }

    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        wuc_record_failed_login($db, 'setpw_staff', $staffId);
        $_SESSION['errorMessage'] = 'Security token mismatch. Please refresh and try again.';
        wuc_redirect('staff_forgot_password.php');
    }

    if ($staffId === '' || $nrcPass === '' || $password === '' || $confirm === '') {
        $_SESSION['errorMessage'] = 'All fields are required.';
        wuc_redirect('staff_forgot_password.php');
    }

    if (!preg_match('/^[A-Za-z0-9_.@\/-]{3,50}$/', $staffId) || strlen($nrcPass) > 50) {
        wuc_record_failed_login($db, 'setpw_staff', $staffId);
        $_SESSION['errorMessage'] = 'Invalid Staff ID or NRC/Passport.';
        wuc_redirect('staff_forgot_password.php');
    }

    if (strlen($password) < WUC_STAFF_PASSWORD_MIN_LENGTH) {
        $_SESSION['errorMessage'] = 'Password must be at least ' . WUC_STAFF_PASSWORD_MIN_LENGTH . ' characters.';
        wuc_redirect('staff_forgot_password.php');
    }

    if ($password !== $confirm) {
        $_SESSION['errorMessage'] = 'Passwords do not match.';
        wuc_redirect('staff_forgot_password.php');
    }

    $hasNrcColumn = false;
    if ($colRes = $db->query("SHOW COLUMNS FROM staff LIKE 'nrc_pass'")) {
        $hasNrcColumn = $colRes->num_rows > 0;
        $colRes->free();
    }
    if (!$hasNrcColumn) {
        error_log('Staff set-password blocked: staff.nrc_pass column is missing.');
        $_SESSION['errorMessage'] = 'Password reset is not available yet. Please contact the system administrator.';
        wuc_redirect('staff_forgot_password.php');
    }

    $stmt = $db->prepare('SELECT id, staff_id, status FROM staff WHERE staff_id = ? AND nrc_pass = ? LIMIT 1');
    if (!$stmt) {
        error_log('Staff set-password prepare failed: ' . $db->error);
        $_SESSION['errorMessage'] = 'Service temporarily unavailable. Please try again shortly.';
        wuc_redirect('staff_forgot_password.php');
    }
    $stmt->bind_param('ss', $staffId, $nrcPass);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        wuc_record_failed_login($db, 'setpw_staff', $staffId);
        $_SESSION['errorMessage'] = 'No staff record matches that Staff ID and NRC/Passport. Contact the system administrator.';
        wuc_redirect('staff_forgot_password.php');
    }

    $status = strtolower(trim((string) ($user['status'] ?? '')));
    if ($status !== '' && !in_array($status, ['active', 'enabled'], true)) {
        $_SESSION['errorMessage'] = 'Your account is not active. Please contact the system administrator.';
        wuc_redirect('staff_forgot_password.php');
    }

    $hashed  = password_hash($password, PASSWORD_DEFAULT);
    $rowId   = (int) $user['id'];

    $upd = $db->prepare('UPDATE staff SET password = ?, failed_attempts = 0, lockout_until = NULL WHERE id = ?');
    if (!$upd) {
        error_log('Staff set-password update prepare failed: ' . $db->error);
        $_SESSION['errorMessage'] = 'Service temporarily unavailable. Please try again shortly.';
        wuc_redirect('staff_forgot_password.php');
    }
    $upd->bind_param('si', $hashed, $rowId);
    if (!$upd->execute()) {
        error_log('Staff set-password update failed: ' . $upd->error);
        $upd->close();
        $_SESSION['errorMessage'] = 'Could not save the new password. Please try again.';
        wuc_redirect('staff_forgot_password.php');
    }
    $upd->close();

    // staffLogin.php verifies users.password — keep legacy staff.password in sync too.
    wuc_sync_staff_password($db, (string) $user['staff_id'], $hashed, null, $status !== '' ? $status : 'active');

    if (wuc_auth_table_exists($db, 'user_credentials')) {
        $legacyHash = md5($password);
        $credUpd = $db->prepare('UPDATE user_credentials SET pass = ? WHERE staff_id = ?');
        if ($credUpd) {
            $credUpd->bind_param('ss', $legacyHash, $staffId);
            $credUpd->execute();
            $credUpd->close();
        }
    }

    wuc_clear_login_attempts($db, 'setpw_staff', $staffId);

    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['successMssg'] = 'Your password has been saved. You can now sign in.';

    wuc_redirect('staff_forgot_password.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Activate or reset your password for the Industrial Training Centre staff portal.">
    <title>Set / Reset Password | ITC Portal</title>
<?php require_once __DIR__ . '/includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/login.css">
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
    <a href="staff_login.php" class="corner-switch" aria-label="Back to staff login">
        <i class="fas fa-arrow-left"></i> Staff Login
    </a>

    <div class="page-wrapper">
        <div class="login-card">
            <div class="card-logo">
                <img src="images/itc_logo.png" alt="Industrial Training Centre">
            </div>

            <div class="card-header">
                <div class="logo-mark"><i class="fas fa-key"></i></div>
                <div class="card-brand">
                    <h1>Set / Reset Password</h1>
                    <span>First-time activation or password change</span>
                </div>
            </div>

            <div class="info-banner">
                <strong>New staff?</strong> Your account is created by the system administrator. Enter your Staff ID and NRC/Passport to set your password for the first time, or to reset a forgotten one.
            </div>

            <?php if (!empty($_SESSION['errorMessage'])): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?= htmlspecialchars($_SESSION['errorMessage']) ?></div>
                </div>
                <?php unset($_SESSION['errorMessage']); ?>
            <?php endif; ?>

            <?php if (!empty($_SESSION['successMssg'])): ?>
                <div class="alert alert-success" role="alert">
                    <i class="fas fa-check-circle"></i>
                    <div><?= htmlspecialchars($_SESSION['successMssg']) ?></div>
                </div>
                <?php unset($_SESSION['successMssg']); ?>
            <?php endif; ?>

            <section class="form-section" aria-label="Set or reset password form">
                <form method="post" action="staff_forgot_password.php" id="setpwForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                    <div class="form-group">
                        <label for="user_id">Staff ID</label>
                        <div class="input-wrap">
                            <i class="fas fa-user-shield"></i>
                            <input type="text" id="user_id" name="user_id" placeholder="e.g. STAFF-2024-001" required autocomplete="username" minlength="3" maxlength="50" pattern="[A-Za-z0-9_.@\-]{3,50}" title="3-50 letters, digits, or _ . @ -">
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
                        <a href="staff_login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
                        <span style="font-size:0.74rem;color:rgba(255,255,255,0.4);">No record? Contact the system administrator.</span>
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
