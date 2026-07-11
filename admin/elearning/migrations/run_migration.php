<?php
/**
 * Auto-migration: Add video_uploaded_at column
 * This script automatically adds the required column if it doesn't exist
 */

require_once __DIR__ . '/../../../db/connect.php';

// Check if column exists
$result = $db->query("SHOW COLUMNS FROM lms_sessions LIKE 'video_uploaded_at'");

if ($result->num_rows === 0) {
    // Add the column
    $sql = "ALTER TABLE lms_sessions 
            ADD COLUMN video_uploaded_at DATETIME NULL DEFAULT NULL 
            COMMENT 'Timestamp when video was uploaded, used for auto-cleanup after 72 hours'";
    
    if ($db->query($sql)) {
        echo "Column 'video_uploaded_at' added successfully.\n";
        
        // Set uploaded_at for existing videos
        $update = "UPDATE lms_sessions 
                   SET video_uploaded_at = updated_at 
                   WHERE video_file_path IS NOT NULL 
                     AND video_file_path != '' 
                     AND video_uploaded_at IS NULL";
        
        if ($db->query($update)) {
            echo "Existing video timestamps updated.\n";
        }
        
        // Add index
        $index = "ALTER TABLE lms_sessions ADD INDEX idx_video_cleanup (video_uploaded_at)";
        $db->query($index); // May fail if index exists, that's OK
        
    } else {
        echo "Error adding column: " . $db->error . "\n";
    }
} else {
    echo "Column 'video_uploaded_at' already exists.\n";
}
