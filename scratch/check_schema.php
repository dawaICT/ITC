<?php
require_once 'db/connect.php';

function print_columns($db, $table) {
    echo "Columns of $table:\n";
    $res = $db->query("DESCRIBE $table");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            echo "  {$row['Field']} - {$row['Type']}\n";
        }
    } else {
        echo "  Error: " . $db->error . "\n";
    }
    echo "\n";
}

print_columns($db, 'student_program');
?>
