<?php
// describe_invoices_table.php

// Database connection
$db = new mysqli('127.0.0.1', 'root', '', 'wucportal');
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error . "\n");
}

echo "Describing invoices table...\n";

$result = $db->query("DESCRIBE invoices");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "  - {$row['Field']} ({$row['Type']})\n";
    }
} else {
    echo "Could not describe invoices table.\n";
}

$db->close();
?>
