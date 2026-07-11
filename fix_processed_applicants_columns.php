<?php
require_once 'db/connect.php';

echo "Updating processed_applicants table to match online_applicants structure...\n";

// Check if table exists
$result = $db->query("SHOW TABLES LIKE 'processed_applicants'");
if ($result->num_rows == 0) {
    echo "Table processed_applicants does not exist. Creating it...\n";
    $create_sql = "CREATE TABLE processed_applicants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(10),
        Fname VARCHAR(50) NOT NULL,
        Lname VARCHAR(50) NOT NULL,
        sex ENUM('Male', 'Female') NOT NULL,
        nrc_pass VARCHAR(50),
        country VARCHAR(50),
        dob DATE,
        mobile VARCHAR(20) NOT NULL,
        email VARCHAR(100) NOT NULL,
        status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
        h_addre TEXT,
        p_addre TEXT,
        sponsor VARCHAR(100),
        next_kin VARCHAR(100),
        next_kin_mobile VARCHAR(20),
        relat VARCHAR(50),
        program VARCHAR(100),
        intake VARCHAR(100),
        mode VARCHAR(50),
        year VARCHAR(10),
        results VARCHAR(255),
        nrc_file VARCHAR(255),
        deposit_slip VARCHAR(255),
        dte_adm TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_dte_adm (dte_adm)
    )";
    if ($db->query($create_sql)) {
        echo "Table created successfully.\n";
    } else {
        die("Error creating table: " . $db->error . "\n");
    }
} else {
    echo "Table exists. Checking and updating structure...\n";

    // Get existing columns
    $existing_columns = [];
    $result = $db->query("DESCRIBE processed_applicants");
    while ($row = $result->fetch_assoc()) {
        $existing_columns[$row['Field']] = $row;
    }

    // Desired structure
    $desired_columns = [
        'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
        'title' => 'VARCHAR(10)',
        'Fname' => 'VARCHAR(50) NOT NULL',
        'Lname' => 'VARCHAR(50) NOT NULL',
        'sex' => "ENUM('Male', 'Female') NOT NULL",
        'nrc_pass' => 'VARCHAR(50)',
        'country' => 'VARCHAR(50)',
        'dob' => 'DATE',
        'mobile' => 'VARCHAR(20) NOT NULL',
        'email' => 'VARCHAR(100) NOT NULL',
        'status' => "ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending'",
        'h_addre' => 'TEXT',
        'p_addre' => 'TEXT',
        'sponsor' => 'VARCHAR(100)',
        'next_kin' => 'VARCHAR(100)',
        'next_kin_mobile' => 'VARCHAR(20)',
        'relat' => 'VARCHAR(50)',
        'program' => 'VARCHAR(100)',
        'intake' => 'VARCHAR(100)',
        'mode' => 'VARCHAR(50)',
        'year' => 'VARCHAR(10)',
        'results' => 'VARCHAR(255)',
        'nrc_file' => 'VARCHAR(255)',
        'deposit_slip' => 'VARCHAR(255)',
        'dte_adm' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
    ];

    foreach ($desired_columns as $column => $definition) {
        if (!isset($existing_columns[$column])) {
            $alter_sql = "ALTER TABLE processed_applicants ADD COLUMN `$column` $definition";
            if ($db->query($alter_sql)) {
                echo "Added column: $column\n";
            } else {
                echo "Error adding column $column: " . $db->error . "\n";
            }
        } else {
            // Check if type matches (basic check)
            $current_type = strtoupper($existing_columns[$column]['Type']);
            $desired_type = strtoupper($definition);
            if (strpos($current_type, 'VARCHAR(12)') !== false && $column == 'Fname') {
                $alter_sql = "ALTER TABLE processed_applicants MODIFY COLUMN `$column` $definition";
                if ($db->query($alter_sql)) {
                    echo "Modified column: $column\n";
                } else {
                    echo "Error modifying column $column: " . $db->error . "\n";
                }
            }
            // Add more checks as needed
        }
    }

    // Add indexes if not exist
    $index_checks = [
        'idx_status' => 'status',
        'idx_dte_adm' => 'dte_adm'
    ];
    foreach ($index_checks as $index_name => $column) {
        $index_result = $db->query("SHOW INDEX FROM processed_applicants WHERE Key_name = '$index_name'");
        if ($index_result->num_rows == 0) {
            $alter_sql = "ALTER TABLE processed_applicants ADD INDEX `$index_name` (`$column`)";
            if ($db->query($alter_sql)) {
                echo "Added index: $index_name\n";
            } else {
                echo "Error adding index $index_name: " . $db->error . "\n";
            }
        }
    }
}

echo "Update completed.\n";
$db->close();
?>