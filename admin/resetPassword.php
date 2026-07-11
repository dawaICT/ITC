<?php
/**
 * Reset Staff Password - Admin Module
 * Follows admin include chain: header.php (→ admin.php → connect.php → nav.php → nav_unified.php) → footer.php
 */

// ===== STEP 1: SESSION & AUTH (admin.php handles session_start) =====
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/../includes/security.php';

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");

// ===== STEP 2: POST HANDLER (before any HTML output) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['staff_id'], $_POST['pass'])) {
    
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['NoAccount'] = 'Security token mismatch. Please try again.';
        header('Location: resetPassword.php');
        exit;
    }
    
    $staff_id = trim($_POST['staff_id']);
    $plain = trim($_POST['pass']);
    $confirm = trim($_POST['confirmPass'] ?? '');
    
    // Server-side confirm password check (don't trust JS only)
    if ($plain !== $confirm) {
        $_SESSION['NoAccount'] = 'Passwords do not match.';
        header('Location: resetPassword.php');
        exit;
    }
    
    // Validate against security policy
    $policy = get_security_policy($db);
    if (!password_meets_policy($plain, $policy, $err)) {
        $_SESSION['NoAccount'] = 'Password policy failed: ' . $err;
        header('Location: resetPassword.php');
        exit;
    }
    
    // Ensure staff exists and get internal ID
    $checkStaff = $db->prepare("SELECT id, staff_id FROM staff WHERE staff_id = ? LIMIT 1");
    $checkStaff->bind_param('s', $staff_id);
    $checkStaff->execute();
    $res = $checkStaff->get_result();
    
    if ($res->num_rows === 0) {
        $checkStaff->close();
        $_SESSION['NoStaff'] = 'This staff ID does not exist in the system.';
        header('Location: resetPassword.php');
        exit;
    }
    
    $staffRow = $res->fetch_assoc();
    $internal_id = $staffRow['id'];
    $checkStaff->close();
    
    // Hash the new password (BCRYPT for staff table, MD5 for legacy user_credentials)
    $bcrypt_hash = password_hash($plain, PASSWORD_BCRYPT);
    $md5_hash = md5($plain);
    
    // Check for password reuse in history
    $historyCount = (int)($policy['history_count'] ?? 5);
    $stmt_hist = $db->prepare("SELECT password_hash FROM password_history WHERE staff_id = ? ORDER BY created_at DESC LIMIT ?");
    if ($stmt_hist) {
        $stmt_hist->bind_param('si', $staff_id, $historyCount);
        $stmt_hist->execute();
        $hist_res = $stmt_hist->get_result();
        while ($row = $hist_res->fetch_assoc()) {
            if (password_verify($plain, $row['password_hash'])) {
                $stmt_hist->close();
                $_SESSION['NoAccount'] = 'New password cannot match any of your last ' . $historyCount . ' passwords.';
                header('Location: resetPassword.php');
                exit;
            }
        }
        $stmt_hist->close();
    }
    
    // Begin transaction
    $db->begin_transaction();

    try {
        // 1. Update MAIN staff table (used for login)
        $updateStaff = $db->prepare("UPDATE staff SET password = ? WHERE id = ?");
        if (!$updateStaff) throw new Exception("Prepare failed: " . $db->error);
        $updateStaff->bind_param('si', $bcrypt_hash, $internal_id);
        if (!$updateStaff->execute()) throw new Exception("Execute failed: " . $updateStaff->error);
        $updateStaff->close();

        // 2. Update LEGACY user_credentials table (if entry exists)
        // We use MD5 here because debug_pw.php showed this table uses MD5
        $checkCreds = $db->prepare("SELECT id FROM user_credentials WHERE staff_id = ?");
        $checkCreds->bind_param('s', $staff_id);
        $checkCreds->execute();
        if ($checkCreds->get_result()->num_rows > 0) {
            $updateCreds = $db->prepare("UPDATE user_credentials SET pass = ? WHERE staff_id = ?");
            $updateCreds->bind_param('ss', $md5_hash, $staff_id);
            $updateCreds->execute();
            $updateCreds->close();
        } else {
            // Optional: Insert if missing, but we'll skipped it to avoid side effects
            // $insertCreds = $db->prepare("INSERT INTO user_credentials (staff_id, pass) VALUES (?, ?)");
            // ...
        }
        $checkCreds->close();

        // 3. Record history
        record_password_history($db, $staff_id, $bcrypt_hash);

        $db->commit();
        
        $_SESSION['resetSuccess'] = 'Password for <strong>' . htmlspecialchars($staff_id) . '</strong> has been successfully updated.';
        header('Location: resetPassword.php');
        exit;

    } catch (Exception $e) {
        $db->rollback();
        error_log("Password Reset Error: " . $e->getMessage());
        $_SESSION['NoAccount'] = 'System error: Failed to update password. Please try again.';
        header('Location: resetPassword.php');
        exit;
    }
}

// ===== STEP 3: LOAD DYNAMIC POLICY FOR DISPLAY =====
$policy = get_security_policy($db);

