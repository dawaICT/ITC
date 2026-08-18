<?php
declare(strict_types=1);

$page_title = 'Reports';
$activeNav = 'mgmt_rep';
$epGuardMode = 'management';
$epNav = 'management';
require_once dirname(__DIR__) . '/includes/guard.php';

if (!ep_can($db, 'enterprise.reports.view')) {
    $_SESSION['flash_error'] = 'Permission denied.';
    wuc_redirect('/wucportal/enterprise/management/index.php');
}

$reports = [];
$queries = [
    'Leads (open)' => "SELECT COUNT(*) c FROM enterprise_opportunity_interests WHERE lead_status NOT IN ('converted','closed_unsuccessful','invalid','spam')",
    'Leads converted' => "SELECT COUNT(*) c FROM enterprise_opportunity_interests WHERE lead_status = 'converted'",
    'Outcomes (all)' => 'SELECT COUNT(*) c FROM enterprise_outcomes',
    'Employment offers' => "SELECT COUNT(*) c FROM enterprise_outcomes WHERE outcome_type = 'employment_offer'",
    'Employment started' => "SELECT COUNT(*) c FROM enterprise_outcomes WHERE outcome_type = 'employment_started'",
    'Product orders' => "SELECT COUNT(*) c FROM enterprise_outcomes WHERE outcome_type = 'product_order'",
    'Product orders completed' => "SELECT COUNT(*) c FROM enterprise_outcomes WHERE outcome_type = 'product_order_completed'",
    'Service contracts' => "SELECT COUNT(*) c FROM enterprise_outcomes WHERE outcome_type = 'service_contract'",
    'Partnerships' => "SELECT COUNT(*) c FROM enterprise_outcomes WHERE outcome_type = 'partnership_created'",
    'Mentorships started' => "SELECT COUNT(*) c FROM enterprise_outcomes WHERE outcome_type = 'mentorship_started'",
    'Funding referrals' => "SELECT COUNT(*) c FROM enterprise_outcomes WHERE outcome_type = 'funding_referral'",
    'Funding received' => "SELECT COUNT(*) c FROM enterprise_outcomes WHERE outcome_type = 'funding_received'",
    'Confirmed jobs (sum)' => "SELECT COALESCE(SUM(jobs_created),0) c FROM enterprise_outcomes WHERE outcome_type = 'employment_started'",
    'Investment listed (ZMW)' => "SELECT COALESCE(SUM(investment_required),0) c FROM enterprise_opportunities WHERE status = 'published' AND investment_required IS NOT NULL",
];
foreach ($queries as $label => $sql) {
    $r = $db->query($sql);
    $reports[$label] = $r ? (float)($r->fetch_assoc()['c'] ?? 0) : 0;
}

$byType = [];
$r = $db->query("SELECT opportunity_type, COUNT(*) c FROM enterprise_opportunities WHERE status = 'published' GROUP BY opportunity_type ORDER BY c DESC");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $byType[] = $row;
    }
}
$types = ep_opportunity_types();

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="alert alert-secondary small">Reports distinguish <strong>leads</strong> from <strong>outcomes</strong>, employment offers from employment started, funding referrals from funding received, and listed investment needs from verified funding received.</div>
<div class="ep-stat-grid">
    <?php foreach ($reports as $label => $value): ?>
        <div class="ep-stat">
            <div class="label"><?= ep_h($label) ?></div>
            <div class="value" style="font-size:1.1rem">
                <?= str_contains($label, 'ZMW') ? ep_h(ep_money($value)) : (int)$value ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<div class="ep-card">
    <h2 class="h6">Published opportunities by type</h2>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>Type</th><th>Count</th></tr></thead>
            <tbody>
            <?php if ($byType === []): ?>
                <tr><td colspan="2" class="ep-muted">No published opportunities.</td></tr>
            <?php else: ?>
                <?php foreach ($byType as $row): ?>
                    <tr>
                        <td><?= ep_h($types[(string)$row['opportunity_type']] ?? (string)$row['opportunity_type']) ?></td>
                        <td><?= (int)$row['c'] ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
