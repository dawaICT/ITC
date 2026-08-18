<?php
declare(strict_types=1);

if (!function_exists('ep_network_public_directory_enabled')) {
    function ep_network_public_directory_enabled(mysqli $db): bool
    {
        if (!ep_portal_enabled($db)) {
            return false;
        }
        return ep_setting_bool($db, 'public_directory', true);
    }
}

if (!function_exists('ep_network_public_filters_from_request')) {
    /** @return array<string,mixed> */
    function ep_network_public_filters_from_request(): array
    {
        $filters = [];
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            $filters['q'] = substr($q, 0, 120);
        }
        $type = trim((string)($_GET['type'] ?? ''));
        if ($type !== '' && isset(ep_opportunity_types()[$type])) {
            $filters['type'] = $type;
        }
        if (!empty($_GET['category_id'])) {
            $filters['category_id'] = (int)$_GET['category_id'];
        }
        $province = trim((string)($_GET['province'] ?? ''));
        if ($province !== '') {
            $filters['province'] = substr($province, 0, 80);
        }
        if (!empty($_GET['featured'])) {
            $filters['featured'] = 1;
        }
        return $filters;
    }
}

if (!function_exists('ep_network_public_type_icon')) {
    function ep_network_public_type_icon(string $type): string
    {
        return match ($type) {
            'professional_skill' => 'fa-screwdriver-wrench',
            'employment_profile' => 'fa-user-tie',
            'service' => 'fa-handshake',
            'product' => 'fa-box-open',
            'innovation' => 'fa-lightbulb',
            'business_idea' => 'fa-seedling',
            'investment_opportunity' => 'fa-chart-line',
            default => 'fa-briefcase',
        };
    }
}

if (!function_exists('ep_network_public_valid_code')) {
    function ep_network_public_valid_code(string $code): bool
    {
        return (bool)preg_match('/^ENT-[A-Z0-9]{8}$/', strtoupper(trim($code)));
    }
}

if (!function_exists('ep_network_public_disclaimer')) {
    function ep_network_public_disclaimer(): string
    {
        return 'Listings reflect institutional verification of submitted information. Verification does not guarantee employment, sales, funding, investment returns, or the accuracy of every detail supplied by participants.';
    }
}

if (!function_exists('ep_network_public_page_begin')) {
    function ep_network_public_page_begin(string $title, string $description = ''): void
    {
        if ($description === '') {
            $description = ep_platform_tagline();
        }
        $product = ep_platform_product_name();
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= ep_h($description) ?>">
    <title><?= ep_h($title) ?> | <?= ep_h($product) ?></title>
    <?php
    $pageMeta = dirname(__DIR__, 2) . '/includes/page_meta.php';
    if (is_file($pageMeta)) {
        require_once $pageMeta;
        if (function_exists('wuc_portal_favicon_links')) {
            wuc_portal_favicon_links();
        }
    }
    ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/css/enterprise-portal.css?v=20260725">
</head>
<body class="ep-body ep-public-shell">
<nav class="navbar navbar-expand-lg navbar-dark ep-public-nav">
    <div class="container">
        <a class="navbar-brand ep-public-brand" href="/wucportal/network/public/">
            <span class="ep-public-brand-mark" aria-hidden="true"><i class="fas fa-network-wired"></i></span>
            <span class="ep-public-brand-text"><?= ep_h($product) ?></span>
        </a>
        <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#epNetworkPublicNav" aria-controls="epNetworkPublicNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse justify-content-end" id="epNetworkPublicNav">
            <div class="d-flex flex-column flex-lg-row gap-2 align-items-stretch align-items-lg-center pt-3 pt-lg-0">
                <a class="btn btn-sm btn-outline-light ep-public-nav-btn<?= $script === 'index.php' ? ' active' : '' ?>" href="/wucportal/network/public/#directory">Browse listings</a>
                <a class="btn btn-sm btn-light ep-public-nav-btn" href="/wucportal/network/login.php"><i class="fas fa-right-to-bracket me-1"></i>Member sign in</a>
                <a class="btn btn-sm btn-outline-light ep-public-nav-btn" href="/wucportal/network/">Home</a>
            </div>
        </div>
    </div>
</nav>
<div class="ep-public-main">
        <?php
    }
}

if (!function_exists('ep_network_public_disclaimer_banner')) {
    function ep_network_public_disclaimer_banner(): void
    {
        echo '<div class="ep-public-disclaimer"><i class="fas fa-shield-halved me-1 text-primary"></i>';
        echo ep_h(ep_network_public_disclaimer());
        echo '</div>';
    }
}

if (!function_exists('ep_network_public_page_end')) {
    function ep_network_public_page_end(): void
    {
        $product = ep_platform_product_name();
        ?>
</div>
<footer class="ep-footer ep-public-footer">
    <div class="container">
        <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start">
            <div>
                <strong class="d-block mb-1"><?= ep_h($product) ?></strong>
                <span class="ep-muted">Public directory · no sign-in required</span>
            </div>
            <div class="text-md-end ep-muted" style="max-width:28rem">
                Verification confirms submitted information was reviewed. It does not guarantee employment, sales, funding, or investment outcomes.
            </div>
        </div>
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
        <?php
    }
}
