<?php
require_once "connect.php";

// Read and execute SQL file
$sql = file_get_contents(__DIR__ . '/finance_tables.sql');

// Split SQL file into individual statements
$statements = array_filter(array_map('trim', explode(';', $sql)));

$success = true;
$errors = [];

// Execute each statement
foreach ($statements as $statement) {
    if (!empty($statement)) {
        try {
            if (!$db->query($statement)) {
                $success = false;
                $errors[] = "Error executing statement: " . $db->error;
            }
        } catch (Exception $e) {
            $success = false;
            $errors[] = "Exception: " . $e->getMessage();
        }
    }
}

// Output results
if ($success) {
    echo "Successfully set up finance tables!\n";
} else {
    echo "Errors occurred while setting up finance tables:\n";
    foreach ($errors as $error) {
        echo "- $error\n";
    }
}

$db->close(); 