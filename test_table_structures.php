<?php
// Test table structures
require_once __DIR__ . '/students/includes/guard.php';

$tables = ['semester_registration', 'course_registration', 'course_levels', 'courses'];

foreach ($tables as $table) {
    echo "\n=== {$table} structure ===\n";
    $r = $db->query("DESCRIBE {$table}");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $key = $row['Key'] ? ' [' . $row['Key'] . ']' : '';
            echo $row['Field'] . ' (' . $row['Type'] . ')' . $key . "\n";
        }
        $r->free();
    } else {
        echo "Table does not exist\n";
    }
}

echo "\n=== Foreign Keys ===\n";
$r = $db->query("
    SELECT 
        TABLE_NAME, 
        COLUMN_NAME, 
        REFERENCED_TABLE_NAME, 
        REFERENCED_COLUMN_NAME 
    FROM information_schema.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND REFERENCED_TABLE_NAME IS NOT NULL 
    AND TABLE_NAME IN ('semester_registration', 'course_registration')
");
if ($r && $r->num_rows > 0) {
    while ($row = $r->fetch_assoc()) {
        echo $row['TABLE_NAME'] . '.' . $row['COLUMN_NAME'] . ' -> ' . 
             $row['REFERENCED_TABLE_NAME'] . '.' . $row['REFERENCED_COLUMN_NAME'] . "\n";
    }
    $r->free();
} else {
    echo "No foreign keys found\n";
}
