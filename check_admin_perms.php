<?php
require_once __DIR__ . '/db/connect.php';

$posID = 'ADM009';
echo "Checking Permissions for PosID: $posID\n";

$pSql = "SELECT * FROM role_permissions WHERE PosID = ?";
if ($pStmt = $db->prepare($pSql)) {
    $pStmt->bind_param("s", $posID);
    $pStmt->execute();
    $pRes = $pStmt->get_result();
    
    $count = 0;
    while ($p = $pRes->fetch_assoc()) {
        echo "- " . $p['permission_name'] . "\n";
        $count++;
    }
    if ($count == 0) {
        echo "No permissions found for ADM009.\n";
    }
} else {
    echo "DB Error: " . $db->error . "\n";
}
?>
