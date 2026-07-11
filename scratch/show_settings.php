<?php
require_once __DIR__ . '/../db/connect.php';

$res = $db->query("SELECT * FROM portal_settings");
while ($row = $res->fetch_assoc()) {
    echo "Key: {$row['setting_key']} | Val: {$row['setting_value']}" . PHP_EOL;
}
