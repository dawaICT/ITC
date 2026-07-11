<?php
require_once 'db/connect.php';

$query = 'DESCRIBE programs';
$result = $db->query($query);

if ($result) {
    echo "Current programs table structure:\n";
    echo str_repeat('=', 50) . "\n";
    while ($row = $result->fetch_assoc()) {
        echo sprintf('%-20s %-15s %-10s %-10s %-20s',
            $row['Field'], $row['Type'], $row['Null'], $row['Key'], $row['Default'] ?? 'NULL') . "\n";
    }
    $result->free();
} else {
    echo 'Error: ' . $db->error . "\n";
}

$db->close();
?>
