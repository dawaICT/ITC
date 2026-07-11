<?php
require_once __DIR__ . '/includes/auth_helpers.php';
wuc_secure_session_start();
wuc_security_headers();

$resetSuccess = $_SESSION['resetSuccess'] ?? null;
unset($_SESSION['resetSuccess']);

$state = $resetSuccess ? 'success' : 'empty';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset | ITC Portal</title>
<?php require_once __DIR__ . '/includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/wuc-premium.css?v=20260613">
    <link rel="stylesheet" href="assets/css/login.css?v=20260702">
    <script src="js/wuc-premium.js?v=20260613" defer></script>
    <style>
        .reset-page .login-card { text-align: center; }
        .result-icon {
            width: 64px; height: 64px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 0.85rem;
            font-size: 1.7rem;
        }
        .result-icon.success {
            background: rgba(45, 200, 130, 0.16);
            color: #5ee5a5;
            box-shadow: 0 0 32px rgba(45, 200, 130, 0.18);
        }
        .result-icon.error {
            background: rgba(230, 57, 80, 0.18);
            color: var(--login-accent);
            box-shadow: 0 0 32px rgba(230, 57, 80, 0.22);
        }
        .result-title {
            font-size: 1.15rem; font-weight: 800; color: #fff;
            margin-bottom: 0.4rem; letter-spacing: -0.01em;
        }
        .result-message {
            font-size: 0.88rem; color: rgba(255,255,255,0.75);
            line-height: 1.55; margin-bottom: 1.1rem;
            word-break: break-word;
        }
        .result-message strong {
            display: inline-block;
            font-family: 'Courier New', monospace;
            background: rgba(255,255,255,0.08);
            border: 1px dashed rgba(230,57,80,0.4);
            border-radius: 8px;
            padding: 6px 10px;
            margin: 6px 0;
            color: #fff;
            user-select: all;
        }
        .btn-login.secondary {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.14);
            box-shadow: none;
            margin-top: 0.6rem;
        }
        .btn-login.secondary:hover {
            background: rgba(255,255,255,0.1);
            box-shadow: none;
        }
    </style>
</head>
<body class="login-page reset-page">
    <div class="page-wrapper">
        <div class="login-card">
            <div class="card-logo">
                <img src="images/itc_logo.png" alt="Industrial Training Centre">
            </div>

            <?php if ($state === 'success'): ?>
                <div class="result-icon success"><i class="fas fa-check"></i></div>
                <h1 class="result-title">Password Saved</h1>
                <div class="result-message"><?= htmlspecialchars($resetSuccess) ?></div>
                <a href="student_login.php" class="btn-login" style="text-decoration:none;display:inline-block;text-align:center;">
                    <i class="fas fa-arrow-right-to-bracket"></i>&nbsp; Sign In Now
                </a>

            <?php else: ?>
                <div class="result-icon error"><i class="fas fa-exclamation-triangle"></i></div>
                <h1 class="result-title">No Action Performed</h1>
                <div class="result-message">This page was accessed directly. Please start from the login page.</div>
                <a href="student_login.php" class="btn-login secondary" style="text-decoration:none;display:inline-block;text-align:center;">
                    <i class="fas fa-arrow-left"></i>&nbsp; Return to Login
                </a>
            <?php endif; ?>

            <p class="footer-note">Ministry of Technology &amp; Science · TEVETA Accredited · Est. 1986 Lusaka, Zambia</p>
        </div>
    </div>
</body>
</html>
