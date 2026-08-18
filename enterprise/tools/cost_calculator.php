<?php
declare(strict_types=1);

$page_title = 'Cost and Pricing Calculator';
$activeNav = 'tools';
require_once dirname(__DIR__) . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/participant_helpers.php';

$profileId = ep_member_profile_id($epProfile);
ep_require_member_profile($profileId);
$opps = ep_list_opportunities_for_profile($db, $profileId);
$oppId = (int)($_GET['opportunity_id'] ?? $_POST['opportunity_id'] ?? 0);
$errors = [];
$calc = null;
$input = [
    'material_cost' => '0', 'labour_cost' => '0', 'transport_cost' => '0', 'utilities_cost' => '0',
    'packaging_cost' => '0', 'marketing_cost' => '0', 'other_cost' => '0', 'number_of_units' => '1', 'selling_price' => '0',
];

if ($oppId > 0) {
    $existing = ep_get_opportunity_costs($db, $oppId);
    if ($existing) {
        foreach ($input as $k => $_) {
            if (isset($existing[$k])) {
                $input[$k] = (string)$existing[$k];
            }
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        ep_require_post_csrf();
        foreach ($input as $k => $_) {
            $input[$k] = (string)($_POST[$k] ?? '0');
        }
        $oppId = (int)($_POST['opportunity_id'] ?? 0);
        $calc = ep_calculate_costs($input);
        if (!empty($_POST['save']) && $oppId > 0) {
            $opp = ep_get_opportunity($db, $oppId);
            ep_assert_own_opportunity($opp, $profileId);
            $save = ep_save_opportunity_costs($db, $oppId, $input);
            if ($save['ok']) {
                $_SESSION['flash_success'] = $save['message'];
                $calc = $save['calc'];
            } else {
                $errors[] = $save['message'];
            }
        } elseif (!$calc['ok']) {
            $errors = $calc['errors'];
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="ep-card">
    <p class="small ep-muted"><?= ep_h(ep_disclaimer_finance()) ?></p>
    <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
        <div class="col-md-6">
            <label class="form-label">Link to opportunity (optional to save)</label>
            <select name="opportunity_id" class="form-select">
                <option value="0">Preview only</option>
                <?php foreach ($opps as $o): ?>
                    <option value="<?= (int)$o['id'] ?>" <?= $oppId === (int)$o['id'] ? 'selected' : '' ?>><?= ep_h((string)$o['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php foreach (['material_cost'=>'Materials','labour_cost'=>'Labour','transport_cost'=>'Transport','utilities_cost'=>'Utilities','packaging_cost'=>'Packaging','marketing_cost'=>'Marketing','other_cost'=>'Other','number_of_units'=>'Units','selling_price'=>'Selling price'] as $k=>$l): ?>
            <div class="col-md-4">
                <label class="form-label"><?= ep_h($l) ?></label>
                <input class="form-control" name="<?= ep_h($k) ?>" value="<?= ep_h($input[$k]) ?>">
            </div>
        <?php endforeach; ?>
        <div class="col-12 d-flex gap-2">
            <button class="btn btn-outline-primary" type="submit">Calculate</button>
            <button class="btn btn-primary" type="submit" name="save" value="1">Save to opportunity</button>
        </div>
    </form>
</div>
<?php if ($calc && $calc['ok']): $d = $calc['data']; ?>
<div class="ep-card">
    <h2 class="h5">Results</h2>
    <ul class="mb-2">
        <li>Total cost: <?= ep_h(ep_money($d['total_cost'])) ?></li>
        <li>Cost per unit: <?= ep_h(ep_money($d['cost_per_unit'])) ?></li>
        <li>Profit per unit: <?= ep_h(ep_money($d['profit_per_unit'])) ?></li>
        <li>Expected revenue: <?= ep_h(ep_money($d['expected_revenue'])) ?></li>
        <li>Expected profit: <?= ep_h(ep_money($d['expected_profit'])) ?></li>
        <li>Profit margin: <?= ep_h((string)$d['profit_margin']) ?>%</li>
    </ul>
    <?php foreach ($calc['warnings'] as $w): ?><div class="alert alert-warning py-2"><?= ep_h($w) ?></div><?php endforeach; ?>
    <p class="small ep-muted mb-0"><?= ep_h(ep_disclaimer_finance()) ?></p>
</div>
<?php endif; ?>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
