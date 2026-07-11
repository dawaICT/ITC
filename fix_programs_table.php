<?php
require_once 'includes/db_connect.php';

echo "Connected to database.\n";

// Drop existing table if it exists
if ($db->query("DROP TABLE IF EXISTS programs")) {
    echo "Dropped existing programs table.\n";
} else {
    echo "Error dropping table: " . $db->error . "\n";
}

// Create the table with correct structure
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
)";

if ($db->query($sql) === TRUE) {
    echo "Programs table created successfully.\n";
} else {
    echo "Error creating table: " . $db->error . "\n";
}

// Verify the table structure
$result = $db->query("SHOW COLUMNS FROM programs");
if ($result) {
    echo "\nTable Structure:\n";
    echo str_repeat('-', 80) . "\n";
    echo sprintf("%-20s %-40s %-7s %s\n", 'Field', 'Type', 'Null', 'Default');
    echo str_repeat('-', 80) . "\n";

    while ($row = $result->fetch_assoc()) {
        echo sprintf("%-20s %-40s %-7s %s\n",
            $row['Field'],
            $row['Type'],
            $row['Null'],
            $row['Default'] ?? 'NULL'
        );
    }
} else {
    echo "Error getting table structure: " . $db->error . "\n";
}

// Test inserting a sample program
$test_sql = "INSERT INTO programs (program_code, program_name, program_type, study_mode, period_mode, duration_months) 
             VALUES ('TST-CS', 'Test Computer Science', 'degree', 'fulltime', 'semester', 48)";

if ($db->query($test_sql)) {
    echo "\nTest record inserted successfully.\n";

    // Verify the inserted record
    $result = $db->query("SELECT * FROM programs WHERE program_code = 'TST-CS'");
    if ($result && $row = $result->fetch_assoc()) {
        echo "\nVerification - Retrieved test record:\n";
        print_r($row);
    }
} else {
    echo "\nError inserting test record: " . $db->error . "\n";
}
?> 