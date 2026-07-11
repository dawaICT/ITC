<?php
require 'db/connect.php';

$sql = file_get_contents('fix_students_table.sql');
$statements = array_filter(array_map('trim', explode(';', $sql)));

foreach ($statements as $statement) {
    if (!empty($statement) && !preg_match('/^--/', $statement)) {
        echo "Executing: " . substr($statement, 0, 50) . "...\n";
        if ($db->query($statement) === TRUE) {
            echo "✓ Success\n";
        } else {
            echo "✗ Error: " . $db->error . "\n";
        }
    }
}

echo "Table update completed.\n";
?>