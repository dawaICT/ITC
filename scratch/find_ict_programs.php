<?php
require_once dirname(__DIR__) . '/db/connect.php';
$r = $db->query("SELECT program_code, program_name FROM programs WHERE program_code LIKE '%ICT%' OR program_name LIKE '%ICT%' OR program_name LIKE '%Information%' LIMIT 20");
while ($x = $r->fetch_assoc()) {
    echo $x['program_code'] . ' | ' . $x['program_name'] . "\n";
}
