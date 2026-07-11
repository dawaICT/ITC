<?php
require "db/connect.php";
$table = 'student_program';
echo "Table: $table\n";
$result = $db->query("DESCRIBE $table");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "  " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
} else {
    echo "  Error: " . $db->error . "\n";
}
?>
