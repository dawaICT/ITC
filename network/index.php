<?php
declare(strict_types=1);

require_once __DIR__ . '/public/bootstrap.php';
require_once __DIR__ . '/public/helpers.php';

$product = ep_platform_product_name();
$tagline = ep_platform_tagline();
$publicOk = ep_network_public_directory_enabled($db);
$loggedIn = wuc_network_is_authenticated();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($product, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="/wucportal/assets/vendor/bootstrap/5.3.2/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/wucportal/css/enterprise-portal.css">
</head>
<body class="bg-light">
<header class="border-bottom bg-white py-3">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1 class="h4 mb-0"><?= htmlspecialchars($product, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="text-muted small mb-0"><?= htmlspecialchars($tagline, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($loggedIn): ?>
                <a class="btn btn-primary btn-sm" href="/wucportal/network/dashboard.php">My workspace</a>
                <a class="btn btn-outline-secondary btn-sm" href="/wucportal/network/logout.php">Sign out</a>
            <?php else: ?>
                <a class="btn btn-primary btn-sm" href="/wucportal/network/login.php">Member sign in</a>
            <?php endif; ?>
            <?php if ($publicOk): ?>
                <a class="btn btn-outline-primary btn-sm" href="/wucportal/network/public/">Public directory</a>
            <?php endif; ?>
        </div>
    </div>
</header>
<main class="container py-5">
    <div class="row g-4">
        <div class="col-lg-7">
            <p class="lead">A dedicated entry point for skills, enterprise listings, and agriculture market access—separate from the academic and eLearning portals.</p>
            <ul class="text-muted">
                <li><strong>Public</strong> — browse verified listings without an account.</li>
                <li><strong>Members</strong> — manage profiles, opportunities, and organization workspaces after sign-in.</li>
                <li><strong>Platform admin</strong> — configure organizations, approvals, and platform settings (restricted role).</li>
            </ul>
            <?php if (!ep_is_standalone_mode()): ?>
                <p class="small text-muted">This host is in <em>integrated</em> mode. ITC staff and students may still open enterprise features from <a href="/wucportal/portal_selection.php">portal selection</a> using their usual campus login.</p>
            <?php endif; ?>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h6 text-uppercase text-muted">Connected services</h2>
                    <ul class="list-unstyled mb-0">
                        <?php foreach (ep_platform_service_pillars() as $pillar): ?>
                            <li class="mb-3">
                                <strong><?= htmlspecialchars($pillar['label'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                <span class="small text-muted"><?= htmlspecialchars($pillar['description'], ENT_QUOTES, 'UTF-8') ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</main>
</body>
</html>
