<?php
require_once(__DIR__ . '/../db/connect.php');

// Get table structure
$tables = ['semester_registration'];
$structures = [];

foreach ($tables as $table) {
    $result = $db->query("SHOW TABLES LIKE '$table'");
    if ($result && $result->num_rows > 0) {
        // Get table structure
        $result = $db->query("SHOW CREATE TABLE $table");
        if ($row = $result->fetch_assoc()) {
            $structures[$table] = $row['Create Table'];
        }

        // Get foreign keys
        $result = $db->query("
            SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '$table'
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        $foreign_keys = [];
        while ($row = $result->fetch_assoc()) {
            $foreign_keys[] = $row;
        }
        $structures[$table . '_foreign_keys'] = $foreign_keys;
    } else {
        $structures[$table] = null;
    }
}

// Output results
header('Content-Type: application/json');
echo json_encode($structures, JSON_PRETTY_PRINT);
?> 