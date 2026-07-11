<?php
require_once('db/connect.php');

echo "=== Testing Department Column ===\n\n";

// Test 1: Check if department_name column exists
echo "1. Checking if 'department_name' column exists...\n";
$result = $db->query("SHOW COLUMNS FROM departments LIKE 'department_name'");
if ($result && $result->num_rows > 0) {
    echo "✓ Column 'department_name' EXISTS\n";
    $col_info = $result->fetch_assoc();
    echo "  Type: {$col_info['Type']}\n";
    echo "  Null: {$col_info['Null']}\n";
} else {
    echo "✗ Column 'department_name' does NOT exist\n";
}

// Test 2: Try to select from the column
echo "\n2. Testing SELECT query...\n";
$result = $db->query("SELECT deptId, department_name FROM departments LIMIT 3");
if ($result) {
    echo "✓ SELECT query successful\n";
    echo "Sample data:\n";
    while ($row = $result->fetch_assoc()) {
        echo "  ID: {$row['deptId']}, Name: {$row['department_name']}\n";
    }
} else {
    echo "✗ SELECT query failed: " . $db->error . "\n";
}

// Test 3: Test JOIN query like in staff.php
echo "\n3. Testing JOIN query (like staff.php)...\n";
$result = $db->query("SELECT staff.staff_id, staff.Fname, departments.department_name
                     FROM staff
                     LEFT JOIN departments ON staff.deptId = departments.deptId
                     LIMIT 3");
if ($result) {
    echo "✓ JOIN query successful\n";
    echo "Sample joined data:\n";
    while ($row = $result->fetch_assoc()) {
        echo "  Staff: {$row['Fname']} ({$row['staff_id']}), Dept: {$row['department_name']}\n";
    }
} else {
    echo "✗ JOIN query failed: " . $db->error . "\n";
}

$db->close();
echo "\n=== Test Complete ===";
?>
