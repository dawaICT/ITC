<?php
declare(strict_types=1);

$page_title = 'Growth Projection';
$activeNav = 'tools';
require_once dirname(__DIR__) . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/participant_helpers.php';

$profileId = ep_member_profile_id($epProfile);
ep_require_member_profile($profileId);
$opps = ep_list_opportunities_for_profile($db, $profileId);
$oppId = (int)($_GET['opportunity_id'] ?? 0);
$months = max(1, min(36, (int)($_GET['months'] ?? 6)));
$growth = max(0, min(50, (float)($_GET['monthly_growth'] ?? 5)));
$proj = null;
$costs = null;
if ($oppId > 0) {
    $opp = ep_get_opportunity($db, $oppId);
    ep_assert_own_opportunity($opp, $profileId);
    $costs = ep_get_opportunity_costs($db, $oppId);
    if ($costs) {
        $baseRev = (float)$costs['expected_revenue'];
        $baseProfit = (float)$costs['expected_profit'];
        $proj = [];
        for ($m = 1; $m <= $months; $m++) {
            $factor = pow(1 + ($growth / 100), $m - 1);
            $proj[] = [
                'month' => $m,
                'revenue' => round($baseRev * $factor, 2),
                'profit' => round($baseProfit * $factor, 2),
            ];
        }
    }
}

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="ep-card">
    <p class="small ep-muted"><?= ep_h(ep_disclaimer_finance()) ?> Growth projections are illustrative only.</p>
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label">Opportunity with saved costing</label>
            <select name="opportunity_id" class="form-select">
                <option value="0">Select…</option>
                <?php foreach ($opps as $o): ?>
                    <option value="<?= (int)$o['id'] ?>" <?= $oppId === (int)$o['id'] ? 'selected' : '' ?>><?= ep_h((string)$o['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label">Months</label><input type="number" name="months" class="form-control" value="<?= $months ?>" min="1" max="36"></div>
        <div class="col-md-3"><label class="form-label">Monthly growth %</label><input type="number" step="0.1" name="monthly_growth" class="form-control" value="<?= ep_h((string)$growth) ?>"></div>
        <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Project</button></div>
    </form>
</div>
<?php if ($proj): ?>
<div class="ep-card">
    <table class="table table-sm"><thead><tr><th>Month</th><th>Projected revenue</th><th>Projected profit</th></tr></thead><tbody>
    <?php foreach ($proj as $row): ?>
        <tr><td><?= (int)$row['month'] ?></td><td><?= ep_h(ep_money($row['revenue'])) ?></td><td><?= ep_h(ep_money($row['profit'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
<?php elseif ($oppId > 0 && !$costs): ?>
<div class="alert alert-warning">Save a cost calculation first.</div>
<?php endif; ?>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
