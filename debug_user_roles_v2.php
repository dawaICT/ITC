<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit; // CLI diagnostic only — never serve role dumps over the web.
}
require 'db/connect.php';

echo "Searching for user WUC026...\n";
$staff_id = 'WUC026';

// Check roles in staff_positions with IDs
echo "Roles (staff_positions) with IDs:\n";
$sql = "SELECT sp.PosID, p.PosName 
        FROM staff_positions sp 
        JOIN positions p ON sp.PosID = p.PosID 
        WHERE sp.staff_id = ? 
        ORDER BY sp.PosID ASC";
$stmt = $db->prepare($sql);
$stmt->bind_param('s', $staff_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    echo "  [ID: " . $row['PosID'] . "] " . $row['PosName'] . "\n";
}

// Predict primary session role
echo "\nPredicting primary session role...\n";
$stmt->execute(); // Re-execute
$res = $stmt->get_result();
if ($first = $res->fetch_assoc()) {
    echo "  First role found: " . $first['PosName'] . " (ID: " . $first['PosID'] . ")\n";
    $role = $first['PosName'];
    $roleNorm = strtolower(trim((string)$role));
    $map = [
        'systems admin' => 'systems_admin',
        'system administrator' => 'systems_admin',
        'superadmin' => 'systems_admin',
        'super admin' => 'systems_admin',
        'admin' => 'systems_admin',
        'administrator' => 'systems_admin',
        'accountant' => 'accountant',
        // ... (rest of map)
    ];
    $mapped = $map[$roleNorm] ?? $roleNorm;
    echo "  Mapped to Session Role: " . $mapped . "\n";
}

// Check admin/includes/admin.php isAdmin logic simulation
echo "\nChecking isAdmin() logic...\n";
$stmt->execute();
$res = $stmt->get_result();
$isAdmin = false;
while ($row = $res->fetch_assoc()) {
    $posName = strtolower(trim($row['PosName']));
    if (in_array($posName, ['systems admin', 'system administrator', 'admin', 'administrator', 'superadmin', 'super admin', 'administration', 'administrative', 'manager', 'director'])) {
        $isAdmin = true;
        break;
    }
}
echo "  isAdmin() result: " . ($isAdmin ? 'TRUE' : 'FALSE') . "\n";
?>
