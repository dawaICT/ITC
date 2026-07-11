<?php
$db = new mysqli('localhost', 'root', '', 'wucportal');
if ($db->connect_error) { echo "CONNERR\n"; exit(1); }
$res = $db->query('SHOW COLUMNS FROM students');
if ($res) {
    while ($r = $res->fetch_object()) {
        echo $r->Field . '|' . $r->Type . "\n";
    }
}
$db->close();
