<?php
require_once __DIR__ . '/db/connect.php';

echo "Checking database schema...\n";

// Check if 'transfer_document' column exists
$result = $db->query("SHOW COLUMNS FROM student_program LIKE 'transfer_document'");
if ($result && $result->num_rows > 0) {
    echo "Column 'transfer_document' already exists.\n";
} else {
    echo "Adding 'transfer_document' column...\n";
    if ($db->query("ALTER TABLE student_program ADD COLUMN transfer_document VARCHAR(255) NULL AFTER credits_transferred")) {
        echo "Successfully added 'transfer_document' column.\n";
    } else {
        echo "Error adding column: " . $db->error . "\n";
        exit(1);
    }
}

echo "Database schema update complete.\n";
?>
