<?php
/**
 * Check schema for registration-related tables
 */
require_once __DIR__ . '/db/connect.php';

echo "=== Registration Tables Schema ===\n\n";

$tables = ['semester_registration', 'course_registration', 'invoices', 'student_payments'];

foreach ($tables as $table) {
    echo strtoupper($table) . " TABLE:\n";
    if ($r = $db->query("DESCRIBE `$table`")) {
        while ($row = $r->fetch_assoc()) {
            $null = $row['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
            $key = $row['Key'] ? " [{$row['Key']}]" : '';
            $default = $row['Default'] !== null ? " = {$row['Default']}" : '';
            echo "  - {$row['Field']} ({$row['Type']}) $null$key$default\n";
        }
        $r->free();
    } else {
        echo "  Table does not exist!\n";
    }
    echo "\n";
}

// Check foreign key relationships
echo "=== Foreign Key Relationships ===\n";
$fkQuery = "
SELECT 
    TABLE_NAME, COLUMN_NAME, 
    REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE() 
  AND REFERENCED_TABLE_NAME IS NOT NULL
  AND TABLE_NAME IN ('semester_registration', 'course_registration', 'invoices', 'student_payments')
ORDER BY TABLE_NAME
";

if ($r = $db->query($fkQuery)) {
    if ($r->num_rows === 0) {
        echo "No foreign key constraints found.\n";
    }
    while ($row = $r->fetch_assoc()) {
        echo "{$row['TABLE_NAME']}.{$row['COLUMN_NAME']} -> {$row['REFERENCED_TABLE_NAME']}.{$row['REFERENCED_COLUMN_NAME']}\n";
    }
    $r->free();
}

echo "\nDone.\n";
