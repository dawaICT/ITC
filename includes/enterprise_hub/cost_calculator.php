<?php
declare(strict_types=1);

if (!function_exists('eh_round_money')) {
    function eh_round_money($value): string
    {
        return number_format((float)$value, 2, '.', '');
    }
}

if (!function_exists('eh_calculate_costs')) {
    /**
     * @param array<string,mixed> $input
     * @return array{ok:bool, errors:list<string>, warnings:list<string>, data:array<string,mixed>}
     */
    function eh_calculate_costs(array $input): array
    {
        $errors = [];
        $warnings = [];
        $fields = [
            'material_cost', 'labour_cost', 'transport_cost', 'utilities_cost',
            'packaging_cost', 'marketing_cost', 'other_cost', 'selling_price',
        ];
        $values = [];
        foreach ($fields as $f) {
            $raw = $input[$f] ?? 0;
            if ($raw === '' || $raw === null) {
                $raw = 0;
            }
            if (!is_numeric($raw)) {
                $errors[] = ucwords(str_replace('_', ' ', $f)) . ' must be a number.';
                $values[$f] = 0.0;
                continue;
            }
            $num = (float)$raw;
            if ($num < 0) {
                $errors[] = ucwords(str_replace('_', ' ', $f)) . ' cannot be negative.';
            }
            $values[$f] = $num;
        }

        $unitsRaw = $input['number_of_units'] ?? 0;
        if (!is_numeric($unitsRaw) || (int)$unitsRaw <= 0) {
            $errors[] = 'Number of units must be greater than zero.';
            $units = 0;
        } else {
            $units = (int)$unitsRaw;
        }

        $totalCost = $values['material_cost'] + $values['labour_cost'] + $values['transport_cost']
            + $values['utilities_cost'] + $values['packaging_cost'] + $values['marketing_cost']
            + $values['other_cost'];

        $costPerUnit = $units > 0 ? ($totalCost / $units) : 0.0;
        $selling = $values['selling_price'];
        $profitPerUnit = $selling - $costPerUnit;
        $expectedRevenue = $selling * $units;
        $expectedProfit = $expectedRevenue - $totalCost;
        $profitMargin = $expectedRevenue > 0 ? (($expectedProfit / $expectedRevenue) * 100) : 0.0;
        $breakEven = ($profitPerUnit > 0) ? (int)ceil($totalCost / $profitPerUnit) : null;

        if ($selling < $costPerUnit && $units > 0) {
            $warnings[] = 'Selling price is below cost per unit.';
        }
        if ($profitMargin < 0) {
            $warnings[] = 'Profit margin is negative.';
        }
        if ($units > 100000) {
            $warnings[] = 'Production volume looks unusually high — confirm assumptions.';
        }
        if ($totalCost <= 0) {
            $warnings[] = 'All cost inputs are zero — complete the cost breakdown.';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'data' => [
                'material_cost' => eh_round_money($values['material_cost']),
                'labour_cost' => eh_round_money($values['labour_cost']),
                'transport_cost' => eh_round_money($values['transport_cost']),
                'utilities_cost' => eh_round_money($values['utilities_cost']),
                'packaging_cost' => eh_round_money($values['packaging_cost']),
                'marketing_cost' => eh_round_money($values['marketing_cost']),
                'other_cost' => eh_round_money($values['other_cost']),
                'number_of_units' => $units,
                'total_cost' => eh_round_money($totalCost),
                'cost_per_unit' => eh_round_money($costPerUnit),
                'selling_price' => eh_round_money($selling),
                'profit_per_unit' => eh_round_money($profitPerUnit),
                'expected_revenue' => eh_round_money($expectedRevenue),
                'expected_profit' => eh_round_money($expectedProfit),
                'profit_margin' => eh_round_money($profitMargin),
                'break_even_quantity' => $breakEven,
            ],
        ];
    }
}

