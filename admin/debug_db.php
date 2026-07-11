<?php

$db_host = "127.0.0.1";
$db_user = "root";
$db_password = "";
$db_name = "wucportal";

$db = new mysqli($db_host, $db_user, $db_password, $db_name);
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}
$db->set_charset("utf8mb4");

function describeTable($db, $tableName) {
    echo "Table: $tableName\n";
    $result = $db->query("DESCRIBE $tableName");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            echo $row['Field'] . " - " . $row['Type'] . "\n";
        }
    } else {
        echo "Error description table $tableName: " . $db->error . "\n";
    }
    echo "\n";
}

describeTable($db, "departments");
describeTable($db, "staff");
describeTable($db, "programs");
?>
