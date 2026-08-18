<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/enterprise_hub/bootstrap.php';
require_once __DIR__ . '/includes/helpers.php';

wuc_secure_session_start();
if (function_exists('wuc_security_headers')) {
    wuc_security_headers();
}

$code = strtoupper(trim((string)($_GET['code'] ?? '')));
if ($code === '' || !preg_match('/^ENT-[A-Z0-9]{8}$/', $code)) {
    http_response_code(404);
    $pageTitle = 'Not found';
    require __DIR__ . '/includes/public_header.php';
    echo '<main class="eh-main"><div class="container"><div class="alert alert-warning">Opportunity not found.</div>';
    echo '<a class="btn btn-eh-primary" href="/wucportal/showcase/index.php">Back to catalogue</a></div>';
    require __DIR__ . '/includes/public_footer.php';
    exit;
}

$item = eh_get_item_by_code($db, $code, true);
if (!$item) {
    http_response_code(404);
    $pageTitle = 'Not found';
    require __DIR__ . '/includes/public_header.php';
    echo '<main class="eh-main"><div class="container"><div class="alert alert-warning">This opportunity is not available or has not been published.</div>';
    echo '<a class="btn btn-eh-primary" href="/wucportal/showcase/index.php">Back to catalogue</a></div>';
    require __DIR__ . '/includes/public_footer.php';
    exit;
}

$itemId = (int)$item['id'];

// Increment views once per session
if (!isset($_SESSION['eh_viewed_items']) || !is_array($_SESSION['eh_viewed_items'])) {
    $_SESSION['eh_viewed_items'] = [];
}
if (empty($_SESSION['eh_viewed_items'][$itemId])) {
    eh_increment_views($db, $itemId);
    $_SESSION['eh_viewed_items'][$itemId] = time();
}

$mediaList = eh_list_media($db, $itemId);
$primaryMedia = null;
foreach ($mediaList as $m) {
    if ((int)($m['is_primary'] ?? 0) === 1) {
        $primaryMedia = $m;
        break;
    }
}
if (!$primaryMedia && $mediaList) {
    $primaryMedia = $mediaList[0];
}

$types = eh_item_types();
$typeLabel = $types[(string)($item['item_type'] ?? '')] ?? ucwords(str_replace('_', ' ', (string)($item['item_type'] ?? '')));
$verified = showcase_item_is_verified($item);
$currency = (string)($item['currency'] ?? 'ZMW');
$publicUrl = eh_public_item_url($code);
$qrDataUri = wuc_qr_svg_data_uri($publicUrl, 3);

