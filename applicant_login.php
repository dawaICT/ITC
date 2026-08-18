<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth_helpers.php';
wuc_secure_session_start();
wuc_security_headers();

require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/includes/login_activity_logger.php';
require_once __DIR__ . '/includes/portal_access.php';

const WUC_APPLICANT_MAX_LOGIN_ATTEMPTS = 5;
const WUC_APPLICANT_LOCKOUT_SECONDS = 900;

$error = '';
$username = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Security token mismatch. Refresh the page and try again.';
    } elseif (!filter_var($username, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Enter the email address and password used on your application.';
    } elseif (wuc_is_login_locked($db, 'applicant', $username, WUC_APPLICANT_MAX_LOGIN_ATTEMPTS, WUC_APPLICANT_LOCKOUT_SECONDS)) {
        $error = 'Too many failed attempts. Try again in 15 minutes.';
    } else {
        $stmt = $db->prepare(
            "SELECT u.user_id, u.username, u.password, u.status, COALESCE(up.display_name, '') display_name
               FROM users u
               LEFT JOIN user_profiles up ON up.user_id = u.user_id
              WHERE LOWER(u.username) = ? AND u.primary_role = 'applicant'
              LIMIT 1"
        );
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        $storedHash = (string)($account['password'] ?? password_hash('invalid', PASSWORD_DEFAULT));
        [$passwordOk, $needsRehash] = wuc_password_verify_legacy($password, $storedHash);
        if (!$account || !$passwordOk || strtolower((string)$account['status']) !== 'active') {
            wuc_record_failed_login($db, 'applicant', $username);
            $error = 'Invalid Applicant Portal email or password.';
        } else {
            $userId = (int)$account['user_id'];
            if ($needsRehash) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $update = $db->prepare('UPDATE users SET password = ? WHERE user_id = ?');
                $update->bind_param('si', $newHash, $userId);
                $update->execute();
                $update->close();
            }

            wuc_clear_login_attempts($db, 'applicant', $username);
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['user_id'] = $username;
            $_SESSION['username'] = $username;
            $_SESSION['login_username'] = $username;
            $_SESSION['user_id_db'] = $userId;
            $_SESSION['user_name'] = trim((string)$account['display_name']) ?: $username;
            $_SESSION['role'] = 'applicant';
            $_SESSION['user_role'] = 'applicant';
            $_SESSION['logged_in'] = true;
            $_SESSION['last_activity'] = time();
            wuc_log_login($db, $username, 'applicant', (string)$_SESSION['user_name']);
            wuc_redirect(wuc_after_login_portal_url($db, $userId, 'applicant'));
        }
    }
}

$csrfToken = wuc_csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Applicant Portal Login | ITC</title>
    <?php require_once __DIR__ . '/includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/wucportal/css/wuc-premium.css">
    <style>
        body { min-height: 100vh; display: grid; place-items: center; background: linear-gradient(135deg, #f7f3ff, #eef2ff); font-family: Inter, sans-serif; }
        .login-shell { width: min(92vw, 460px); }
        .login-card { border: 0; border-radius: 18px; box-shadow: 0 18px 55px rgba(40, 24, 75, .14); overflow: hidden; }
        .login-head { background: #6f42c1; color: #fff; padding: 1.6rem; }
        .btn-purple { background: #6f42c1; border-color: #6f42c1; color: #fff; }
        .btn-purple:hover { background: #5a32a3; border-color: #5a32a3; color: #fff; }
    </style>
</head>
<body>
<main class="login-shell">
    <div class="card login-card">
        <div class="login-head">
            <div class="d-flex align-items-center gap-3">
                <i class="fa-solid fa-file-signature fa-2x"></i>
                <div><h1 class="h4 mb-1">Applicant Portal</h1><p class="mb-0 text-white-50">Track your admission application securely.</p></div>
            </div>
        </div>
        <div class="card-body p-4">
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="mb-3">
                    <label for="username" class="form-label fw-semibold">Application email</label>
                    <input type="email" class="form-control" id="username" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required autofocus>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label fw-semibold">Password</label>
                    <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                </div>
                <button class="btn btn-purple w-100 py-2" type="submit"><i class="fa-solid fa-arrow-right-to-bracket me-2"></i>Sign in</button>
            </form>
            <div class="d-flex justify-content-between gap-3 mt-4 small">
                <a href="/wucportal/online_services/index.php">Start an application</a>
                <a href="/wucportal/index.php">Back to portal</a>
            </div>
        </div>
    </div>
</main>
</body>
</html>
