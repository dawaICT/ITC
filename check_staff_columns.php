<?php
require_once 'db/connect.php';

echo "Staff Table Structure:\n";
echo str_repeat("=", 50) . "\n";

$result = $db->query("DESCRIBE staff");
while($row = $result->fetch_assoc()) {
    echo sprintf("%-20s | %-20s\n", $row['Field'], $row['Type']);
}
