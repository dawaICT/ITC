<?php
include "includes/admin.php";
$tables = ['exams', 'students'];
foreach ($tables as $t) {
    echo "Table: $t\n";
    $res = $db->query("DESCRIBE $t");
    while($row = $res->fetch_assoc()) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
    echo "-------------------\n";
}
?>
