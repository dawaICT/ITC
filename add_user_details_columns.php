<?php
require_once 'db/connect.php';

// Additional user details columns to add
$columns_to_add = [
    "date_of_birth DATE NULL",
    "secondary_phone VARCHAR(20) NULL",
    "emergency_contact VARCHAR(100) NULL",
    "emergency_phone VARCHAR(20) NULL",
    "employment_date DATE NULL",
    "position VARCHAR(100) NULL"
];

echo "Adding additional user details columns to staff table...\n";

foreach ($columns_to_add as $column_def) {
    // Extract column name from definition
    $column_name = explode(' ', $column_def)[0];

    // Check if column exists
    $result = $db->query("SHOW COLUMNS FROM staff LIKE '$column_name'");
    if ($result->num_rows == 0) {
        $sql = "ALTER TABLE staff ADD COLUMN $column_def";
        if ($db->query($sql)) {
            echo "✓ Added column: $column_name\n";
        } else {
            echo "✗ Error adding $column_name: " . $db->error . "\n";
        }
    } else {
        echo "- Column $column_name already exists\n";
    }
}

$db->close();
echo "Done!\n";
?>