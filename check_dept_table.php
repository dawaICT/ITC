<?php
define('IS_SCRIPT', true);
require_once __DIR__ . "/db/connect.php";

echo "=== Departments Table Structure ===\n\n";
$result = $db->query('DESCRIBE departments');
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' (' . $row['Type'] . ') ' . ($row['Null'] == 'YES' ? 'NULL' : 'NOT NULL') . "\n";
}
?>
