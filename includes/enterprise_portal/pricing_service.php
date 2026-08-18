<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/enterprise_hub/cost_calculator.php';

if (!function_exists('ep_calculate_costs')) {
    /** @param array<string,mixed> $input */
    function ep_calculate_costs(array $input): array
    {
        return eh_calculate_costs($input);
    }
}

if (!function_exists('ep_save_opportunity_costs')) {
    /** @param array<string,mixed> $input */
    function ep_save_opportunity_costs(mysqli $db, int $opportunityId, array $input): array
    {
        $calc = ep_calculate_costs($input);
        if (!$calc['ok']) {
            return ['ok' => false, 'message' => implode(' ', $calc['errors']), 'calc' => $calc];
        }
        $d = $calc['data'];
        $now = date('Y-m-d H:i:s');
        $units = (int)$d['number_of_units'];
        $be = $d['break_even_quantity'] !== null ? (int)$d['break_even_quantity'] : null;
        $m1 = (float)$d['material_cost'];
        $m2 = (float)$d['labour_cost'];
        $m3 = (float)$d['transport_cost'];
        $m4 = (float)$d['utilities_cost'];
        $m5 = (float)$d['packaging_cost'];
        $m6 = (float)$d['marketing_cost'];
        $m7 = (float)$d['other_cost'];
        $t1 = (float)$d['total_cost'];
        $t2 = (float)$d['cost_per_unit'];
        $t3 = (float)$d['selling_price'];
        $t4 = (float)$d['profit_per_unit'];
        $t5 = (float)$d['expected_revenue'];
        $t6 = (float)$d['expected_profit'];
        $t7 = (float)$d['profit_margin'];

        $sql = "INSERT INTO enterprise_opportunity_costs (
            enterprise_opportunity_id, material_cost, labour_cost, transport_cost, utilities_cost,
            packaging_cost, marketing_cost, other_cost, number_of_units, total_cost, cost_per_unit,
            selling_price, profit_per_unit, expected_revenue, expected_profit, profit_margin,
            break_even_quantity, calculation_version, calculated_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)
        ON DUPLICATE KEY UPDATE
            material_cost=VALUES(material_cost), labour_cost=VALUES(labour_cost), transport_cost=VALUES(transport_cost),
            utilities_cost=VALUES(utilities_cost), packaging_cost=VALUES(packaging_cost), marketing_cost=VALUES(marketing_cost),
            other_cost=VALUES(other_cost), number_of_units=VALUES(number_of_units), total_cost=VALUES(total_cost),
            cost_per_unit=VALUES(cost_per_unit), selling_price=VALUES(selling_price), profit_per_unit=VALUES(profit_per_unit),
            expected_revenue=VALUES(expected_revenue), expected_profit=VALUES(expected_profit), profit_margin=VALUES(profit_margin),
            break_even_quantity=VALUES(break_even_quantity), calculation_version=calculation_version+1, calculated_at=VALUES(calculated_at)";
        $stmt = $db->prepare($sql);
        $stmt->bind_param(
            'idddddddidddddddis',
            $opportunityId, $m1, $m2, $m3, $m4, $m5, $m6, $m7, $units,
            $t1, $t2, $t3, $t4, $t5, $t6, $t7, $be, $now
        );
        $stmt->execute();
        $stmt->close();
        ep_audit($db, 'enterprise_portal.costs_saved', ['opportunity_id' => $opportunityId]);
        return ['ok' => true, 'message' => 'Cost calculation saved.', 'calc' => $calc];
    }
}

if (!function_exists('ep_get_opportunity_costs')) {
    function ep_get_opportunity_costs(mysqli $db, int $opportunityId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM enterprise_opportunity_costs WHERE enterprise_opportunity_id = ? LIMIT 1');
        $stmt->bind_param('i', $opportunityId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}
