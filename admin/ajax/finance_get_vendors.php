<?php
require_once "../../includes/finance_helpers.php";
ensure_roles($db, ['Accountant','Systems Admin']);

$rows = [];
$res = $db->query("SELECT id, name, tin, bank_account, contact_email, contact_phone, status FROM finance_vendors ORDER BY name");
if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
json_success($rows);


