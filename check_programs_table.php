<?php
require 'db/connect.php';

$result = $db->query('DESCRIBE programs');
echo "Programs table structure:\n";
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . "\n";
}
?>