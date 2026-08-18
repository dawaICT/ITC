<?php
declare(strict_types=1);

$page_title = 'Agriculture reports';
$activeNav = 'agri_reports';
$epGuardMode = 'agriculture';
$epNav = 'agriculture';
require_once dirname(__DIR__) . '/includes/guard.php';

if (!ep_staff_can($db, 'agriculture.reports.view')) {
    $_SESSION['flash_error'] = 'Permission denied.';
    wuc_redirect('/wucportal/enterprise/agriculture/index.php');
}

$m = ep_agriculture_report_metrics($db);
$labels = [
    'farmers_registered' => 'Farmers registered',
    'farmers_verified' => 'Verified farmers',
    'produce_listed' => 'Produce listed',
    'produce_available' => 'Produce available',
    'buyer_demands' => 'Buyer demands',
    'matches_suggested' => 'Matches suggested',
    'matches_approved' => 'Matches approved',
    'offers_accepted' => 'Offers accepted',
    'produce_promised' => 'Produce promised',
    'produce_delivered' => 'Produce delivered',
    'sales_confirmed' => 'Sales confirmed',
    'price_checks_sms' => 'Price-check SMS',
    'sms_delivered' => 'SMS delivered',
    'ussd_completed' => 'USSD sessions completed',
    'expired_prices' => 'Expired prices',
    'expired_produce' => 'Expired produce listings',
];

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="alert alert-secondary small">
    A price check is not a sale. A match is not a transaction. An accepted offer is not delivery.
    Promised produce is separate from delivered produce. Buyer enquiry is not a completed purchase.
</div>
<div class="ep-stat-grid">
    <?php foreach ($labels as $key => $label): ?>
        <div class="ep-stat">
            <div class="label"><?= ep_h($label) ?></div>
            <div class="value"><?= (int)($m[$key] ?? 0) ?></div>
        </div>
    <?php endforeach; ?>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
