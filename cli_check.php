<?php
// Define IS_SCRIPT to indicate this is a standalone script
define('IS_SCRIPT', true);

// Include the database connection
require_once 'db/connect.php';

// Check connection
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

echo "Database connection successful\n";

// Check if the staff table exists
$result = $db->query("SHOW TABLES LIKE 'staff'");
if ($result->num_rows > 0) {
    echo "Staff table exists\n";
    
    // Check the structure of the staff table
    $result = $db->query("DESCRIBE staff");
    echo "=== Staff Table Structure ===\n";
    
    $profile_image_exists = false;
    
    while ($row = $result->fetch_assoc()) {
        echo "Field: " . str_pad($row['Field'], 15) . " | Type: " . str_pad($row['Type'], 15) . 
             " | Null: " . str_pad($row['Null'], 5) . " | Key: " . str_pad($row['Key'], 5) . "\n";
        
        if ($row['Field'] == 'profile_image') {
            $profile_image_exists = true;
        }
    }
    
    echo "\n";
    
    if (!$profile_image_exists) {
        echo "The profile_image column does not exist in the staff table.\n";
        echo "SQL to add the column: ALTER TABLE staff ADD COLUMN profile_image VARCHAR(255) NULL;\n";
    } else {
        echo "The profile_image column exists in the staff table.\n";
    }
    
} else {
    echo "Staff table does not exist!\n";
}

// Close connection
$db->close();
?> 