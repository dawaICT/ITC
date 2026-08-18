<?php
require_once dirname(__DIR__) . '/db/connect.php';
$r = $db->query("SHOW TABLES LIKE 'enterprise_opportunities'");
if (!$r || $r->num_rows === 0) {
    echo "MISSING\n";
    exit;
}
$r = $db->query('DESCRIBE enterprise_opportunities');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . "\n";
}
