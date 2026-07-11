<?php
require "db/connect.php";

echo "Starting migration to add transfer_program and transfer_letter columns...\n";

// Check if columns exist before adding
$check_query = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_NAME = 'students' AND COLUMN_NAME IN ('transfer_program', 'transfer_letter')";
$result = mysqli_query($db, $check_query);
$existing_columns = [];
while ($row = mysqli_fetch_assoc($result)) {
    $existing_columns[] = $row['COLUMN_NAME'];
}

// Add transfer_program if it doesn't exist
if (!in_array('transfer_program', $existing_columns)) {
    $add_program = "ALTER TABLE students ADD COLUMN transfer_program VARCHAR(255) NULL DEFAULT NULL AFTER transfer_credits";
    if (mysqli_query($db, $add_program)) {
        echo "✓ Added transfer_program column\n";
    } else {
        echo "✗ Error adding transfer_program: " . mysqli_error($db) . "\n";
    }
} else {
    echo "✓ transfer_program column already exists\n";
}

// Add transfer_letter if it doesn't exist
if (!in_array('transfer_letter', $existing_columns)) {
    $add_letter = "ALTER TABLE students ADD COLUMN transfer_letter TEXT NULL DEFAULT NULL AFTER transfer_program";
    if (mysqli_query($db, $add_letter)) {
        echo "✓ Added transfer_letter column\n";
    } else {
        echo "✗ Error adding transfer_letter: " . mysqli_error($db) . "\n";
    }
} else {
    echo "✓ transfer_letter column already exists\n";
}

// Verify columns were added
$verify_query = "SHOW COLUMNS FROM students WHERE Field IN ('transfer_program', 'transfer_letter')";
$verify_result = mysqli_query($db, $verify_query);
echo "\nVerifying columns:\n";
while ($row = mysqli_fetch_assoc($verify_result)) {
    echo "  - " . $row['Field'] . " (" . $row['Type'] . ")\n";
}

echo "\nMigration completed successfully!\n";
mysqli_close($db);
?>
