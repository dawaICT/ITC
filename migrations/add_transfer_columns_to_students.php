<?php
/**
 * Migration script to add transfer student fields to students table
 * for the admissions/regOldStud.php registration form
 * Adds columns: is_transfer, transfer_from, transfer_credits, transfer_program, transfer_letter
 */

require_once 'db/connect.php';

try {
    echo "<h2>Adding Transfer Student Fields to Students Table</h2>";
    
    // List of columns to add with their definitions
    $columns = [
        'is_transfer' => "ALTER TABLE students ADD COLUMN IF NOT EXISTS is_transfer TINYINT(1) DEFAULT 0 COMMENT 'Flag indicating if student is a transfer student'",
        'transfer_from' => "ALTER TABLE students ADD COLUMN IF NOT EXISTS transfer_from VARCHAR(255) NULL COMMENT 'Previous institution name'",
        'transfer_credits' => "ALTER TABLE students ADD COLUMN IF NOT EXISTS transfer_credits INT DEFAULT 0 COMMENT 'Number of credits to transfer'",
        'transfer_program' => "ALTER TABLE students ADD COLUMN IF NOT EXISTS transfer_program VARCHAR(255) NULL COMMENT 'Previous program name'",
        'transfer_letter' => "ALTER TABLE students ADD COLUMN IF NOT EXISTS transfer_letter TEXT NULL COMMENT 'Reason for transfer'"
    ];
    
    // Add columns one by one
    foreach ($columns as $col_name => $sql) {
        try {
            $db->query($sql);
            echo "<p style='color: green;'>✓ Column '$col_name' added or already exists</p>";
        } catch (Exception $e) {
            echo "<p style='color: orange;'>⚠ Column '$col_name': " . $e->getMessage() . "</p>";
        }
    }
    
    // Verify columns exist
    $result = $db->query("DESCRIBE students");
    $columns_in_table = [];
    while ($row = $result->fetch_assoc()) {
        $columns_in_table[$row['Field']] = $row['Type'];
    }
    
    echo "<h3>Verification:</h3>";
    $required_cols = ['is_transfer', 'transfer_from', 'transfer_credits', 'transfer_program', 'transfer_letter'];
    foreach ($required_cols as $col) {
        if (isset($columns_in_table[$col])) {
            echo "<p style='color: green;'>✓ Column '$col' exists with type: " . $columns_in_table[$col] . "</p>";
        } else {
            echo "<p style='color: red;'>✗ Column '$col' NOT FOUND</p>";
        }
    }
    
    echo "<p style='margin-top: 20px;'><strong>Migration completed successfully!</strong></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

?>
