<?php
require 'db/connect.php';
$result = $db->query('SELECT id FROM semester_registration WHERE student_id = "test123"');
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo 'Semester registration ID: ' . $row['id'];
} else {
    echo 'No semester registration for test123';
}
?>