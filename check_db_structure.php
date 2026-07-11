<?php
require_once('db/connect.php');

echo "=== Database Structure Check ===\n\n";

// Check departments table
echo "DEPARTMENTS TABLE:\n";
$result = $db->query('DESCRIBE departments');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "- {$row['Field']} ({$row['Type']})\n";
    }
    $result->free();
} else {
    echo "Error: " . $db->error . "\n";
}

// Check if deptName column exists
echo "\nChecking for deptName column...\n";
$result = $db->query("SHOW COLUMNS FROM departments LIKE 'deptName'");
if ($result && $result->num_rows > 0) {
    echo "✓ deptName column EXISTS\n";
} else {
    echo "✗ deptName column does NOT exist\n";

    // Show all columns that contain 'name' or 'dept'
    echo "\nSearching for similar column names...\n";
    $result = $db->query("SHOW COLUMNS FROM departments");
    while ($row = $result->fetch_assoc()) {
        $field = strtolower($row['Field']);
        if (strpos($field, 'name') !== false || strpos($field, 'dept') !== false) {
            echo "Found: {$row['Field']} ({$row['Type']})\n";
        }
    }
}

$db->close();
?>
