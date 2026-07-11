<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once 'includes/db_connect.php';

if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

echo "Database connected successfully\n";

// First check if table exists
$result = $db->query("SHOW TABLES LIKE 'programs'");
if ($result->num_rows == 0) {
    die("Programs table does not exist!");
}

echo "Programs table exists\n";

// Get table structure
$result = $db->query("SHOW CREATE TABLE programs");
if ($result) {
    $row = $result->fetch_row();
    echo "\nTable Structure:\n" . $row[1] . "\n";
} else {
    echo "Error getting table structure: " . $db->error . "\n";
}

// Try to insert a test record
$stmt = $db->prepare("INSERT INTO programs (program_code, program_name, program_type, study_mode, period_mode, duration_months) VALUES (?, ?, ?, ?, ?, ?)");
if (!$stmt) {
    die("Prepare failed: " . $db->error);
}

$code = "TST-CS";
$name = "Test Program";
$type = "degree";
$mode = "fulltime";
$period_mode = "semester";
$duration = 48;

$stmt->bind_param("sssssi", $code, $name, $type, $mode, $period_mode, $duration);
if ($stmt->execute()) {
    echo "\nTest record inserted successfully\n";
} else {
    echo "\nError inserting test record: " . $stmt->error . "\n";
}
?> 