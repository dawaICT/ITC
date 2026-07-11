<?php
require_once __DIR__ . '/../db/connect.php';
$r = $db->query('DESCRIBE program_courses');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' ' . $row['Type'] . "\n";
}
