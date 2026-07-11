<?php
require_once 'includes/db_connect.php';

echo "All access_right records:\n";
$result = $db->query("SELECT id, staff_id, UserID, assigned_access FROM access_right ORDER BY id");
if ($result) {
    echo "Total records: " . $result->num_rows . "\n\n";
    while ($row = $result->fetch_assoc()) {
        printf("ID: %-3s | staff_id: %-10s | UserID: %-10s | Role: %-20s\n", 
            $row['id'], 
            $row['staff_id'], 
            $row['UserID'] ?: '(empty)',
            $row['assigned_access']
        );
    }
}
?>
