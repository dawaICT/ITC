<?php
// inspect_unpaid_invoices.php

// Database connection
$db = new mysqli('127.0.0.1', 'root', '', 'wucportal');
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error . "\n");
}

echo "Inspecting unpaid invoices...\n\n";

$result = $db->query("
    SELECT i.id, i.student_id, s.Fname, s.Lname, i.amount, i.date_generated, i.status
    FROM invoices i
    LEFT JOIN students s ON i.student_id = s.SID
    WHERE i.status != 'paid'
");

if ($result && $result->num_rows > 0) {
    echo "Found {$result->num_rows} unpaid invoices:\n";
    while ($row = $result->fetch_assoc()) {
        echo "----------------------------------------\n";
        echo "  Invoice ID: {$row['id']}\n";
        echo "  Student ID: {$row['student_id']}\n";
        echo "  Student Name: {$row['Fname']} {$row['Lname']}\n";
        echo "  Amount: {$row['amount']}\n";
        echo "  Due Date: {$row['date_generated']}\n";
        echo "  Status: {$row['status']}\n";
    }
} else {
    echo "No unpaid invoices found.\n";
}

$db->close();
?>
