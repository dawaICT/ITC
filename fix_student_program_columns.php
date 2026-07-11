<?php
require_once "db/connect.php";

if (!isset($db) || !$db) {
    echo 'Database connection failed.';
    exit(1);
}

echo "Adding missing columns to student_program table...\n";

$queries = [
    'ALTER TABLE student_program ADD COLUMN IF NOT EXISTS mode VARCHAR(50) NULL AFTER intake',
    'ALTER TABLE student_program ADD COLUMN IF NOT EXISTS semester INT NULL AFTER mode',
    'ALTER TABLE student_program ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER endYear',
    'ALTER TABLE student_program ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at'
];

foreach ($queries as $query) {
    echo "Running: " . substr($query, 0, 50) . "...\n";
    if ($db->query($query)) {
        echo "✓ Successfully added column\n";
    } else {
        echo "✗ Error adding column: " . $db->error . "\n";
    }
}

echo "\nVerifying table structure...\n";
$result = $db->query('DESCRIBE student_program');
while ($row = $result->fetch_assoc()) {
    echo "- {$row['Field']}: {$row['Type']} {$row['Null']}\n";
}

$db->close();
echo "\nDone!\n";
?>
