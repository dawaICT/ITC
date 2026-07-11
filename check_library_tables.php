<?php
require_once 'db/connect.php';
$res = $db->query('SHOW TABLES LIKE "library_%"');
$tables = [];
while($row = $res->fetch_assoc()) {
    $tables[] = implode('', $row);
}
echo implode(', ', $tables);
?>