<?php
// assign_program_to_student.php

// Database connection
$db = new mysqli('127.0.0.1', 'root', '', 'wucportal');
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error . "\n");
}

$student_sid = '2601640001';
$program_code = 'BSCS'; // Example program code, change as needed
$intake = '2023';
$mode = 'semester';
$startYear = 2023;
$endYear = 2027;

$stmt = $db->prepare("INSERT INTO student_program (Sid, program_code, intake, mode, startYear, endYear) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssssii", $student_sid, $program_code, $intake, $mode, $startYear, $endYear);

if ($stmt->execute()) {
    echo "Successfully assigned program {$program_code} to student {$student_sid}.\n";
} else {
    echo "Error assigning program: " . $stmt->error . "\n";
}

$stmt->close();
$db->close();
?>
