<?php
// Set up logging
$logFile = __DIR__ . '/rebuild_log.txt';
file_put_contents($logFile, "Starting programs table rebuild at " . date('Y-m-d H:i:s') . "\n");

function log_message($message) {
    global $logFile;
    $message = date('Y-m-d H:i:s') . " - " . $message . "\n";
    file_put_contents($logFile, $message, FILE_APPEND);
    echo $message;
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('error_log', $logFile);

log_message("Starting programs table rebuild...");

require_once 'includes/db_connect.php';

if ($db->connect_error) {
    log_message("Connection failed: " . $db->connect_error);
    die();
}

log_message("Connected to database successfully.");

// Drop table if exists
$sql = "DROP TABLE IF EXISTS programs";
if ($db->query($sql)) {
    log_message("Existing programs table dropped successfully.");
} else {
    log_message("Error dropping table: " . $db->error);
    die();
}

// Create new table
$sql = "CREATE TABLE programs (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    program_code VARCHAR(20) NOT NULL UNIQUE,
    program_name VARCHAR(255) NOT NULL,
    program_type ENUM('degree', 'diploma', 'certificate') NOT NULL,
    study_mode ENUM('fulltime', 'parttime', 'distance') NOT NULL,
    period_mode ENUM('semester', 'term') NOT NULL DEFAULT 'semester',
    duration_months INT(11) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($db->query($sql)) {
    log_message("Programs table created successfully.");
} else {
    log_message("Error creating table: " . $db->error);
    die();
}

// Verify table structure
$result = $db->query("SHOW CREATE TABLE programs");
if ($result) {
    $row = $result->fetch_row();
    log_message("\nVerified table structure:\n" . $row[1]);
} else {
    log_message("Error getting table structure: " . $db->error);
}

// Insert test record
$sql = "INSERT INTO programs (program_code, program_name, program_type, study_mode, period_mode, duration_months) 
    VALUES ('TST-CS', 'Test Program', 'degree', 'fulltime', 'semester', 48)";

if ($db->query($sql)) {
    log_message("Test record inserted successfully.");

    // Verify the record
    $result = $db->query("SELECT * FROM programs WHERE program_code = 'TST-CS'");
    if ($result && $row = $result->fetch_assoc()) {
        log_message("\nVerified inserted record:\n" . print_r($row, true));
    }
} else {
    log_message("Error inserting test record: " . $db->error);
}

$db->close();
log_message("Database connection closed.");

log_message("Script completed. Check the full log at: " . $logFile);
?> 