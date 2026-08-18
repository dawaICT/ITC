<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/legacy_academic_guard.php';
ep_redirect_certificate_from_legacy_skills_hub($db, (string)($_SESSION['Sid'] ?? ''));
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.item.edit_own');

$base = '/wucportal/students/enterprise';
$itemId = (int)($_GET['item_id'] ?? $_POST['item_id'] ?? 0);
if ($itemId <= 0) {
    wuc_set_flash('error', 'Select an item first.');
    header('Location: ' . $base . '/items.php');
    exit;
}

$item = eh_get_item($db, $itemId);
if (!$item) {
    wuc_set_flash('error', 'Item not found.');
    header('Location: ' . $base . '/items.php');
    exit;
}

try {
    eh_assert_owns_item($db, $item);
} catch (Throwable $e) {
    wuc_set_flash('error', $e->getMessage());
    header('Location: ' . $base . '/items.php');
    exit;
}

$editable = in_array((string)$item['status'], ['draft', 'changes_requested'], true);
$existing = eh_get_costs($db, $itemId);
$errors = [];
$warnings = [];
$savedCalc = null;
$simResult = null;

$form = [
    'material_cost' => (string)($existing['material_cost'] ?? '0'),
    'labour_cost' => (string)($existing['labour_cost'] ?? '0'),
    'transport_cost' => (string)($existing['transport_cost'] ?? '0'),
    'utilities_cost' => (string)($existing['utilities_cost'] ?? '0'),
    'packaging_cost' => (string)($existing['packaging_cost'] ?? '0'),
    'marketing_cost' => (string)($existing['marketing_cost'] ?? '0'),
    'other_cost' => (string)($existing['other_cost'] ?? '0'),
    'number_of_units' => (string)($existing['number_of_units'] ?? '1'),
    'selling_price' => (string)($existing['selling_price'] ?? '0'),
];

$simForm = [
    'current_qty' => (string)($item['current_capacity'] ?? '0'),
    'current_price' => (string)($existing['selling_price'] ?? '0'),
    'current_cost' => (string)($existing['total_cost'] ?? '0'),
    'proposed_investment' => (string)($item['investment_required'] ?? '0'),
    'expected_qty' => (string)($item['expected_capacity'] ?? '0'),
    'expected_workers' => (string)($item['employment_potential'] ?? '0'),
    'expected_cost' => '0',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        eh_require_post_csrf();
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    $action = (string)($_POST['action'] ?? 'save_costs');

    if ($errors === [] && $action === 'save_costs') {
        if (!$editable) {
            $errors[] = 'Costs can only be edited while the item is a draft or changes were requested.';
        } else {
            foreach (array_keys($form) as $key) {
                $form[$key] = trim((string)($_POST[$key] ?? '0'));
            }
            $result = eh_save_costs($db, $itemId, $form);
            if (empty($result['ok'])) {
                $errors = array_merge($errors, $result['errors'] ?? ['Could not save costs.']);
            } else {
                $savedCalc = $result;
                $warnings = $result['warnings'] ?? [];
                wuc_set_flash('success', 'Cost calculation saved.');
                header('Location: ' . $base . '/cost_calculator.php?item_id=' . $itemId);
                exit;
            }
        }
    } elseif ($errors === [] && $action === 'simulate') {
        foreach (array_keys($simForm) as $key) {
            $simForm[$key] = trim((string)($_POST[$key] ?? '0'));
        }
        $simResult = eh_investment_simulator($simForm);
        if (empty($simResult['ok'])) {
            $errors = array_merge($errors, $simResult['errors'] ?? ['Simulation failed.']);
        }
    }
}

$existing = eh_get_costs($db, $itemId);
if ($existing && $savedCalc === null) {
    $previewSeed = [
        'total_cost' => $existing['total_cost'],
        'cost_per_unit' => $existing['cost_per_unit'],
        'profit_per_unit' => $existing['profit_per_unit'],
        'expected_revenue' => $existing['expected_revenue'],
        'expected_profit' => $existing['expected_profit'],
        'profit_margin' => $existing['profit_margin'],
        'break_even_quantity' => $existing['break_even_quantity'],
    ];
} else {
    $previewSeed = $savedCalc['data'] ?? [
        'total_cost' => 0, 'cost_per_unit' => 0, 'profit_per_unit' => 0,
        'expected_revenue' => 0, 'expected_profit' => 0, 'profit_margin' => 0,
        'break_even_quantity' => null,
    ];
}

