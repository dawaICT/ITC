<?php
require_once 'includes/db_connect.php';

echo "Checking UserID vs staff_id relationship:\n";
$result = $db->query("SELECT id, staff_id, UserID, assigned_access FROM access_right WHERE UserID != '' LIMIT 10");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        printf("staff_id: %-10s | UserID: %-10s | Role: %-20s | Match: %s\n", 
            $row['staff_id'], 
            $row['UserID'],
            $row['assigned_access'],
            ($row['staff_id'] === $row['UserID']) ? 'YES' : 'NO'
        );
    }
}
?>
