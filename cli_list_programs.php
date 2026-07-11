<?php
// cli_list_programs.php

// Database connection
$db = new mysqli('127.0.0.1', 'root', '', 'wucportal');
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error . "\n");
}

echo "Available programs:\n";

$result = $db->query("SELECT program_code, program_name FROM programs");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "  - {$row['program_code']}: {$row['program_name']}\n";
    }
} else {
    echo "Could not list programs.\n";
}

$db->close();
?>
