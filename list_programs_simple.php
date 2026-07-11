<?php
require 'db/connect.php';
$result = $db->query('SELECT program_code, program_name FROM programs');
while($row = $result->fetch_assoc()) {
    echo $row['program_code'] . ' - ' . $row['program_name'] . PHP_EOL;
}
?>