<?php
// find_student_without_program.php

// Database connection
$db = new mysqli('127.0.0.1', 'root', '', 'wucportal');
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error . "\n");
}

echo "Finding student without program assignment...\n";

$result = $db->query("
    SELECT s.SID, s.Fname, s.Lname, s.email
    FROM students s
    LEFT JOIN student_program sp ON s.SID = sp.Sid
    WHERE sp.Sid IS NULL
");

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "Found student:\n";
        echo "  SID: {$row['SID']}\n";
        echo "  Name: {$row['Fname']} {$row['Lname']}\n";
        echo "  Email: {$row['email']}\n";
    }
} else {
    echo "No students found without program assignments.\n";
}

$db->close();
?>
