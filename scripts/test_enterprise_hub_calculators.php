<?php
declare(strict_types=1);

/**
 * Offline unit tests for Skills-to-Trade Hub calculators (no DB required).
 * Run: C:\xampp\php\php.exe scripts/test_enterprise_hub_calculators.php
 */

require_once dirname(__DIR__) . '/includes/enterprise_hub/cost_calculator.php';
require_once dirname(__DIR__) . '/includes/enterprise_hub/readiness.php';
require_once dirname(__DIR__) . '/includes/enterprise_hub/status_service.php';

$pass = 0;
$fail = 0;

function eh_assert(bool $cond, string $name): void
{
    global $pass, $fail;
    if ($cond) {
        echo "[PASS] {$name}\n";
        $pass++;
    } else {
        echo "[FAIL] {$name}\n";
        $fail++;
    }
}

// Normal positive
$r = eh_calculate_costs([
    'material_cost' => 100, 'labour_cost' => 50, 'transport_cost' => 10,
    'utilities_cost' => 5, 'packaging_cost' => 5, 'marketing_cost' => 10,
    'other_cost' => 20, 'number_of_units' => 10, 'selling_price' => 30,
]);
eh_assert($r['ok'] === true, 'costs: normal ok');
eh_assert((float)$r['data']['total_cost'] === 200.0, 'costs: total_cost=200');
eh_assert((float)$r['data']['cost_per_unit'] === 20.0, 'costs: cost_per_unit=20');
eh_assert((float)$r['data']['profit_per_unit'] === 10.0, 'costs: profit_per_unit=10');
eh_assert((float)$r['data']['expected_revenue'] === 300.0, 'costs: revenue=300');
eh_assert((float)$r['data']['expected_profit'] === 100.0, 'costs: profit=100');
eh_assert(abs((float)$r['data']['profit_margin'] - 33.33) < 0.02, 'costs: margin≈33.33');

// Zero units
$r = eh_calculate_costs(['number_of_units' => 0, 'selling_price' => 10]);
eh_assert($r['ok'] === false, 'costs: zero units rejected');

// Negative
$r = eh_calculate_costs(['material_cost' => -1, 'number_of_units' => 1, 'selling_price' => 10]);
eh_assert($r['ok'] === false, 'costs: negative rejected');

// Selling below cost
$r = eh_calculate_costs([
    'material_cost' => 100, 'number_of_units' => 1, 'selling_price' => 50,
    'labour_cost' => 0, 'transport_cost' => 0, 'utilities_cost' => 0,
    'packaging_cost' => 0, 'marketing_cost' => 0, 'other_cost' => 0,
]);
eh_assert($r['ok'] === true && in_array('Selling price is below cost per unit.', $r['warnings'], true), 'costs: below-cost warning');

// Decimals
$r = eh_calculate_costs([
    'material_cost' => 10.555, 'labour_cost' => 0, 'transport_cost' => 0,
    'utilities_cost' => 0, 'packaging_cost' => 0, 'marketing_cost' => 0,
    'other_cost' => 0, 'number_of_units' => 3, 'selling_price' => 4.999,
]);
eh_assert($r['ok'] === true && $r['data']['total_cost'] === '10.56' || $r['data']['total_cost'] === '10.55', 'costs: decimal rounding present');

// Large values
$r = eh_calculate_costs([
    'material_cost' => 1000000, 'labour_cost' => 500000, 'transport_cost' => 0,
    'utilities_cost' => 0, 'packaging_cost' => 0, 'marketing_cost' => 0,
    'other_cost' => 0, 'number_of_units' => 1000, 'selling_price' => 2000,
]);
eh_assert($r['ok'] === true && (float)$r['data']['expected_revenue'] === 2000000.0, 'costs: large values');

// Readiness
$ready = eh_calculate_readiness([
    'product_score' => 100, 'market_score' => 100, 'costing_score' => 100,
    'capacity_score' => 100, 'compliance_score' => 100, 'team_score' => 100,
]);
eh_assert($ready['ok'] && (float)$ready['data']['total_score'] === 100.0, 'readiness: perfect 100');
eh_assert($ready['data']['readiness_level'] === 'Strong Investment Readiness', 'readiness: strong level');

$ready = eh_calculate_readiness([
    'product_score' => 20, 'market_score' => 20, 'costing_score' => 20,
    'capacity_score' => 20, 'compliance_score' => 20, 'team_score' => 20,
]);
eh_assert($ready['ok'] && (float)$ready['data']['total_score'] === 20.0, 'readiness: early stage score');
eh_assert($ready['data']['readiness_level'] === 'Early Stage', 'readiness: early stage label');

$ready = eh_calculate_readiness([
    'product_score' => 101, 'market_score' => 50, 'costing_score' => 50,
    'capacity_score' => 50, 'compliance_score' => 50, 'team_score' => 50,
]);
eh_assert($ready['ok'] === false, 'readiness: out of range rejected');

// Weighted example: 80,70,60,50,40,30
// 80*.2 + 70*.2 + 60*.2 + 50*.15 + 40*.15 + 30*.10 = 16+14+12+7.5+6+3 = 58.5
$ready = eh_calculate_readiness([
    'product_score' => 80, 'market_score' => 70, 'costing_score' => 60,
    'capacity_score' => 50, 'compliance_score' => 40, 'team_score' => 30,
]);
eh_assert($ready['ok'] && abs((float)$ready['data']['total_score'] - 58.5) < 0.01, 'readiness: weighted 58.5');
eh_assert($ready['data']['readiness_level'] === 'Development Required', 'readiness: development level');

// Status transitions
eh_assert(eh_can_transition('draft', 'submitted'), 'transition: draft→submitted');
eh_assert(!eh_can_transition('draft', 'published'), 'transition: draft↛published');
eh_assert(eh_can_transition('submitted', 'lecturer_verified'), 'transition: submitted→verified');
eh_assert(eh_can_transition('lecturer_verified', 'approved'), 'transition: verified→approved');
eh_assert(eh_can_transition('approved', 'published'), 'transition: approved→published');
eh_assert(eh_can_transition('published', 'unpublished'), 'transition: published→unpublished');
eh_assert(!eh_can_transition('rejected', 'published'), 'transition: rejected↛published');

// Investment simulator
$sim = eh_investment_simulator([
    'current_qty' => 100, 'current_price' => 10, 'current_cost' => 500,
    'proposed_investment' => 1000, 'expected_qty' => 200, 'expected_workers' => 2, 'expected_cost' => 800,
]);
eh_assert($sim['ok'] && (float)$sim['data']['current_revenue'] === 1000.0, 'simulator: current revenue');
eh_assert((float)$sim['data']['projected_revenue'] === 2000.0, 'simulator: projected revenue');
eh_assert((int)$sim['data']['additional_jobs'] === 2, 'simulator: jobs');
eh_assert(!empty($sim['data']['is_projection']), 'simulator: marked projection');

echo "\nPassed: {$pass}  Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
