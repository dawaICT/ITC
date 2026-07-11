<?php
require 'db/connect.php';

function describeTable($db, $table) {
    echo "Table: $table\n";
    $res = $db->query("DESCRIBE $table");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            echo "  " . $row['Field'] . " (" . $row['Type'] . ")\n";
        }
    } else {
        echo "  Table does not exist.\n";
    }
    echo "\n";
}

describeTable($db, 'student_payments');
describeTable($db, 'transactions');
describeTable($db, 'student_finances');
?>
