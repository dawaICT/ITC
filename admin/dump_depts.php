<?php
$db_host = "127.0.0.1";
$db_user = "root";
$db_password = "";
$db_name = "wucportal";

$db = new mysqli($db_host, $db_user, $db_password, $db_name);
$db->set_charset("utf8mb4");

$res = $db->query("SELECT * FROM departments");
while ($row = $res->fetch_assoc()) {
    print_r($row);
}
?>
