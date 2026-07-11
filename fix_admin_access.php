<?php
require_once 'db/connect.php';

// First, check the staff table structure
echo "<h3>Staff Table Structure</h3>";
$result = $db->query("DESCRIBE staff");
echo "<table border='1' cellpadding='5'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td></tr>";
}
echo "</table><br>";

$staffId = 'WUC026';

// Check if staff exists
echo "<h3>Checking Staff Record</h3>";
$stmt = $db->prepare("SELECT * FROM staff WHERE staff_id = ?");
$stmt->bind_param('s', $staffId);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo "<strong>Staff found!</strong><br>";
    echo "<pre>" . print_r($row, true) . "</pre>";
    
    // Check current access_right
    echo "<h3>Current Access Rights</h3>";
    $stmt2 = $db->prepare("SELECT * FROM access_right WHERE staff_id = ?");
    $stmt2->bind_param('s', $staffId);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    
    if ($row2 = $result2->fetch_assoc()) {
        echo "Already has access: <strong>" . htmlspecialchars($row2['assigned_access']) . "</strong><br><br>";
        echo "<div style='color:green; padding:10px; background:#d4edda; border:1px solid #c3e6cb; border-radius:5px;'>";
        echo "✓ Access rights already exist. Try accessing the eLearning module again.";
        echo "</div><br>";
    } else {
        echo "No access rights found. <strong>Adding Systems Admin access...</strong><br><br>";
        
        // Insert access right
        $insert = $db->prepare("INSERT INTO access_right (staff_id, assigned_access) VALUES (?, 'Systems Admin')");
        $insert->bind_param('s', $staffId);
        
        if ($insert->execute()) {
            echo "<div style='color:green; font-weight:bold; padding:10px; background:#d4edda; border:1px solid #c3e6cb; border-radius:5px;'>";
            echo "✓ SUCCESS! Systems Admin access granted to $staffId<br>";
            echo "You can now access the eLearning module.";
            echo "</div><br>";
        } else {
            echo "<div style='color:red; padding:10px; background:#f8d7da; border:1px solid #f5c6cb; border-radius:5px;'>";
            echo "ERROR: " . htmlspecialchars($db->error);
            echo "</div>";
        }
    }
    
    echo "<br><a href='admin/elearning/index.php' style='padding:10px 20px; background:#007bff; color:white; text-decoration:none; border-radius:5px; display:inline-block;'>Go to eLearning Module</a>";
    
} else {
    echo "<div style='color:red; padding:10px; background:#f8d7da; border:1px solid #f5c6cb; border-radius:5px;'>";
    echo "Staff ID $staffId not found in staff table!";
    echo "</div>";
}
?>
