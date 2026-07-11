<?php
require 'db/connect.php';

$student_id = 'test123';

// Check if student exists in students table
$stmt = $db->prepare("SELECT * FROM students WHERE SID = ?");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "Student ID $student_id exists in students table.\n";
    $student = $result->fetch_assoc();
    echo "Details: " . json_encode($student) . "\n";
} else {
    echo "Student ID $student_id does not exist in students table.\n";
}

// Check if student has login credentials
$stmt = $db->prepare("SELECT * FROM student_login WHERE Sid = ?");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "Student ID $student_id has login credentials.\n";
    $login = $result->fetch_assoc();
    echo "Password hash: " . $login['Password'] . "\n";
} else {
    echo "Student ID $student_id does not have login credentials.\n";
}
?>