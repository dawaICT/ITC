<?php
// delete_orphaned_invoices.php

// Database connection
$db = new mysqli('127.0.0.1', 'root', '', 'wucportal');
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error . "\n");
}

$orphaned_student_ids = ['2400001', '2500001', '2500002', '2500003', 'test123', 'TEST12345'];
$placeholders = implode(',', array_fill(0, count($orphaned_student_ids), '?'));

$stmt = $db->prepare("DELETE FROM invoices WHERE student_id IN ($placeholders)");
$stmt->bind_param(str_repeat('s', count($orphaned_student_ids)), ...$orphaned_student_ids);

if ($stmt->execute()) {
    echo "Successfully deleted {$stmt->affected_rows} orphaned invoices.\n";
} else {
    echo "Error deleting orphaned invoices: " . $stmt->error . "\n";
}

$stmt->close();
$db->close();
?>
