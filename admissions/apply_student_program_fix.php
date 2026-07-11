<?php
/**
 * Apply student_program status column migration
 * This script adds the missing 'status' column to student_program table
 * Run this once to fix the database schema
 */

require "db/connect.php";

echo "=== Student Program Status Column Migration ===\n\n";

try {
    // Check if status column exists
    $check_query = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_NAME='student_program' AND COLUMN_NAME='status'";
    $result = $db->query($check_query);
    
    if ($result && $result->num_rows === 0) {
        echo "[1/4] Adding status column to student_program table...\n";
        $alter_query = "ALTER TABLE student_program 
                       ADD COLUMN status ENUM('active', 'completed', 'withdrawn') DEFAULT 'active'";
        
        if ($db->query($alter_query)) {
            echo "✓ Status column added successfully\n\n";
        } else {
            echo "✗ Error adding status column: " . $db->error . "\n";
            exit(1);
        }
    } else {
        echo "[1/4] Status column already exists - skipping\n\n";
    }
    
    // Add index for status column
    echo "[2/4] Adding index on status column...\n";
    $index_query = "ALTER TABLE student_program 
                   ADD INDEX IF NOT EXISTS idx_status (status)";
    
    if ($db->query($index_query)) {
        echo "✓ Index added successfully\n\n";
    } else {
        echo "✗ Error adding index: " . $db->error . "\n";
        exit(1);
    }
    
    // Update NULL values to 'active'
    echo "[3/4] Updating NULL status values to 'active'...\n";
    $update_query = "UPDATE student_program SET status = 'active' WHERE status IS NULL";
    
    if ($db->query($update_query)) {
        $affected = $db->affected_rows;
        echo "✓ Updated $affected record(s)\n\n";
    } else {
        echo "✗ Error updating records: " . $db->error . "\n";
        exit(1);
    }
    
    // Try to add unique constraint
    echo "[4/4] Verifying database structure...\n";
    $describe_query = "DESCRIBE student_program";
    $result = $db->query($describe_query);
    
    if ($result) {
        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
        
        if (in_array('status', $columns)) {
            echo "✓ Status column verified in table structure\n\n";
        } else {
            echo "✗ Status column not found!\n\n";
            exit(1);
        }
    }
    
    echo "=== Migration Complete ===\n";
    echo "The student_program table has been successfully updated!\n";
    echo "The reports.php page should now work correctly.\n";
    
} catch (Exception $e) {
    echo "✗ Fatal Error: " . $e->getMessage() . "\n";
    exit(1);
}

$db->close();
?>