$pageTitle = 'Cost calculator';
require_once __DIR__ . '/../includes/navbar.php';
?>
<main class="content-wrapper portal-dashboard eh-hub pt-3 pb-5">
<div class="container-fluid px-3 px-lg-4">
    <div class="dashboard-header student-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title mb-1"><i class="fas fa-calculator me-2 text-primary"></i>Cost &amp; profit calculator</h1>
                <p class="eh-page-lead mb-0"><?php echo eh_h((string)$item['title']); ?></p>
            </div>
            <div class="col-auto">
                <div class="eh-toolbar">
                    <a class="btn btn-outline-secondary" href="<?php echo eh_h($base); ?>/item_view.php?id=<?php echo (int)$itemId; ?>">Back to item</a>
                    <a class="btn btn-outline-primary" href="<?php echo eh_h($base); ?>/readiness_assessment.php?item_id=<?php echo (int)$itemId; ?>">Readiness</a>
                </div>
            </div>
        </div>
    </div>

    <?php
    $doneKeysCost = ['profile', 'item'];
    if (eh_count_item_media($db, $itemId) > 0) {
        $doneKeysCost[] = 'media';
    }
    if ($existing) {
        $doneKeysCost[] = 'costs';
    }
    eh_render_workflow_stepper($base, 'costs', $itemId, $doneKeysCost);
    ?>

    <div class="alert alert-warning">
        <i class="fas fa-triangle-exclamation me-1"></i><?php echo eh_h(eh_disclaimer_finance()); ?>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach (array_unique($errors) as $error): ?>
                <div><?php echo eh_h($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($warnings): ?>
        <div class="alert alert-warning">
            <?php foreach ($warnings as $warning): ?>
                <div><?php echo eh_h($warning); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="data-table-card">
                <div class="card-header"><h5 class="mb-0">Cost inputs</h5></div>
                <div class="card-body">
                    <form method="post" id="ehCostForm" action="<?php echo eh_h($base); ?>/cost_calculator.php?item_id=<?php echo (int)$itemId; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo eh_h(wuc_csrf_token()); ?>">
                        <input type="hidden" name="action" value="save_costs">
                        <input type="hidden" name="item_id" value="<?php echo (int)$itemId; ?>">
                        <fieldset <?php echo $editable ? '' : 'disabled'; ?>>
                            <div class="row g-3">
                                <?php
                                $fields = [
                                    'material_cost' => 'Material cost',
                                    'labour_cost' => 'Labour cost',
                                    'transport_cost' => 'Transport cost',
                                    'utilities_cost' => 'Utilities cost',
                                    'packaging_cost' => 'Packaging cost',
                                    'marketing_cost' => 'Marketing cost',
                                    'other_cost' => 'Other cost',
                                    'number_of_units' => 'Number of units',
                                    'selling_price' => 'Selling price (per unit)',
                                ];
                                foreach ($fields as $name => $label):
                                    $step = $name === 'number_of_units' ? '1' : '0.01';
                                    ?>
                                    <div class="col-md-6">
                                        <label class="form-label" for="<?php echo eh_h($name); ?>"><?php echo eh_h($label); ?></label>
                                        <input class="form-control eh-cost-input" type="number" step="<?php echo eh_h($step); ?>" min="0"
                                               id="<?php echo eh_h($name); ?>" name="<?php echo eh_h($name); ?>"
                                               value="<?php echo eh_h($form[$name]); ?>">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($editable): ?>
                                <div class="mt-3">
                                    <button class="btn btn-primary" type="submit"><i class="fas fa-save me-1"></i>Save calculation</button>
                                </div>
                            <?php endif; ?>
                        </fieldset>
                    </form>
                </div>
            </section>
        </div>
        <div class="col-lg-5">
            <section class="data-table-card">
                <div class="card-header"><h5 class="mb-0">Live preview</h5></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-6">Total cost</dt>
                        <dd class="col-6" id="pv_total_cost"><?php echo eh_h(eh_money($previewSeed['total_cost'] ?? 0)); ?></dd>
                        <dt class="col-6">Cost per unit</dt>
                        <dd class="col-6" id="pv_cost_per_unit"><?php echo eh_h(eh_money($previewSeed['cost_per_unit'] ?? 0)); ?></dd>
                        <dt class="col-6">Profit per unit</dt>
                        <dd class="col-6" id="pv_profit_per_unit"><?php echo eh_h(eh_money($previewSeed['profit_per_unit'] ?? 0)); ?></dd>
                        <dt class="col-6">Expected revenue</dt>
                        <dd class="col-6" id="pv_expected_revenue"><?php echo eh_h(eh_money($previewSeed['expected_revenue'] ?? 0)); ?></dd>
                        <dt class="col-6">Expected profit</dt>
                        <dd class="col-6" id="pv_expected_profit"><?php echo eh_h(eh_money($previewSeed['expected_profit'] ?? 0)); ?></dd>
                        <dt class="col-6">Profit margin</dt>
                        <dd class="col-6" id="pv_profit_margin"><?php echo eh_h(number_format((float)($previewSeed['profit_margin'] ?? 0), 2)); ?>%</dd>
                        <dt class="col-6">Break-even qty</dt>
                        <dd class="col-6" id="pv_break_even"><?php echo $previewSeed['break_even_quantity'] !== null ? (int)$previewSeed['break_even_quantity'] : '—'; ?></dd>
                    </dl>
                    <p class="small text-muted mt-3 mb-0" id="pv_warning"></p>
                </div>
            </section>
        </div>
    </div>

    <section class="data-table-card mt-4">
        <div class="card-header"><h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Investment simulator</h5></div>
        <div class="card-body">
            <p class="text-muted">Projection only — not a guaranteed return.</p>
            <form method="post" action="<?php echo eh_h($base); ?>/cost_calculator.php?item_id=<?php echo (int)$itemId; ?>" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo eh_h(wuc_csrf_token()); ?>">
                <input type="hidden" name="action" value="simulate">
                <input type="hidden" name="item_id" value="<?php echo (int)$itemId; ?>">
                <?php
                $simLabels = [
                    'current_qty' => 'Current quantity',
                    'current_price' => 'Current unit price',
                    'current_cost' => 'Current total cost',
                    'proposed_investment' => 'Proposed investment',
                    'expected_qty' => 'Expected quantity',
                    'expected_workers' => 'Expected additional workers',
                    'expected_cost' => 'Expected total cost',
                ];
                foreach ($simLabels as $name => $label):
                    ?>
                    <div class="col-md-3">
                        <label class="form-label" for="sim_<?php echo eh_h($name); ?>"><?php echo eh_h($label); ?></label>
                        <input class="form-control" type="number" step="0.01" min="0" id="sim_<?php echo eh_h($name); ?>"
                               name="<?php echo eh_h($name); ?>" value="<?php echo eh_h($simForm[$name]); ?>">
                    </div>
                <?php endforeach; ?>
                <div class="col-12">
                    <button class="btn btn-outline-primary" type="submit">Run projection</button>
                </div>
            </form>

            <?php if (is_array($simResult) && !empty($simResult['ok'])): ?>
                <?php $d = $simResult['data']; ?>
                <hr>
                <div class="row g-3">
                    <div class="col-md-3"><strong>Current revenue</strong><div><?php echo eh_h(eh_money($d['current_revenue'])); ?></div></div>
                    <div class="col-md-3"><strong>Projected revenue</strong><div><?php echo eh_h(eh_money($d['projected_revenue'])); ?></div></div>
                    <div class="col-md-3"><strong>Current profit</strong><div><?php echo eh_h(eh_money($d['current_profit'])); ?></div></div>
                    <div class="col-md-3"><strong>Projected profit</strong><div><?php echo eh_h(eh_money($d['projected_profit'])); ?></div></div>
                    <div class="col-md-3"><strong>Production growth</strong><div><?php echo eh_h($d['production_growth_pct']); ?>%</div></div>
                    <div class="col-md-3"><strong>Revenue growth</strong><div><?php echo eh_h($d['revenue_growth_pct']); ?>%</div></div>
                    <div class="col-md-3"><strong>Additional jobs</strong><div><?php echo (int)$d['additional_jobs']; ?></div></div>
                    <div class="col-md-3"><strong>Proposed investment</strong><div><?php echo eh_h(eh_money($d['proposed_investment'])); ?></div></div>
                </div>
                <p class="small text-muted mt-3 mb-0"><?php echo eh_h((string)$d['disclaimer']); ?></p>
            <?php endif; ?>
        </div>
    </section>
</div>
<script>
(function () {
    function num(id) {
        var el = document.getElementById(id);
        var v = el ? parseFloat(el.value) : 0;
        return isFinite(v) ? v : 0;
    }
    function money(v) {
        return 'ZMW ' + (Math.round(v * 100) / 100).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
    function refresh() {
        var material = num('material_cost');
        var labour = num('labour_cost');
        var transport = num('transport_cost');
        var utilities = num('utilities_cost');
        var packaging = num('packaging_cost');
        var marketing = num('marketing_cost');
        var other = num('other_cost');
        var units = Math.max(0, Math.floor(num('number_of_units')));
        var selling = num('selling_price');
        var total = material + labour + transport + utilities + packaging + marketing + other;
        var cpu = units > 0 ? total / units : 0;
        var ppu = selling - cpu;
        var revenue = selling * units;
        var profit = revenue - total;
        var margin = revenue > 0 ? (profit / revenue) * 100 : 0;
        var be = (ppu > 0) ? Math.ceil(total / ppu) : null;

        document.getElementById('pv_total_cost').textContent = money(total);
        document.getElementById('pv_cost_per_unit').textContent = money(cpu);
        document.getElementById('pv_profit_per_unit').textContent = money(ppu);
        document.getElementById('pv_expected_revenue').textContent = money(revenue);
        document.getElementById('pv_expected_profit').textContent = money(profit);
        document.getElementById('pv_profit_margin').textContent = margin.toFixed(2) + '%';
        document.getElementById('pv_break_even').textContent = be === null ? '—' : String(be);

        var warn = '';
        if (units <= 0) warn = 'Number of units must be greater than zero to save.';
        else if (selling < cpu) warn = 'Selling price is below cost per unit.';
        else if (margin < 0) warn = 'Profit margin is negative.';
        document.getElementById('pv_warning').textContent = warn;
    }
    document.querySelectorAll('.eh-cost-input').forEach(function (el) {
        el.addEventListener('input', refresh);
        el.addEventListener('change', refresh);
    });
    refresh();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
