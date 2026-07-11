<?php
// check_invoice_student_link.php

// Database connection
$db = new mysqli('127.0.0.1', 'root', '', 'wucportal');
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error . "\n");
}

echo "Checking for orphaned invoice records...\n\n";

$result = $db->query("
    SELECT i.id, i.student_id
    FROM invoices i
    LEFT JOIN students s ON i.student_id = s.SID
    WHERE s.SID IS NULL
");

if ($result && $result->num_rows > 0) {
    echo "Found {$result->num_rows} invoices with no matching student:\n";
    while ($row = $result->fetch_assoc()) {
        echo "  - Invoice ID: {$row['id']}, Student ID: {$row['student_id']}\n";
    }
} else {
    echo "No orphaned invoice records found.\n";
}

$db->close();
?>
