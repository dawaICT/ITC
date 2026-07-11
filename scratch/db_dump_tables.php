<?php
require_once __DIR__ . '/../db/connect.php';
$r = $db->query('SHOW TABLES');
echo "=== ALL TABLES ===" . PHP_EOL;
while ($row = $r->fetch_row()) {
    echo $row[0] . PHP_EOL;
}
