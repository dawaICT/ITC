<?php
putenv('APP_ENV=development');
require_once __DIR__ . '/../db/connect.php';
$r = $db->query("SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
      AND REFERENCED_TABLE_NAME = 'courses'
    ORDER BY TABLE_NAME");
while ($row = $r->fetch_assoc()) {
    echo implode(' | ', $row) . "\n";
}
