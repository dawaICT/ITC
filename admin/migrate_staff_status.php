<?php
require '../db/connect.php';

echo "Adding status column to staff table...\n";
$sql = "ALTER TABLE staff ADD COLUMN status ENUM('Active', 'Deleted') DEFAULT 'Active'";
if ($db->query($sql)) {
    echo "SUCCESS: Column 'status' added.\n";
} else {
    // Check if duplicate column error
    if (strpos($db->error, "Duplicate column") !== false) {
        echo "INFO: Column 'status' already exists.\n";
    } else {
        echo "ERROR: " . $db->error . "\n";
    }
}
?>
