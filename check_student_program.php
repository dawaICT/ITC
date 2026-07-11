<?php
echo "Starting script...\n";
require_once "db/connect.php";
echo "Database connection file loaded.\n";

if (!isset($db) || !$db) {
    echo 'Database connection failed.';
    exit(1);
}

echo "Database connection successful.\n";
echo "Checking student_program table structure:\n";
$result = $db->query('DESCRIBE student_program');

if ($result) {
    echo "Columns in student_program table:\n";
    while ($row = $result->fetch_assoc()) {
        echo "- {$row['Field']}: {$row['Type']} {$row['Null']} {$row['Key']} {$row['Default']}\n";
    }
} else {
    echo 'Error: ' . $db->error;
}

$db->close();
echo "Script completed.\n";
?>
