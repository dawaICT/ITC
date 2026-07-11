<?php
// list_unpaid_invoices.php

// Database connection
$db = new mysqli('127.0.0.1', 'root', '', 'wucportal');
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error . "\n");
}

echo "Listing unpaid invoices...\n";

$result = $db->query("
    SELECT i.id, i.student_id, i.amount, i.due_date, i.status, s.Fname, s.Lname
    FROM invoices i
    LEFT JOIN students s ON i.student_id = s.SID
    WHERE i.status != 'paid'
");

if ($result && $result->num_rows > 0) {
    echo "Found {$result->num_rows} unpaid invoices:\n";
    while ($row = $result->fetch_assoc()) {
        echo "  - Invoice ID: {$row['id']}\n";
        echo "    Student: {$row['Fname']} {$row['Lname']} (SID: {$row['student_id']})\n";
        echo "    Amount: {$row['amount']}\n";
        echo "    Due Date: {$row['due_date']}\n";
        echo "    Status: {$row['status']}\n\n";
    }
} else {
    echo "No unpaid invoices found.\n";
}

$db->close();
?>
