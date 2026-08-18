<?php
require_once __DIR__ . '/includes/guard.php'; // session, login enforcement, $db, csrf token
require_once dirname(__DIR__) . '/includes/auth_helpers.php';

$studentId = (string)($_SESSION['Sid'] ?? '');
$error = '';
$mustChange = !empty($_SESSION['must_change_password']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$pageCsrf = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token   = $_POST['csrf_token'] ?? '';
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Security token mismatch. Please try again.';
    } elseif ($current === '' || $new === '' || $confirm === '') {
        $error = 'All fields are required.';
    } elseif (strlen($new) < 8) {
        $error = 'Your new password must be at least 8 characters long.';
    } elseif ($new !== $confirm) {
        $error = 'The new password and confirmation do not match.';
    } else {
        $stmt = $db->prepare('SELECT Password FROM student_login WHERE Sid = ? LIMIT 1');
        $hash = '';
        if ($stmt) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $hash = (string)($row['Password'] ?? '');
            }
            $stmt->close();
        }

        if ($hash === '' || !password_verify($current, $hash)) {
            $error = 'Your current password is incorrect.';
        } elseif (password_verify($new, $hash)) {
            $error = 'Please choose a password different from your current one.';
        } else {
            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $upd = $db->prepare('UPDATE student_login SET Password = ?, must_change_password = 0 WHERE Sid = ?');
            if ($upd) {
                $upd->bind_param('ss', $newHash, $studentId);
                $upd->execute();
                $upd->close();
            }
            // studentLogin.php authenticates against users.password first. Keep
            // both credential stores aligned so the old initial password stops
            // working immediately after this change.
            wuc_sync_student_password($db, $studentId, $newHash);
            unset($_SESSION['must_change_password']);
            $_SESSION['loginStudent'] = 'Your password has been updated successfully.';
            // Honour the destination the user was originally heading to (e.g. the
            // parallel eLearning login), falling back to the dashboard.
            $pcNext = (string) ($_SESSION['post_password_change_next'] ?? '');
            unset($_SESSION['post_password_change_next']);
            if ($pcNext === 'students/elearning/index.php' || strpos($pcNext, 'students/elearning/') === 0) {
                $_SESSION['current_portal'] = 'elearning';
                header('Location: /wucportal/students/elearning/index.php');
            } else {
                require_once dirname(__DIR__) . '/includes/student_program_portal.php';
                header('Location: ' . wuc_student_program_portal_url($db, (string)$_SESSION['Sid']));
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - ITC</title>
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/css/wuc-premium.css?v=20260613">
    <script src="/wucportal/js/wuc-premium.js?v=20260613" defer></script>
    <style>
        body { font-family: 'Inter', 'Segoe UI', system-ui, sans-serif; background: #f6f7fb; }
        .card { animation: wucPageIn 360ms cubic-bezier(0.22,1,0.36,1) both; }
    </style>
</head>
<body class="bg-light">
    <div class="d-flex align-items-center justify-content-center" style="min-height: 100vh; padding: 1rem;">
        <div class="card border-0 shadow-sm" style="max-width: 460px; width: 100%; border-radius: 14px;">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center mb-3"
                         style="width:56px;height:56px;border-radius:50%;background:#fef3c7;color:#92400e;">
                        <i class="fas fa-key fa-lg"></i>
                    </div>
                    <h1 class="h4 fw-bold text-dark mb-1">Change Your Password</h1>
                    <p class="text-secondary mb-0">
                        <?php if ($mustChange): ?>
                            For your security, please set a new password before continuing.
                        <?php else: ?>
                            Update the password for your account.
                        <?php endif; ?>
                    </p>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
                        <i class="fas fa-exclamation-circle mt-1"></i>
                        <div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($pageCsrf, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="mb-3">
                        <label for="current_password" class="form-label fw-semibold">Current Password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password">
                    </div>
                    <div class="mb-3">
                        <label for="new_password" class="form-label fw-semibold">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" minlength="8" required autocomplete="new-password">
                        <div class="form-text">At least 8 characters, and different from your current password.</div>
                    </div>
                    <div class="mb-4">
                        <label for="confirm_password" class="form-label fw-semibold">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="8" required autocomplete="new-password">
                    </div>

                    <button type="submit" class="btn btn-dark w-100" style="border-radius:10px;">
                        <i class="fas fa-check me-1"></i> Update Password
                    </button>
                </form>

                <?php if (!$mustChange): ?>
                    <div class="text-center mt-3">
                        <a href="index.php" class="text-secondary small"><i class="fas fa-arrow-left me-1"></i> Back to Dashboard</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
