<?php
require_once __DIR__ . '/db/connect.php';

$staff_id = 'WUC026';
$new_pos = 'ADM009';
$new_access = 'System Administrator'; // Standardize the string if needed

echo "Updating $staff_id to Position $new_pos...\n";

// 1. Update Position
$check = $db->query("SELECT * FROM staff_positions WHERE staff_id = '$staff_id'");
if ($check->num_rows > 0) {
    $sql = "UPDATE staff_positions SET PosID = ? WHERE staff_id = ?";
} else {
    $sql = "INSERT INTO staff_positions (PosID, staff_id) VALUES (?, ?)";
}

if ($stmt = $db->prepare($sql)) {
    $stmt->bind_param("ss", $new_pos, $staff_id);
    if ($stmt->execute()) {
        echo "Position updated successfully.\n";
    } else {
        echo "Error updating position: " . $stmt->error . "\n";
    }
}

// 2. Update Access Right (Permissions table logic relies on PosID, but sidebar relies on this)
// Let's ensure it maps to 'System Administrator' which is a known role key often used.
echo "Updating access_right...\n";
$sql2 = "UPDATE access_right SET assigned_access = ? WHERE staff_id = ?";
if ($stmt2 = $db->prepare($sql2)) {
    $stmt2->bind_param("ss", $new_access, $staff_id);
    if ($stmt2->execute()) {
        echo "Access Right updated successfully.\n";
    } else {
        echo "Error updating access right: " . $stmt2->error . "\n";
    }
}

echo "Done.\n";
?>
