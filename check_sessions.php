<?php
$db = new mysqli('localhost', 'root', '', 'wucportal');
if ($db->connect_error) die("Connection failed");

$out = "CURRENT ACADEMIC PERIODS:\n";
$res = $db->query("SELECT * FROM academic_periods WHERE is_current = 1 LIMIT 1");
if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
    $out .= print_r($row, true);
} else {
    $out .= "No current academic period found.\n";
}

$out .= "\nALL PERIODS:\n";
$res = $db->query("SELECT * FROM academic_periods");
if ($res) {
    while($row = $res->fetch_assoc()) $out .= print_r($row, true);
}

file_put_contents('debug_output.txt', $out);
echo "Debug output written to debug_output.txt";
?>