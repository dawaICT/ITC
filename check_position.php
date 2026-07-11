<?php
require 'c:/xampp/htdocs/wucportal/db/connect.php';

$result = $db->query('SELECT p.PosName FROM staff_positions sp INNER JOIN positions p ON sp.PosID = p.PosID WHERE sp.staff_id = "WUC015" LIMIT 1');
if ($result && $row = $result->fetch_assoc()) {
    echo 'Position: ' . $row['PosName'] . PHP_EOL;
} else {
    echo 'Position not found' . PHP_EOL;
}
?>