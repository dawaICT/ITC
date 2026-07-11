<?php
require_once __DIR__ . '/../db/connect.php';

echo "=== All staff ===\n";
$r = $db->query('SELECT staff_id, Fname, Lname FROM staff ORDER BY staff_id');
while ($row = $r->fetch_assoc()) {
    echo $row['staff_id'] . ' | ' . $row['Fname'] . ' ' . $row['Lname'] . "\n";
}

echo "\n=== WUC refs in text columns ===\n";
$cols = $db->query(
    "SELECT TABLE_NAME, COLUMN_NAME
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND DATA_TYPE IN ('char','varchar','text','mediumtext','longtext')
     ORDER BY TABLE_NAME, COLUMN_NAME"
);
while ($col = $cols->fetch_assoc()) {
    $table = str_replace('`', '``', (string)$col['TABLE_NAME']);
    $column = str_replace('`', '``', (string)$col['COLUMN_NAME']);
    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM `{$table}` WHERE `{$column}` LIKE 'WUC%'");
    if (!$stmt) {
        continue;
    }
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    if ($count > 0) {
        echo "{$table}.{$column}: {$count}\n";
        $sample = $db->query("SELECT DISTINCT `{$column}` AS v FROM `{$table}` WHERE `{$column}` LIKE 'WUC%' LIMIT 15");
        while ($s = $sample->fetch_assoc()) {
            echo "  - {$s['v']}\n";
        }
    }
}
