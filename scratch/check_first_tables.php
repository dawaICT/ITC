<?php
require_once __DIR__ . '/../db/connect.php';

$tables = ['users', 'user_profiles', 'portals', 'user_portal_access'];
foreach ($tables as $tbl) {
    echo "=== Table: {$tbl} ===" . PHP_EOL;
    $res = $db->query("SHOW TABLES LIKE '{$tbl}'");
    if ($res && $res->num_rows > 0) {
        $desc = $db->query("DESCRIBE `{$tbl}`");
        while ($row = $desc->fetch_assoc()) {
            echo "  {$row['Field']} | {$row['Type']} | Key={$row['Key']} | Null={$row['Null']}" . PHP_EOL;
        }
        $countRes = $db->query("SELECT COUNT(*) as c FROM `{$tbl}`");
        $count = $countRes->fetch_assoc()['c'];
        echo "  Row Count: {$count}" . PHP_EOL;
        
        $data = $db->query("SELECT * FROM `{$tbl}` LIMIT 10");
        echo "  Sample Data:" . PHP_EOL;
        while ($row = $data->fetch_assoc()) {
            echo "    " . json_encode($row) . PHP_EOL;
        }
    } else {
        echo "  DOES NOT EXIST" . PHP_EOL;
    }
    echo PHP_EOL;
}
