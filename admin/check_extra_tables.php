<?php
require '../db/connect.php';

$tables = ['staff_positions', 'access_right'];

foreach ($tables as $t) {
    echo "Checking $t: ";
    $res = $db->query("DESCRIBE $t");
    if ($res) {
        echo "EXISTS\n";
    } else {
        echo "NOT_FOUND\n";
    }
}
?>
