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

// Check and add is_transfer column
$result = $db->query("SHOW COLUMNS FROM student_program LIKE 'is_transfer'");
if ($result->num_rows > 0) {
    echo "The is_transfer column already exists in the student_program table.\n";
} else {
    $sql = "ALTER TABLE student_program ADD COLUMN is_transfer TINYINT(1) DEFAULT 0";
    
    if ($db->query($sql) === TRUE) {
        echo "The is_transfer column was added successfully to the student_program table.\n";
    } else {
        echo "Error adding is_transfer column: " . $db->error . "\n";
    }
}

// Check and add previous_institution column
$result = $db->query("SHOW COLUMNS FROM student_program LIKE 'previous_institution'");
if ($result->num_rows > 0) {
    echo "The previous_institution column already exists in the student_program table.\n";
} else {
    $sql = "ALTER TABLE student_program ADD COLUMN previous_institution VARCHAR(255) NULL";
    
    if ($db->query($sql) === TRUE) {
        echo "The previous_institution column was added successfully to the student_program table.\n";
    } else {
        echo "Error adding previous_institution column: " . $db->error . "\n";
    }
}

// Check and add credits_transferred column
$result = $db->query("SHOW COLUMNS FROM student_program LIKE 'credits_transferred'");
if ($result->num_rows > 0) {
    echo "The credits_transferred column already exists in the student_program table.\n";
} else {
    $sql = "ALTER TABLE student_program ADD COLUMN credits_transferred INT NULL";
    
    if ($db->query($sql) === TRUE) {
        echo "The credits_transferred column was added successfully to the student_program table.\n";
    } else {
        echo "Error adding credits_transferred column: " . $db->error . "\n";
    }
}

// Close connection
$db->close();
?>