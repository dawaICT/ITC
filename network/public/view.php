<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/helpers.php';

if (!ep_network_public_directory_enabled($db)) {
    http_response_code(503);
    echo 'The public directory is currently unavailable.';
    exit;
}

$code = strtoupper(trim((string)($_GET['code'] ?? '')));
if (!ep_network_public_valid_code($code)) {
    http_response_code(404);
    echo 'Opportunity not found.';
    exit;
}

$opp = ep_get_opportunity_by_code($db, $code, true);
if (!$opp) {
    http_response_code(404);
    echo 'Opportunity not found.';
    exit;
}

ep_increment_view_count($db, (int)$opp['id']);
$media = ep_list_media($db, (int)$opp['id']);

ep_network_public_page_begin((string)$opp['title']);
ep_network_public_disclaimer_banner();
?>
<div class="container py-4">
    <div class="ep-card">
        <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
            <div>
                <span class="badge bg-success">Verified listing</span>
                <span class="badge bg-secondary"><?= ep_h(ep_status_label((string)$opp['opportunity_type'])) ?></span>
                <span class="small text-muted ms-2"><?= ep_h((string)$opp['public_code']) ?></span>
            </div>
            <a class="btn btn-sm btn-outline-secondary" href="/wucportal/network/public/">Back to directory</a>
        </div>
        <h1 class="h3"><?= ep_h((string)$opp['title']) ?></h1>
        <p class="ep-muted"><?= ep_h((string)$opp['short_description']) ?></p>
        <p><?= nl2br(ep_h((string)($opp['full_description'] ?? ''))) ?></p>

        <?php if ($media): ?>
            <div class="row g-2 mb-3">
                <?php foreach ($media as $m): ?>
                    <div class="col-6 col-md-3">
                        <img src="/wucportal/enterprise/media.php?id=<?= (int)$m['id'] ?>&public=1" alt="" class="img-fluid rounded border" loading="lazy">
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary" href="/wucportal/opportunities/express_interest.php?code=<?= ep_h(rawurlencode($code)) ?>">Express interest</a>
            <a class="btn btn-outline-secondary" href="/wucportal/network/login.php">Member sign in</a>
        </div>
    </div>
</div>
<?php ep_network_public_page_end(); ?>
