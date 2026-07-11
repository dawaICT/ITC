<?php
require_once 'db/connect.php';

echo "Courses Table Structure:\n";
echo str_repeat("=", 50) . "\n";

$result = $db->query("DESCRIBE courses");
while($row = $result->fetch_assoc()) {
    echo sprintf("%-30s | %-20s\n", $row['Field'], $row['Type']);
}
