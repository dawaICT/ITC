<?php
require_once __DIR__ . '/db/connect.php';

$debug_user = 'WUC026';

echo "Analyzing User: $debug_user\n";

// 1. Get Staff Details and Position correctly using 3 tables
$sql = "SELECT s.staff_id, s.Fname, s.Lname, sp.PosID, p.PosName, ar.assigned_access
        FROM staff s
        LEFT JOIN staff_positions sp ON s.staff_id = sp.staff_id
        LEFT JOIN positions p ON sp.PosID = p.PosID
        LEFT JOIN access_right ar ON s.staff_id = ar.staff_id
        WHERE s.staff_id = ?";

if ($stmt = $db->prepare($sql)) {
    $stmt->bind_param("s", $debug_user);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        print_r($row);
        
        $posID = $row['PosID'];
        $assignedAccess = $row['assigned_access'];
        
        echo "Position: " . ($row['PosName'] ?? 'None') . " (ID: $posID)\n";
        echo "Access Right: $assignedAccess\n";
        
        // 2. Check Permissions for this PosID
        if ($posID) {
            echo "Permissions for PosID ($posID):\n";
            $pSql = "SELECT * FROM role_permissions WHERE PosID = ?";
            $pStmt = $db->prepare($pSql);
            $pStmt->bind_param("s", $posID);
            $pStmt->execute();
            $pRes = $pStmt->get_result();
            
            $count = 0;
            while ($p = $pRes->fetch_assoc()) {
                echo "- " . $p['permission_name'] . "\n";
                $count++;
            }
            if ($count == 0) echo "No specific permissions found in role_permissions.\n";
            
        } else {
            echo "No PosID found for this user in staff_positions table.\n";
        }

    } else {
        echo "User WUC026 not found in staff table.\n";
    }
} else {
    echo "DB Error: " . $db->error . "\n";
}
?>
