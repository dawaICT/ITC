<?php
require 'db/connect.php';

echo "=== FULL PROGRAM_COURSES STRUCTURE ===\n";
$result = $db->query('DESCRIBE program_courses');
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . " - " . $row['Type'] . " - " . ($row['Null'] == 'NO' ? 'NOT NULL' : 'NULL') . " - Default: " . ($row['Default'] ?? 'NULL') . "\n";
}

echo "\n=== SAMPLE DATA ===\n";
$result = $db->query('SELECT * FROM program_courses LIMIT 3');
while($row = $result->fetch_assoc()) {
    print_r($row);
    echo "\n";
}
?>
