<?php
require_once __DIR__ . '/../db/connect.php';
if ($argc < 2) { echo "Usage: php show_create_table.php table_name\n"; exit(1); }
$table = $argv[1];
$r = $db->query("SHOW CREATE TABLE `" . $db->real_escape_string($table) . "`");
if (!$r) { echo "Error: " . $db->error . "\n"; exit(1); }
$row = $r->fetch_assoc();
echo $row['Create Table'] . "\n";
