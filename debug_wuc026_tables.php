<?php
require_once "db/connect.php";
$staff_id = 'WUC026';

echo "=== staff_positions for $staff_id ===\n";
$res = $db->query("SELECT * FROM staff_positions WHERE staff_id='$staff_id'");
while($row = $res->fetch_assoc()) {
    print_r($row);
}

echo "\n=== positions table matching PosIDs ===\n";
// Grab relevant PosIDs
$posIds = [];
$res = $db->query("SELECT PosID FROM staff_positions WHERE staff_id='$staff_id'");
while($row = $res->fetch_assoc()) {
    $posIds[] = $row['PosID'];
}

if (!empty($posIds)) {
    $in = "'" . implode("','", $posIds) . "'";
    $res = $db->query("SELECT * FROM positions WHERE PosID IN ($in)");
    while($row = $res->fetch_assoc()) {
        print_r($row);
    }
}
?>