$pageTitle = (string)$item['title'];
$pageDescription = (string)($item['short_description'] ?? $item['title']);
require __DIR__ . '/includes/public_header.php';
?>
<main class="eh-main">
<div class="container">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="/wucportal/showcase/index.php">Catalogue</a></li>
            <?php if (!empty($item['category_slug'])): ?>
                <li class="breadcrumb-item">
                    <a href="/wucportal/showcase/category.php?slug=<?php echo rawurlencode((string)$item['category_slug']); ?>">
                        <?php echo eh_h((string)$item['category_name']); ?>
                    </a>
                </li>
            <?php endif; ?>
            <li class="breadcrumb-item active" aria-current="page"><?php echo eh_h($code); ?></li>
        </ol>
    </nav>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="eh-card">
                <?php if ($primaryMedia): ?>
                    <img class="eh-card-img" style="aspect-ratio:16/11;border-radius:0"
                         src="<?php echo eh_h(showcase_media_url((int)$primaryMedia['id'], false)); ?>"
                         alt="<?php echo eh_h((string)$item['title']); ?>">
                <?php else: ?>
                    <div class="eh-card-img-placeholder" style="aspect-ratio:16/11;border-radius:0">
                        <i class="fas fa-image fa-2x"></i>
                    </div>
                <?php endif; ?>
                <?php if (count($mediaList) > 1): ?>
                    <div class="p-2 d-flex flex-wrap gap-2 border-top">
                        <?php foreach ($mediaList as $m): ?>
                            <a href="<?php echo eh_h(showcase_media_url((int)$m['id'], false)); ?>" target="_blank" rel="noopener">
                                <img src="<?php echo eh_h(showcase_media_url((int)$m['id'], true)); ?>"
                                     alt="" width="72" height="54"
                                     style="object-fit:cover;border-radius:6px;border:1px solid var(--eh-border)">
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="eh-filter-panel h-100">
                <div class="d-flex flex-wrap gap-1 mb-2">
                    <?php if (!empty($item['category_name'])): ?>
                        <span class="badge bg-light text-dark border"><?php echo eh_h((string)$item['category_name']); ?></span>
                    <?php endif; ?>
                    <span class="badge text-bg-secondary"><?php echo eh_h($typeLabel); ?></span>
                    <?php if ($verified): ?>
                        <span class="eh-badge-verified"><i class="fas fa-shield-alt me-1"></i>Verified listing</span>
                    <?php endif; ?>
                </div>
                <h1 class="h3 mb-2"><?php echo eh_h((string)$item['title']); ?></h1>
                <p class="eh-meta mb-3">
                    <i class="fas fa-building me-1"></i><?php echo eh_h((string)($item['business_name'] ?? '')); ?>
                    <?php if (!empty($item['province'])): ?>
                        · <i class="fas fa-map-marker-alt me-1"></i><?php echo eh_h((string)$item['province']); ?>
                    <?php endif; ?>
                </p>
                <?php if (!empty($item['programme_name'])): ?>
                    <p class="small mb-3">
                        <strong>Programme:</strong> <?php echo eh_h((string)$item['programme_name']); ?>
                        <?php if (!empty($item['programme_code'])): ?>
                            <span class="text-muted">(<?php echo eh_h((string)$item['programme_code']); ?>)</span>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <dl class="row small mb-3">
                    <?php if ($item['unit_price'] !== null && $item['unit_price'] !== ''): ?>
                        <dt class="col-5">Unit price</dt>
                        <dd class="col-7 fw-semibold"><?php echo eh_h(eh_money($item['unit_price'], $currency)); ?></dd>
                    <?php endif; ?>
                    <?php if ($item['current_capacity'] !== null && $item['current_capacity'] !== ''): ?>
                        <dt class="col-5">Capacity</dt>
                        <dd class="col-7">
                            <?php echo eh_h((string)(int)$item['current_capacity']); ?>
                            <?php if (!empty($item['capacity_period'])): ?>
                                <span class="text-muted">/ <?php echo eh_h((string)$item['capacity_period']); ?></span>
                            <?php endif; ?>
                        </dd>
                    <?php endif; ?>
                    <?php if ($item['investment_required'] !== null && $item['investment_required'] !== ''): ?>
                        <dt class="col-5">Investment</dt>
                        <dd class="col-7 fw-semibold text-primary"><?php echo eh_h(eh_money($item['investment_required'], $currency)); ?></dd>
                    <?php endif; ?>
                    <?php if ($item['employment_potential'] !== null && $item['employment_potential'] !== ''): ?>
                        <dt class="col-5">Employment</dt>
                        <dd class="col-7"><?php echo eh_h((string)(int)$item['employment_potential']); ?> potential jobs</dd>
                    <?php endif; ?>
                    <?php if (!empty($item['readiness_level'])): ?>
                        <dt class="col-5">Readiness</dt>
                        <dd class="col-7"><?php echo eh_h((string)$item['readiness_level']); ?></dd>
                    <?php endif; ?>
                    <dt class="col-5">Public code</dt>
                    <dd class="col-7"><code><?php echo eh_h($code); ?></code></dd>
                </dl>

                <?php if (!empty($item['public_phone']) || !empty($item['public_email'])): ?>
                    <div class="small mb-3">
                        <strong>Public contact</strong><br>
                        <?php if (!empty($item['public_phone'])): ?>
                            <span class="me-2"><i class="fas fa-phone me-1"></i><?php echo eh_h((string)$item['public_phone']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($item['public_email'])): ?>
                            <span><i class="fas fa-envelope me-1"></i><?php echo eh_h((string)$item['public_email']); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="d-grid gap-2 mb-3">
                    <a class="btn btn-eh-primary btn-lg"
                       href="/wucportal/showcase/express_interest.php?code=<?php echo rawurlencode($code); ?>">
                        <i class="fas fa-handshake me-2"></i>Express interest
                    </a>
                </div>

                <?php if ($qrDataUri !== ''): ?>
                    <div class="text-center border-top pt-3">
                        <img src="<?php echo eh_h($qrDataUri); ?>" alt="QR code for this opportunity" width="120" height="120">
                        <div class="small text-muted mt-1">Scan to open this listing</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-lg-8">
            <div class="eh-filter-panel">
                <h2 class="h5 mb-3">Description</h2>
                <?php if (!empty($item['short_description'])): ?>
                    <p class="lead fs-6"><?php echo eh_h((string)$item['short_description']); ?></p>
                <?php endif; ?>
                <?php if (!empty($item['full_description'])): ?>
                    <div class="text-body" style="white-space:pre-wrap"><?php echo eh_h((string)$item['full_description']); ?></div>
                <?php elseif (empty($item['short_description'])): ?>
                    <p class="text-muted mb-0">No detailed description provided.</p>
                <?php endif; ?>
                <?php if (!empty($item['investment_purpose'])): ?>
                    <hr>
                    <h3 class="h6">Investment purpose</h3>
                    <p class="mb-0"><?php echo eh_h((string)$item['investment_purpose']); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="eh-disclaimer">
                <i class="fas fa-info-circle me-1"></i>
                <?php echo eh_h(eh_disclaimer_finance()); ?>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
