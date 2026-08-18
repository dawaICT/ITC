<?php
declare(strict_types=1);

$page_title = 'Agriculture & Market Access';
$activeNav = 'agri_dash';
$epGuardMode = 'agriculture';
$epNav = 'agriculture';
require_once dirname(__DIR__) . '/includes/guard.php';

$metrics = ep_agriculture_report_metrics($db);
$expiredListings = ep_expire_produce_listings($db);
$expiredDemands = ep_expire_buyer_crop_demands($db);

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="alert alert-info small">
    Skills and Enterprise Portal — Agriculture &amp; Market Access module.
    Matches and price checks are <strong>not</strong> sales. Farmers may exist without web login.
    Deployment mode: <code><?= ep_h(ep_deployment_mode()) ?></code>.
    <?php if ($expiredListings > 0): ?>
        Auto-expired <?= (int)$expiredListings ?> produce listing(s).
    <?php endif; ?>
</div>
<div class="ep-stat-grid">
    <div class="ep-stat"><div class="label">Farmers registered</div><div class="value"><?= (int)$metrics['farmers_registered'] ?></div></div>
    <div class="ep-stat"><div class="label">Verified farmers</div><div class="value"><?= (int)$metrics['farmers_verified'] ?></div></div>
    <div class="ep-stat"><div class="label">Produce available</div><div class="value"><?= (int)$metrics['produce_available'] ?></div></div>
    <div class="ep-stat"><div class="label">Buyer demands</div><div class="value"><?= (int)$metrics['buyer_demands'] ?></div></div>
    <div class="ep-stat"><div class="label">Matches suggested</div><div class="value"><?= (int)$metrics['matches_suggested'] ?></div></div>
    <div class="ep-stat"><div class="label">Offers accepted</div><div class="value"><?= (int)$metrics['offers_accepted'] ?></div></div>
    <div class="ep-stat"><div class="label">Sales confirmed</div><div class="value"><?= (int)$metrics['sales_confirmed'] ?></div></div>
    <div class="ep-stat"><div class="label">USSD completed</div><div class="value"><?= (int)$metrics['ussd_completed'] ?></div></div>
</div>
<div class="ep-card mt-3">
    <h2 class="h6">Quick actions</h2>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-sm btn-primary" href="/wucportal/enterprise/agriculture/farmers.php">Register farmer</a>
        <a class="btn btn-sm btn-outline-primary" href="/wucportal/enterprise/agriculture/produce.php">Produce listings</a>
        <a class="btn btn-sm btn-outline-primary" href="/wucportal/enterprise/agriculture/demands.php">Buyer demands</a>
        <a class="btn btn-sm btn-outline-primary" href="/wucportal/enterprise/agriculture/matches.php">Matching</a>
        <a class="btn btn-sm btn-outline-secondary" href="/wucportal/enterprise/agriculture/prices.php">Prices</a>
        <a class="btn btn-sm btn-outline-secondary" href="/wucportal/enterprise/agriculture/ussd_simulator.php">USSD simulator</a>
    </div>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