// ===== STEP 4: PAGE RENDER =====
$page_title = "Reset Staff Password";
require_once __DIR__ . '/includes/header.php';
?>

<!-- Main Content -->
<div class="container-fluid px-4 portal-dashboard">
    
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-key me-2 text-primary"></i>Reset Staff Password</h5>
                <p class="page-subtitle mb-0">Reset passwords for existing staff accounts with security policy enforcement</p>
            </div>
            <div class="header-actions">
                <a href="staff.php" class="btn btn-outline-primary shadow-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back to Staff
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">
            <div class="card border-0 shadow-sm data-table-card">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="fas fa-key me-2 text-primary"></i>Reset Password</h5>
                </div>
                <div class="card-body p-4">
                    
                    <!-- Alert Messages -->
                    <?php if (isset($_SESSION['resetSuccess'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i>
                            <?= htmlspecialchars($_SESSION['resetSuccess']) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['resetSuccess']); ?>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['NoStaff'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?= htmlspecialchars($_SESSION['NoStaff']) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['NoStaff']); ?>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['NoAccount'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?= htmlspecialchars($_SESSION['NoAccount']) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['NoAccount']); ?>
                    <?php endif; ?>

                    <!-- Password Reset Form -->
                    <form action="resetPassword.php" method="POST" id="passwordResetForm" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        
                        <div class="mb-3">
                            <label for="staff_id" class="form-label fw-semibold">
                                <i class="fas fa-id-badge me-2"></i>Staff ID
                            </label>
                            <input type="text" class="form-control form-control-lg" id="staff_id" name="staff_id"
                                   placeholder="Enter staff ID (e.g., ITC001)" required
                                   pattern="^[A-Za-z0-9]{3,20}$"
                                   title="Enter a valid staff ID">
                            <div class="form-text">Enter the staff ID for the account you want to reset</div>
                        </div>

                        <div class="mb-3">
                            <label for="pass" class="form-label fw-semibold">
                                <i class="fas fa-lock me-2"></i>New Password
                            </label>
                            <div class="input-group">
                                <input type="password" class="form-control form-control-lg" id="pass" name="pass"
                                       placeholder="Enter new password" required minlength="<?= (int)($policy['min_length'] ?? 8) ?>"
                                       title="Password must meet the security policy requirements">
                                <button class="btn btn-outline-secondary" type="button" id="togglePass" tabindex="-1">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="confirmPass" class="form-label fw-semibold">
                                <i class="fas fa-lock me-2"></i>Confirm Password
                            </label>
                            <div class="input-group">
                                <input type="password" class="form-control form-control-lg" id="confirmPass" name="confirmPass"
                                       placeholder="Confirm new password" required minlength="<?= (int)($policy['min_length'] ?? 8) ?>">
                                <button class="btn btn-outline-secondary" type="button" id="toggleConfirm" tabindex="-1">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div id="matchFeedback" class="form-text"></div>
                        </div>

                        <!-- Password Strength Indicator -->
                        <div class="mb-3" id="passwordStrength" style="display: none;">
                            <small class="text-muted">Password strength: <span id="strengthText" class="fw-bold"></span></small>
                            <div class="progress mt-1" style="height: 6px;">
                                <div class="progress-bar" id="strengthBar" role="progressbar"></div>
                            </div>
                        </div>

                        <!-- Policy Checklist (live) -->
                        <div class="mb-4 p-3 bg-light rounded" id="policyChecklist">
                            <small class="fw-bold text-muted d-block mb-2">Requirements:</small>
                            <div class="row small">
                                <div class="col-6"><span id="checkLen" class="text-muted"><i class="fas fa-times-circle me-1"></i>Min <?= (int)($policy['min_length'] ?? 8) ?> characters</span></div>
                                <div class="col-6"><span id="checkUpper" class="text-muted"><i class="fas fa-times-circle me-1"></i>Uppercase letter</span></div>
                                <div class="col-6"><span id="checkLower" class="text-muted"><i class="fas fa-times-circle me-1"></i>Lowercase letter</span></div>
                                <div class="col-6"><span id="checkDigit" class="text-muted"><i class="fas fa-times-circle me-1"></i>Number</span></div>
                                <div class="col-6"><span id="checkSpecial" class="text-muted"><i class="fas fa-times-circle me-1"></i>Special character</span></div>
                                <div class="col-6"><span id="checkMatch" class="text-muted"><i class="fas fa-times-circle me-1"></i>Passwords match</span></div>
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                <i class="fas fa-key me-2"></i>Reset Password
                            </button>
                            <a href="staff.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Security Policy Info -->
            <div class="card mt-4 border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="card-title"><i class="fas fa-shield-alt me-2 text-primary"></i>Password Security Policy</h6>
                    <ul class="list-unstyled mb-0 small">
                        <li><i class="fas fa-check text-success me-2"></i>Minimum <?= (int)($policy['min_length'] ?? 8) ?> characters</li>
                        <?php if (!empty($policy['require_uppercase'])): ?>
                            <li><i class="fas fa-check text-success me-2"></i>At least one uppercase letter</li>
                        <?php endif; ?>
                        <?php if (!empty($policy['require_lowercase'])): ?>
                            <li><i class="fas fa-check text-success me-2"></i>At least one lowercase letter</li>
                        <?php endif; ?>
                        <?php if (!empty($policy['require_digit'])): ?>
                            <li><i class="fas fa-check text-success me-2"></i>At least one number</li>
                        <?php endif; ?>
                        <?php if (!empty($policy['require_special'])): ?>
                            <li><i class="fas fa-check text-success me-2"></i>At least one special character</li>
                        <?php endif; ?>
                        <li><i class="fas fa-check text-success me-2"></i>Cannot reuse last <?= (int)($policy['history_count'] ?? 5) ?> passwords</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const minLength = <?= (int)($policy['min_length'] ?? 8) ?>;

// Password visibility toggles
document.getElementById('togglePass').addEventListener('click', function() {
    toggleVisibility('pass', this);
});
document.getElementById('toggleConfirm').addEventListener('click', function() {
    toggleVisibility('confirmPass', this);
});

function toggleVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

// Live password validation
const passInput = document.getElementById('pass');
const confirmInput = document.getElementById('confirmPass');

passInput.addEventListener('input', validatePassword);
confirmInput.addEventListener('input', validatePassword);

function validatePassword() {
    const password = passInput.value;
    const confirm = confirmInput.value;
    
    const strengthIndicator = document.getElementById('passwordStrength');
    const strengthText = document.getElementById('strengthText');
    const strengthBar = document.getElementById('strengthBar');
    
    // Policy checks
    const hasLen = password.length >= minLength;
    const hasUpper = /[A-Z]/.test(password);
    const hasLower = /[a-z]/.test(password);
    const hasDigit = /\d/.test(password);
    const hasSpecial = /[^A-Za-z0-9]/.test(password);
    const matches = password.length > 0 && confirm.length > 0 && password === confirm;
    
    updateCheck('checkLen', hasLen);
    updateCheck('checkUpper', hasUpper);
    updateCheck('checkLower', hasLower);
    updateCheck('checkDigit', hasDigit);
    updateCheck('checkSpecial', hasSpecial);
    updateCheck('checkMatch', matches);
    
    // Strength indicator
    if (password.length === 0) {
        strengthIndicator.style.display = 'none';
        return;
    }
    
    strengthIndicator.style.display = 'block';
    let score = [hasLen, hasUpper, hasLower, hasDigit, hasSpecial].filter(Boolean).length;
    
    const levels = [
        { text: 'Very Weak', color: '#dc3545', width: '20%' },
        { text: 'Weak', color: '#fd7e14', width: '40%' },
        { text: 'Fair', color: '#ffc107', width: '60%' },
        { text: 'Good', color: '#28a745', width: '80%' },
        { text: 'Strong', color: '#20c997', width: '100%' }
    ];
    
    const level = levels[Math.max(0, score - 1)] || levels[0];
    strengthText.textContent = level.text;
    strengthBar.style.width = level.width;
    strengthBar.style.backgroundColor = level.color;
    
    // Match feedback
    const matchFeedback = document.getElementById('matchFeedback');
    if (confirm.length > 0) {
        if (matches) {
            matchFeedback.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>Passwords match</span>';
        } else {
            matchFeedback.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle me-1"></i>Passwords do not match</span>';
        }
    } else {
        matchFeedback.innerHTML = '';
    }
}

function updateCheck(id, passed) {
    const el = document.getElementById(id);
    if (!el) return;
    const icon = el.querySelector('i');
    if (passed) {
        el.className = 'text-success';
        icon.className = 'fas fa-check-circle me-1';
    } else {
        el.className = 'text-muted';
        icon.className = 'fas fa-times-circle me-1';
    }
}

// Form submission validation
document.getElementById('passwordResetForm').addEventListener('submit', function(e) {
    const password = passInput.value;
    const confirm = confirmInput.value;
    const staffId = document.getElementById('staff_id').value.trim();
    
    if (!staffId) {
        e.preventDefault();
        Swal.fire({ icon: 'error', title: 'Staff ID Required', text: 'Please enter the staff ID.' });
        return;
    }
    
    if (password !== confirm) {
        e.preventDefault();
        Swal.fire({ icon: 'error', title: 'Password Mismatch', text: 'Passwords do not match. Please try again.' });
        return;
    }
    
    if (password.length < minLength) {
        e.preventDefault();
        Swal.fire({ icon: 'error', title: 'Password Too Short', text: 'Password must be at least ' + minLength + ' characters long.' });
        return;
    }
    
    if (!/[A-Z]/.test(password) || !/[a-z]/.test(password) || !/\d/.test(password) || !/[^A-Za-z0-9]/.test(password)) {
        e.preventDefault();
        Swal.fire({ icon: 'error', title: 'Password Requirements', text: 'Password must contain uppercase, lowercase, number, and special character.' });
        return;
    }
    
    // Show loading state
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Resetting...';
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

