<?php
require 'db/connect.php';
$result = $db->query('SELECT s.staff_id, s.Fname, p.PosName FROM staff s LEFT JOIN staff_positions sp ON s.staff_id = sp.staff_id LEFT JOIN positions p ON sp.PosID = p.PosID LIMIT 5');
echo "Staff positions:\n";
while($row = $result->fetch_assoc()) {
    echo $row['staff_id'] . ' (' . $row['Fname'] . '): ' . ($row['PosName'] ?? 'No position') . "\n";
}
?>