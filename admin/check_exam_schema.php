<?php
require 'c:/xampp/htdocs/wucportal/db/connect.php';

echo "EXAMS TABLE:\n";
$res = $db->query("DESCRIBE exams");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . " | " . $row['Type'] . "\n";
}

echo "\nSTUDENT_PROGRAM TABLE:\n";
$res = $db->query("DESCRIBE student_program");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . " | " . $row['Type'] . "\n";
}
?>
