<?php
require_once __DIR__ . '/students/includes/DatabaseConnection.php';
$conn = DatabaseConnection::getInstance();
$mysqli = $conn->getMysqli();

function showTable($name) {
    global $mysqli;
    echo "Table: $name\n";
    $result = $mysqli->query("DESCRIBE $name");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            echo $row['Field'] . " (" . $row['Type'] . ")\n";
        }
    } else {
        echo "Error: " . $mysqli->error . "\n";
    }
    echo "\n";
}

showTable('students');
showTable('student_program');
showTable('programs');

session_start();
echo "Session SID: " . ($_SESSION['Sid'] ?? 'Not set') . "\n";
if (isset($_SESSION['Sid'])) {
    $sid = $_SESSION['Sid'];
    echo "Checking student_program for SID '$sid'...\n";
    $res = $mysqli->query("SELECT * FROM student_program WHERE Sid = '$sid' OR student_id = '$sid' LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        print_r($row);
    } else {
        echo "No record found in student_program.\n";
    }
}
?>
