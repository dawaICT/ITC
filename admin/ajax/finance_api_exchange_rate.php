<?php
require_once "../../includes/finance_helpers.php";
ensure_roles($db, ['Systems Admin','Accountant']);

$base = strtoupper(trim($_GET['base'] ?? 'ZMW'));
$quote = strtoupper(trim($_GET['quote'] ?? 'USD'));

$rate = get_exchange_rate($db, $base, $quote);
json_success(['base'=>$base,'quote'=>$quote,'rate'=>$rate]);


