<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once 'db/connect.php';

echo "Starting programs table column fix...\n";

// Function to safely add a column if it doesn't exist
function add_column_if_not_exists($db, $table, $column, $definition) {
    $result = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($result->num_rows == 0) {
        $sql = "ALTER TABLE `$table` ADD COLUMN `$column` $definition";
        if ($db->query($sql)) {
            echo "Added column $column successfully.\n";
        } else {
            echo "Error adding column $column: " . $db->error . "\n";
            return false;
        }
    } else {
        echo "Column $column already exists.\n";
    }
    return true;
}

// Add missing columns
$columns = [
    'program_type' => "ENUM('degree', 'diploma', 'certificate') NOT NULL DEFAULT 'degree' AFTER program_name",
    'study_mode' => "ENUM('fulltime', 'parttime', 'distance') NOT NULL DEFAULT 'fulltime' AFTER program_type",
    'period_mode' => "ENUM('semester', 'term') NOT NULL DEFAULT 'semester' AFTER study_mode",
    'duration_months' => "INT(11) NOT NULL DEFAULT 48 AFTER period_mode"
];

foreach ($columns as $column => $definition) {
    add_column_if_not_exists($db, 'programs', $column, $definition);
}

// Make program_name NOT NULL if it isn't already
$sql = "ALTER TABLE programs MODIFY program_name VARCHAR(255) NOT NULL";
if ($db->query($sql)) {
    echo "Modified program_name to be NOT NULL.\n";
} else {
    echo "Error modifying program_name: " . $db->error . "\n";
}

// Show the updated table structure
echo "\nUpdated table structure:\n";
$result = $db->query("SHOW CREATE TABLE programs");
if ($result) {
    $row = $result->fetch_row();
    echo $row[1] . "\n";
} else {
    echo "Error getting table structure: " . $db->error . "\n";
}

// Try to insert a test record
$sql = "INSERT INTO programs (program_code, program_name, program_type, study_mode, period_mode, duration_months) 
    VALUES ('TST-CS', 'Test Program', 'degree', 'fulltime', 'semester', 48)
        ON DUPLICATE KEY UPDATE 
        program_name = VALUES(program_name),
        program_type = VALUES(program_type),
        study_mode = VALUES(study_mode),
    period_mode = VALUES(period_mode),
        duration_months = VALUES(duration_months)";

if ($db->query($sql)) {
    echo "\nTest record inserted/updated successfully.\n";

    // Verify the record
    $result = $db->query("SELECT * FROM programs WHERE program_code = 'TST-CS'");
    if ($result && $row = $result->fetch_assoc()) {
        echo "Verified record:\n";
        print_r($row);
    }
} else {
    echo "Error inserting/updating test record: " . $db->error . "\n";
}

$db->close();
echo "\nDatabase connection closed.\n";
?> 