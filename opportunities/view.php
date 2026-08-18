<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (!ep_public_directory_enabled($db)) {
    http_response_code(503);
    echo 'The Skills and Enterprise Directory is currently unavailable.';
    exit;
}

$code = strtoupper(trim((string)($_GET['code'] ?? '')));
if (!ep_public_valid_code($code)) {
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

ep_public_page_begin((string)$opp['title']);
ep_public_disclaimer_banner();
?>
<div class="container py-4">
    <div class="ep-card">
        <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
            <div>
                <span class="badge bg-success">Verified listing</span>
                <span class="badge bg-secondary"><?= ep_h(ep_status_label((string)$opp['opportunity_type'])) ?></span>
                <span class="small text-muted ms-2"><?= ep_h((string)$opp['public_code']) ?></span>
            </div>
            <a class="btn btn-sm btn-outline-secondary" href="/wucportal/opportunities/index.php">Back to directory</a>
        </div>
        <h1 class="h3"><?= ep_h((string)$opp['title']) ?></h1>
        <p class="ep-muted"><?= ep_h((string)$opp['short_description']) ?></p>
        <p><?= nl2br(ep_h((string)($opp['full_description'] ?? ''))) ?></p>

        <dl class="row small">
            <dt class="col-sm-3">Participant</dt>
            <dd class="col-sm-9"><?= ep_h(trim((string)(($opp['business_name'] ?? '') ?: ($opp['professional_title'] ?? ''))) ?: 'Participant') ?></dd>
            <?php if (!empty($opp['programme_name'])): ?>
                <dt class="col-sm-3">Programme</dt>
                <dd class="col-sm-9"><?= ep_h((string)$opp['programme_name']) ?></dd>
            <?php endif; ?>
            <?php if (!empty($opp['province'])): ?>
                <dt class="col-sm-3">Location</dt>
                <dd class="col-sm-9"><?= ep_h(trim(($opp['district'] ?? '') . ', ' . ($opp['province'] ?? ''), ' ,')) ?></dd>
            <?php endif; ?>
            <?php if (!empty($opp['pricing_type'])): ?>
                <dt class="col-sm-3">Pricing</dt>
                <dd class="col-sm-9">
                    <?= ep_h(ep_status_label((string)$opp['pricing_type'])) ?>
                    <?php if ($opp['unit_price'] !== null && $opp['unit_price'] !== ''): ?>
                        · <?= ep_h(ep_money($opp['unit_price'], (string)$opp['currency'])) ?>
                    <?php endif; ?>
                </dd>
            <?php endif; ?>
            <dt class="col-sm-3">Availability</dt>
            <dd class="col-sm-9"><?= ep_h(ep_status_label((string)$opp['availability_status'])) ?></dd>
            <?php if (!empty($opp['readiness_level'])): ?>
                <dt class="col-sm-3">Readiness</dt>
                <dd class="col-sm-9"><?= ep_h((string)$opp['readiness_level']) ?></dd>
            <?php endif; ?>
            <?php if (!empty($opp['investment_required'])): ?>
                <dt class="col-sm-3">Investment sought</dt>
                <dd class="col-sm-9"><?= ep_h(ep_money($opp['investment_required'], (string)$opp['currency'])) ?><?php if (!empty($opp['investment_purpose'])): ?> — <?= ep_h((string)$opp['investment_purpose']) ?><?php endif; ?></dd>
            <?php endif; ?>
            <dt class="col-sm-3">Contact</dt>
            <dd class="col-sm-9">
                <?php
                $method = (string)($opp['preferred_contact_method'] ?? 'portal_mediated');
                if ($method === 'email' && !empty($opp['public_email'])) {
                    echo ep_h((string)$opp['public_email']);
                } elseif ($method === 'phone' && !empty($opp['public_phone'])) {
                    echo ep_h((string)$opp['public_phone']);
                } else {
                    echo 'Portal-mediated (use Express interest)';
                }
                ?>
            </dd>
        </dl>

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
            <a class="btn btn-outline-danger" href="/wucportal/opportunities/report.php?code=<?= ep_h(rawurlencode($code)) ?>">Report listing</a>
        </div>
    </div>
</div>
<?php ep_public_page_end(); ?>
