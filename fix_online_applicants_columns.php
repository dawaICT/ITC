<?php
require_once 'db/connect.php';

echo "Updating online_applicants table to add missing columns...\n";

// Check if table exists
$result = $db->query("SHOW TABLES LIKE 'online_applicants'");
if ($result->num_rows == 0) {
    echo "Table online_applicants does not exist. Creating it...\n";
    $create_sql = "CREATE TABLE online_applicants (
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
    echo "Table exists. Checking for missing columns...\n";

    // List of columns to add if missing
    $columns_to_add = [
        "title VARCHAR(10)",
        "nrc_pass VARCHAR(50)",
        "country VARCHAR(50)",
        "dob DATE",
        "h_addre TEXT",
        "p_addre TEXT",
        "sponsor VARCHAR(100)",
        "next_kin VARCHAR(100)",
        "next_kin_mobile VARCHAR(20)",
        "relat VARCHAR(50)",
        "year VARCHAR(10)",
        "nrc_file VARCHAR(255)",
        "deposit_slip VARCHAR(255)"
    ];

    // Get existing columns
    $existing_columns = [];
    $result = $db->query("DESCRIBE online_applicants");
    while ($row = $result->fetch_assoc()) {
        $existing_columns[] = $row['Field'];
    }

    foreach ($columns_to_add as $column_def) {
        $column_name = explode(' ', $column_def)[0];
        if (!in_array($column_name, $existing_columns)) {
            $alter_sql = "ALTER TABLE online_applicants ADD COLUMN $column_def";
            if ($db->query($alter_sql)) {
                echo "Added column: $column_name\n";
            } else {
                echo "Error adding column $column_name: " . $db->error . "\n";
            }
        } else {
            echo "Column $column_name already exists.\n";
        }
    }
}

echo "Update completed.\n";
$db->close();
?>