<?php
require 'db/connect.php';

echo "Checking Accounts Access for WUC026...\n";
$_SESSION['staff_id'] = 'WUC026';
$_SESSION['role'] = 'accountant'; // Simulated

// Check tables
$tables = ['payments', 'pending_payments'];
foreach ($tables as $t) {
    $res = $db->query("SHOW TABLES LIKE '$t'");
    echo "Table '$t' exists: " . ($res->num_rows > 0 ? 'YES' : 'NO') . "\n";
}

// Check counts
if ($res = $db->query("SHOW TABLES LIKE 'payments'")) {
    if ($res->num_rows > 0) {
        $count = $db->query("SELECT COUNT(*) as c FROM payments")->fetch_object()->c;
        echo "Total Payments: $count\n";
    }
}
if ($res = $db->query("SHOW TABLES LIKE 'pending_payments'")) {
    if ($res->num_rows > 0) {
        $count = $db->query("SELECT COUNT(*) as c FROM pending_payments")->fetch_object()->c;
        echo "Pending Payments: $count\n";
    }
}

// Check for specific recent errors in error log related to accounts?
?>
