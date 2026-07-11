<?php
require 'db/connect.php';
$result = $db->query('SELECT * FROM positions LIMIT 10');
echo "Positions in database:\n";
while($row = $result->fetch_assoc()) {
    echo $row['PosID'] . ': ' . $row['PosName'] . "\n";
}
?>