if (!function_exists('eh_save_costs')) {
    function eh_save_costs(mysqli $db, int $itemId, array $input): array
    {
        $calc = eh_calculate_costs($input);
        if (!$calc['ok']) {
            return $calc;
        }
        $d = $calc['data'];
        $existing = eh_get_costs($db, $itemId);
        $version = $existing ? ((int)$existing['calculation_version'] + 1) : 1;
        $now = date('Y-m-d H:i:s');

        $mc = (float)$d['material_cost'];
        $lc = (float)$d['labour_cost'];
        $tc = (float)$d['transport_cost'];
        $uc = (float)$d['utilities_cost'];
        $pc = (float)$d['packaging_cost'];
        $mk = (float)$d['marketing_cost'];
        $oc = (float)$d['other_cost'];
        $nu = (int)$d['number_of_units'];
        $tot = (float)$d['total_cost'];
        $cpu = (float)$d['cost_per_unit'];
        $sp = (float)$d['selling_price'];
        $ppu = (float)$d['profit_per_unit'];
        $er = (float)$d['expected_revenue'];
        $ep = (float)$d['expected_profit'];
        $pm = (float)$d['profit_margin'];
        $hasBe = $d['break_even_quantity'] !== null;
        $beQty = $hasBe ? (int)$d['break_even_quantity'] : 0;

        if ($existing) {
            $sql = "UPDATE enterprise_costs SET
                material_cost=?, labour_cost=?, transport_cost=?, utilities_cost=?,
                packaging_cost=?, marketing_cost=?, other_cost=?, number_of_units=?,
                total_cost=?, cost_per_unit=?, selling_price=?, profit_per_unit=?,
                expected_revenue=?, expected_profit=?, profit_margin=?,
                break_even_quantity=?, calculation_version=?, calculated_at=?
                WHERE enterprise_item_id=?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param(
                'dddddddidddddddiisi',
                $mc, $lc, $tc, $uc, $pc, $mk, $oc, $nu, $tot, $cpu, $sp, $ppu, $er, $ep, $pm, $beQty, $version, $now, $itemId
            );
            $stmt->execute();
            $stmt->close();
        } else {
            $sql = "INSERT INTO enterprise_costs (
                enterprise_item_id, material_cost, labour_cost, transport_cost, utilities_cost,
                packaging_cost, marketing_cost, other_cost, number_of_units, total_cost,
                cost_per_unit, selling_price, profit_per_unit, expected_revenue, expected_profit,
                profit_margin, break_even_quantity, calculation_version, calculated_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $stmt = $db->prepare($sql);
            $stmt->bind_param(
                'idddddddidddddddiis',
                $itemId, $mc, $lc, $tc, $uc, $pc, $mk, $oc, $nu, $tot, $cpu, $sp, $ppu, $er, $ep, $pm, $beQty, $version, $now
            );
            $stmt->execute();
            $stmt->close();
        }

        if (!$hasBe) {
            $nullBe = $db->prepare('UPDATE enterprise_costs SET break_even_quantity = NULL WHERE enterprise_item_id = ?');
            $nullBe->bind_param('i', $itemId);
            $nullBe->execute();
            $nullBe->close();
        }

        $u = $db->prepare('UPDATE enterprise_items SET unit_price = ?, updated_at = NOW() WHERE id = ?');
        $u->bind_param('di', $sp, $itemId);
        $u->execute();
        $u->close();

        eh_audit($db, 'enterprise_hub.cost_calculated', ['item_id' => $itemId, 'version' => $version]);
        $calc['saved'] = true;
        return $calc;
    }
}

if (!function_exists('eh_investment_simulator')) {
    /** @param array<string,mixed> $input */
    function eh_investment_simulator(array $input): array
    {
        $errors = [];
        $keys = [
            'current_qty', 'current_price', 'current_cost',
            'proposed_investment', 'expected_qty', 'expected_workers', 'expected_cost',
        ];
        $v = [];
        foreach ($keys as $k) {
            $raw = $input[$k] ?? 0;
            if (!is_numeric($raw) || (float)$raw < 0) {
                $errors[] = ucwords(str_replace('_', ' ', $k)) . ' must be a non-negative number.';
                $v[$k] = 0.0;
            } else {
                $v[$k] = (float)$raw;
            }
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'data' => []];
        }

        $currentRevenue = $v['current_qty'] * $v['current_price'];
        $projectedRevenue = $v['expected_qty'] * $v['current_price'];
        $currentProfit = $currentRevenue - $v['current_cost'];
        $projectedProfit = $projectedRevenue - $v['expected_cost'];
        $prodGrowth = $v['current_qty'] > 0
            ? (($v['expected_qty'] - $v['current_qty']) / $v['current_qty']) * 100 : 0.0;
        $revGrowth = $currentRevenue > 0
            ? (($projectedRevenue - $currentRevenue) / $currentRevenue) * 100 : 0.0;

        return [
            'ok' => true,
            'errors' => [],
            'data' => [
                'current_revenue' => eh_round_money($currentRevenue),
                'projected_revenue' => eh_round_money($projectedRevenue),
                'current_profit' => eh_round_money($currentProfit),
                'projected_profit' => eh_round_money($projectedProfit),
                'production_growth_pct' => eh_round_money($prodGrowth),
                'revenue_growth_pct' => eh_round_money($revGrowth),
                'additional_jobs' => (int)$v['expected_workers'],
                'proposed_investment' => eh_round_money($v['proposed_investment']),
                'is_projection' => true,
                'disclaimer' => 'These figures are projections only and are not guaranteed outcomes.',
            ],
        ];
    }
}
