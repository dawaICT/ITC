<?php
require_once __DIR__ . '/../db/connect.php';
$r = $db->query('SELECT SID, Fname, Lname, program, academic_year, status FROM students LIMIT 10');
while ($row = $r->fetch_assoc()) {
    echo "SID: " . $row['SID'] . " | Name: " . $row['Fname'] . " " . $row['Lname'] . " | Prog: " . $row['program'] . " | Year: " . $row['academic_year'] . " | Status: " . $row['status'] . PHP_EOL;
}
