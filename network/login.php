<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once dirname(__DIR__, 2) . '/includes/auth_helpers.php';
wuc_network_session_start();
wuc_security_headers();

require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_portal/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    require_once dirname(__DIR__) . '/includes/auth_handler.php';
    network_process_login($db);
    exit;
}

if (wuc_network_is_authenticated()) {
    wuc_redirect('/wucportal/network/dashboard.php');
}

$error = (string)($_SESSION['network_flash_error'] ?? '');
unset($_SESSION['network_flash_error']);
$csrf = function_exists('wuc_csrf_token') ? wuc_csrf_token() : (bin2hex(random_bytes(16)));
$product = ep_platform_product_name();
$tagline = ep_platform_tagline();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in | <?= htmlspecialchars($product, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="/wucportal/assets/vendor/bootstrap/5.3.2/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/css/enterprise-portal.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:420px">
    <h1 class="h4"><?= htmlspecialchars($product, ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="text-muted small"><?= htmlspecialchars($tagline, ENT_QUOTES, 'UTF-8') ?></p>
    <p class="small">This sign-in is for the <strong>Skills and Enterprise Network</strong> only. It does not grant access to the academic or eLearning portals.</p>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" class="card card-body shadow-sm">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <div class="mb-3">
            <label class="form-label">Username or student ID</label>
            <input class="form-control" name="username" required autocomplete="username">
        </div>
        <div class="mb-3">
            <label class="form-label">Password</label>
            <input class="form-control" type="password" name="password" required autocomplete="current-password">
        </div>
        <button class="btn btn-primary w-100" type="submit">Sign in to Network</button>
    </form>
    <p class="mt-3 small text-center">
        <a href="/wucportal/network/public/">Browse public directory</a> ·
        <a href="/wucportal/network/">Home</a>
    </p>
    <?php if (!ep_is_standalone_mode()): ?>
        <p class="small text-muted">Integrated host: WUCPortal staff/students may also use <a href="/wucportal/portal_selection.php">portal selection</a> for enterprise features.</p>
    <?php endif; ?>
</div>
</body>
</html>
