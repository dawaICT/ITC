<?php
require_once "../../includes/finance_helpers.php";
ensure_roles($db, ['Accountant','Systems Admin','Dean','Head of Section']);

$rows = [];
$res = $db->query("SELECT CONCAT(cc.center_type, ' - ', cc.name) AS label, SUM(b.allocated_amount) AS total
                   FROM finance_budgets b JOIN finance_cost_centers cc ON cc.id=b.cost_center_id
                   GROUP BY cc.id, cc.center_type, cc.name ORDER BY total DESC");
if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
json_success($rows);


