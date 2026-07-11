<?php
require 'db/connect.php';

// Check current permissions
$result = $db->query('SELECT permission_name, permission_description FROM role_permissions');
echo "Current permissions in database:\n";
while($row = $result->fetch_assoc()) {
    echo "- " . $row['permission_name'] . ": " . $row['permission_description'] . "\n";
}

// Check staff positions and their permissions
echo "\nStaff positions and permissions:\n";
$result = $db->query("
    SELECT sp.PosID, sp.staff_id, rp.permission_name, rp.permission_description
    FROM staff_positions sp
    LEFT JOIN role_permissions rp ON sp.PosID = rp.PosID
    ORDER BY sp.staff_id, rp.permission_name
");

$current_staff = null;
while($row = $result->fetch_assoc()) {
    if ($current_staff !== $row['staff_id']) {
        echo "\nStaff ID: " . $row['staff_id'] . "\n";
        $current_staff = $row['staff_id'];
    }
    if ($row['permission_name']) {
        echo "  - " . $row['permission_name'] . ": " . $row['permission_description'] . "\n";
    } else {
        echo "  - No permissions assigned\n";
    }
}

// Check if eLearning permissions exist
$elearning_permissions = ['elearn_manage_course', 'elearn_admin_all', 'elearn_access'];
echo "\nChecking eLearning permissions:\n";
foreach ($elearning_permissions as $perm) {
    $result = $db->query("SELECT COUNT(*) as count FROM role_permissions WHERE permission_name = '$perm'");
    $row = $result->fetch_assoc();
    echo "- $perm: " . ($row['count'] > 0 ? 'EXISTS' : 'MISSING') . "\n";
}
?>




