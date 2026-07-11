<?php
/**
 * Migration: Add required columns to students table for Transfer Students feature
 */

require 'db/connect.php';

echo "Database Migration: Adding Transfer Student Support Columns\n";
echo str_repeat("=", 80) . "\n\n";

$columns_to_add = [
    'is_transfer' => "INT DEFAULT 0 COMMENT 'Flag: 1 if transfer student, 0 if new'",
    'academic_year' => "INT DEFAULT YEAR(NOW()) COMMENT 'Academic year of admission'",
    'transfer_from' => "VARCHAR(255) DEFAULT NULL COMMENT 'Previous institution name'",
    'transfer_credits' => "INT DEFAULT 0 COMMENT 'Credits transferred from previous institution'",
    'school' => "VARCHAR(255) DEFAULT NULL COMMENT 'Current/previous school name'",
    'nrc_pass' => "VARCHAR(50) DEFAULT NULL COMMENT 'NRC or Passport number'",
    'dob' => "DATE DEFAULT NULL COMMENT 'Date of birth'",
    'mobile' => "VARCHAR(20) DEFAULT NULL COMMENT 'Mobile phone number'",
    'email' => "VARCHAR(100) DEFAULT NULL COMMENT 'Email address'",
    'h_addre' => "VARCHAR(255) DEFAULT NULL COMMENT 'Home address'",
    'p_addre' => "VARCHAR(255) DEFAULT NULL COMMENT 'Postal address'",
    'next_kin' => "VARCHAR(100) DEFAULT NULL COMMENT 'Next of kin name'",
    'next_kin_mobile' => "VARCHAR(20) DEFAULT NULL COMMENT 'Next of kin mobile'",
    'profile_image' => "VARCHAR(255) DEFAULT NULL COMMENT 'Profile image filename'",
    'dte_adm' => "DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Date of admission'",
];

try {
    // Get existing columns
    $existing_columns = [];
    $result = $db->query("DESCRIBE students");
    while ($row = $result->fetch_assoc()) {
        $existing_columns[] = $row['Field'];
    }
    
    echo "Existing columns in students table:\n";
    echo implode(", ", $existing_columns) . "\n\n";
    
    // Add missing columns
    $added_count = 0;
    foreach ($columns_to_add as $column => $definition) {
        if (!in_array($column, $existing_columns)) {
            $sql = "ALTER TABLE students ADD COLUMN $column $definition";
            if ($db->query($sql)) {
                echo "✓ Added column: $column\n";
                $added_count++;
            } else {
                echo "✗ Error adding $column: " . $db->error . "\n";
            }
        } else {
            echo "→ Column already exists: $column\n";
        }
    }
    
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "Migration complete. Added $added_count new column(s).\n";
    echo str_repeat("=", 80) . "\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

$db->close();
?>
