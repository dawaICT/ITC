<?php
declare(strict_types=1);

$page_title = 'Platform admin';
require_once dirname(__DIR__) . '/admin/guard.php';

$product = ep_platform_product_name();
$standalone = ep_is_standalone_mode();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Platform admin | <?= htmlspecialchars($product, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="/wucportal/assets/vendor/bootstrap/5.3.2/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/wucportal/css/enterprise-portal.css">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h4 mb-0"><i class="fas fa-shield-halved text-warning me-1"></i> Platform admin</h1>
            <p class="text-muted small mb-0"><?= htmlspecialchars($product, ENT_QUOTES, 'UTF-8') ?> · not WUC academic admin</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="/wucportal/network/dashboard.php">Member hub</a>
            <a class="btn btn-outline-danger btn-sm" href="/wucportal/network/logout.php">Sign out</a>
        </div>
    </div>
    <?php if ($flashError !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <?php if (!$standalone): ?>
        <div class="alert alert-info small">
            Integrated host: open management modules with your <strong>campus staff login</strong> via portal selection, or sign in here with a user that has the <code>platform_admin</code> role for network-only tasks below.
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h6">Organizations</h2>
                    <p class="small text-muted">Multi-tenant workspaces and membership boundaries.</p>
                    <a class="btn btn-primary btn-sm" href="/wucportal/enterprise/management/organizations.php">Manage organizations</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h6">Enterprise management</h2>
                    <p class="small text-muted">Memberships, approvals, published listings, reports.</p>
                    <a class="btn btn-primary btn-sm" href="/wucportal/enterprise/management/index.php">Management dashboard</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h6">Hub settings</h2>
                    <p class="small text-muted">Portal toggles, public directory, agriculture features.</p>
                    <a class="btn btn-primary btn-sm" href="/wucportal/enterprise/management/settings.php">Settings</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h6">Public directory</h2>
                    <p class="small text-muted">Anonymous browse surface (public role).</p>
                    <a class="btn btn-outline-primary btn-sm" href="/wucportal/network/public/">View public site</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
