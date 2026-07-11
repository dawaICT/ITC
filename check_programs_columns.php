<?php
require_once 'db/connect.php';

echo "=== PROGRAMS TABLE COLUMNS ===\n";
$res = $db->query('SHOW COLUMNS FROM programs');
if($res) {
    while($r = $res->fetch_assoc()) {
        echo $r['Field'] . ' | ' . $r['Type'] . ' | ' . $r['Null'] . ' | ' . $r['Default'] . "\n";
    }
} else {
    echo 'Error: ' . $db->error . "\n";
}

echo "\n=== SAMPLE PROGRAMS DATA ===\n";
$res = $db->query('SELECT * FROM programs LIMIT 3');
if($res) {
    while($r = $res->fetch_assoc()) {
        print_r($r);
    }
} else {
    echo 'Error: ' . $db->error . "\n";
}
