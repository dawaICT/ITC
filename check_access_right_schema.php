<?php
require_once 'includes/db_connect.php';

echo "Checking access_right table structure:\n";
echo str_repeat("=", 80) . "\n";

$result = $db->query("DESCRIBE access_right");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        printf("%-20s | %-20s | %-5s | %-10s | %-10s\n", 
            $row['Field'], 
            $row['Type'], 
            $row['Null'], 
            $row['Key'],
            $row['Default'] ?? 'NULL'
        );
    }
    echo str_repeat("=", 80) . "\n";
} else {
    echo "Error: " . $db->error . "\n";
}

// Check for any records with empty UserID
echo "\nChecking for empty UserID records:\n";
$empty_check = $db->query("SELECT * FROM access_right WHERE UserID = '' OR UserID IS NULL LIMIT 5");
if ($empty_check) {
    echo "Found " . $empty_check->num_rows . " records with empty UserID\n";
    while ($row = $empty_check->fetch_assoc()) {
        print_r($row);
    }
}
?>
