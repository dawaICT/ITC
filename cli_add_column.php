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

// Check if the column already exists
$result = $db->query("SHOW COLUMNS FROM staff LIKE 'profile_image'");
if ($result->num_rows > 0) {
    echo "The profile_image column already exists in the staff table.\n";
} else {
    // Add the profile_image column
    $sql = "ALTER TABLE staff ADD COLUMN profile_image VARCHAR(255) NULL";
    
    if ($db->query($sql) === TRUE) {
        echo "The profile_image column was added successfully to the staff table.\n";
    } else {
        echo "Error adding column: " . $db->error . "\n";
    }
}

// Close connection
$db->close();
?> 