<?php
require_once "../../includes/finance_helpers.php";
ensure_roles($db, ['Accountant','Systems Admin','Dean','Head of Section']);

$rows = [];
$res = $db->query("SELECT e.id, v.name AS vendor_name, e.category, e.description, e.amount, e.currency_code, e.status, e.expense_date
                   FROM finance_expenses e JOIN finance_vendors v ON v.id = e.vendor_id
                   ORDER BY e.created_at DESC LIMIT 500");
if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
json_success($rows);


