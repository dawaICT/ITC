<?php
require_once "../../includes/finance_helpers.php";
ensure_roles($db, ['Accountant','Systems Admin','Dean','Head of Section']);

// Gracefully handle missing tables/schemas
$tableExists = function(mysqli $db, string $table): bool {
    $like = $db->real_escape_string($table);
    if ($res = $db->query("SHOW TABLES LIKE '{$like}'")) { $exists = $res->num_rows > 0; $res->free(); return $exists; }
    return false;
};

if (!$tableExists($db, 'finance_budgets') || !$tableExists($db, 'finance_cost_centers')) {
    json_success([]);
}

$rows = [];
$sql = "SELECT b.id, b.period_year, b.period_term, b.allocated_amount, b.updated_at, 
               CONCAT(cc.center_type, ' - ', cc.name) AS center_name
        FROM finance_budgets b 
        JOIN finance_cost_centers cc ON cc.id = b.cost_center_id
        ORDER BY b.updated_at DESC LIMIT 500";
$res = $db->query($sql);
if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
json_success($rows);


