<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once 'includes/db_connect.php';

echo "Checking references to programs table...\n";

// Get all tables that reference programs
$sql = "SELECT 
    TABLE_NAME,
    COLUMN_NAME,
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM
    INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE
    REFERENCED_TABLE_SCHEMA = 'wucportal'
    AND REFERENCED_TABLE_NAME = 'programs'";

$result = $db->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "\nFound reference:\n";
        echo "Table: " . $row['TABLE_NAME'] . "\n";
        echo "Column: " . $row['COLUMN_NAME'] . "\n";
        echo "Constraint: " . $row['CONSTRAINT_NAME'] . "\n";
        echo "References: " . $row['REFERENCED_TABLE_NAME'] . "(" . $row['REFERENCED_COLUMN_NAME'] . ")\n";
    }
} else {
    echo "Error checking references: " . $db->error . "\n";
}

// Get the current structure of programs table
echo "\nCurrent programs table structure:\n";
$result = $db->query("SHOW CREATE TABLE programs");
if ($result) {
    $row = $result->fetch_row();
    echo $row[1] . "\n";
} else {
    echo "Error getting table structure: " . $db->error . "\n";
}

$db->close();
?> 