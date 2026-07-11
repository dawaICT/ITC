<?php
$db = new mysqli('localhost', 'root', '', 'wucportal');
if ($db->connect_error) die("Connection failed");

echo "TABLES:\n";
$res = $db->query("SHOW TABLES");
while($row = $res->fetch_row()) echo "- " . $row[0] . "\n";

echo "\nPORTAL SETTINGS:\n";
$res = $db->query("SELECT * FROM portal_settings");
if ($res) {
    while($row = $res->fetch_assoc()) print_r($row);
} else {
    echo "No portal_settings table found.\n";
}

echo "\nACADEMIC PERIODS / SESSIONS:\n";
$res = $db->query("SHOW COLUMNS FROM semester_registration");
while($row = $res->fetch_assoc()) echo $row['Field'] . " (" . $row['Type'] . ")\n";
?>