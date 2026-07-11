<?php
require_once __DIR__ . '/../db/connect.php';

$res = $db->query("SELECT SID, Fname, Lname FROM students ORDER BY id DESC LIMIT 20");
if (!$res) { echo "Query failed: " . $db->error . "\n"; exit(1); }
while ($r = $res->fetch_assoc()) {
    echo $r['SID'] . " | " . ($r['Fname'] ?? '') . " " . ($r['Lname'] ?? '') . "\n";
}
?>