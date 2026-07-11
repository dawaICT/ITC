<?php
require_once __DIR__ . '/../db/connect.php'; 
$res = $db->query("SHOW COLUMNS FROM access_right");
while ($row = $res->fetch_assoc()) {
    echo $row['Field'] . "\n";
}
?>
