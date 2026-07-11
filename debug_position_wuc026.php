<?php
require_once "db/connect.php";
$staff_id = 'WUC026';

echo "Checking staff_positions for $staff_id:\n";
$query = "SELECT p.PosName FROM staff_positions sp 
          INNER JOIN positions p ON sp.PosID = p.PosID 
          WHERE sp.staff_id = '$staff_id'";
$result = $db->query($query);
if ($result) {
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        print_r($row);
    } else {
        echo "No position found for $staff_id\n";
    }
} else {
    echo "Query failed: " . $db->error;
}
?>
