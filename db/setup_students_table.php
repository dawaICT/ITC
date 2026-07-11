<?php
require_once '../connect.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '0');

try {
    // Read the SQL file
    $sql = file_get_contents('create_students_table.sql');

    // Execute multiple SQL statements
    if ($db->multi_query($sql)) {
        do {
            // Store first result set
            if ($result = $db->store_result()) {
                $result->free();
            }
            // Prepare next result set
        } while ($db->more_results() && $db->next_result());

        echo "Students table created successfully!";
    } else {
        throw new Exception("Error creating tables: " . $db->error);
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

$db->close();
?> 