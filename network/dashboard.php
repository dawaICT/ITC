<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/member_guard.php';

$product = ep_platform_product_name();
$username = (string)($_SESSION['username'] ?? '');
$isAdmin = ep_is_platform_admin($db);
$agriOn = function_exists('ep_agriculture_enabled') && ep_agriculture_enabled($db);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workspace | <?= htmlspecialchars($product, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="/wucportal/assets/vendor/bootstrap/5.3.2/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/wucportal/css/enterprise-portal.css">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h4 mb-0"><?= htmlspecialchars($product, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="text-muted small mb-0">Signed in as <?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?> · network session</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="/wucportal/network/">Home</a>
            <a class="btn btn-outline-danger btn-sm" href="/wucportal/network/logout.php">Sign out</a>
        </div>
    </div>
    <?php if ($flashError !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($flashSuccess !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <div class="row g-3">
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h6"><i class="fas fa-briefcase text-primary me-1"></i> Enterprise workspace</h2>
                    <?php if (ep_is_standalone_mode()): ?>
                        <p class="small text-muted">Profiles, skills, opportunities, and membership tools.</p>
                        <a class="btn btn-primary btn-sm" href="/wucportal/enterprise/index.php">Open enterprise app</a>
                    <?php else: ?>
                        <p class="small text-muted">Use your campus login and portal selection for the member workspace on this integrated host.</p>
                        <a class="btn btn-primary btn-sm" href="/wucportal/portal_selection.php">Portal selection</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($agriOn && ep_is_standalone_mode()): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h6"><i class="fas fa-wheat-awn text-success me-1"></i> Agriculture &amp; market access</h2>
                    <p class="small text-muted">Farmers, produce, buyer demands, and price governance.</p>
                    <a class="btn btn-success btn-sm" href="/wucportal/enterprise/agriculture/index.php">Open agriculture</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h6"><i class="fas fa-store text-secondary me-1"></i> Public directory</h2>
                    <p class="small text-muted">See how published listings appear to visitors.</p>
                    <a class="btn btn-outline-primary btn-sm" href="/wucportal/network/public/">Browse directory</a>
                </div>
            </div>
        </div>
        <?php if ($isAdmin): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 shadow-sm border-warning">
                <div class="card-body">
                    <h2 class="h6"><i class="fas fa-shield-halved text-warning me-1"></i> Platform admin</h2>
                    <p class="small text-muted">Organizations, approvals, and network-wide settings—not the WUC academic admin console.</p>
                    <a class="btn btn-warning btn-sm" href="/wucportal/network/admin/">Admin console</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
