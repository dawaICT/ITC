<?php
require_once 'db/connect.php';

echo "Adding missing columns to students table...\n";

$columns_to_add = [
    "dob DATE",
    "country VARCHAR(100)",
    "h_addre TEXT",
    "p_addre TEXT",
    "status VARCHAR(50) DEFAULT 'Active'",
    "sponsor VARCHAR(100)",
    "next_kin VARCHAR(100)",
    "next_kin_mobile VARCHAR(20)",
    "relat VARCHAR(50)",
    "dte_adm DATETIME"
];

// Get existing columns
$existing_columns = [];
$result = $db->query("DESCRIBE students");
while ($row = $result->fetch_assoc()) {
    $existing_columns[] = $row['Field'];
}

foreach ($columns_to_add as $column_def) {
    $column_name = explode(' ', $column_def)[0];
    if (!in_array($column_name, $existing_columns)) {
        $alter_sql = "ALTER TABLE students ADD COLUMN $column_def";
        if ($db->query($alter_sql)) {
            echo "Added column: $column_name\n";
        } else {
            echo "Error adding column $column_name: " . $db->error . "\n";
        }
    } else {
        echo "Column $column_name already exists.\n";
    }
}

echo "Update completed.\n";
$db->close();
?>