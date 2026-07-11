<?php
$db = new mysqli('localhost', 'root', '', 'wucportal');
$result = $db->query('SHOW COLUMNS FROM student_courses');
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' (' . $row['Type'] . ")\n";
}
?>