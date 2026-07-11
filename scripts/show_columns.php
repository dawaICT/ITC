<?php
require_once __DIR__ . '/../db/connect.php';
if ($argc < 2) { echo "Usage: php show_columns.php table_name\n"; exit(1); }
$table = $argv[1];
$r = $db->query("SHOW COLUMNS FROM `" . $db->real_escape_string($table) . "`");
if (!$r) { echo "Error: " . $db->error . "\n"; exit(1); }
echo "Columns for $table:\n";
while ($c = $r->fetch_assoc()) {
    echo $c['Field'] . ' | ' . $c['Type'] . ' | NULL=' . $c['Null'] . ' | KEY=' . $c['Key'] . ' | DEFAULT=' . $c['Default'] . ' | EXTRA=' . $c['Extra'] . "\n";
}
