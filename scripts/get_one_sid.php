<?php
$db = new mysqli('localhost', 'root', '', 'wucportal');
if ($db->connect_error) {
    echo "CONNERR\n";
    exit(1);
}
$r = $db->query('SELECT SID FROM students LIMIT 1');
if ($r && $row = $r->fetch_object()) {
    echo $row->SID;
} else {
    echo "NOSID";
}
$db->close();
