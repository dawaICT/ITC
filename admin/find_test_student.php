<?php
require_once __DIR__ . '/../db/connect.php';

echo "=== FINDING TEST DATA ===\n";

// 1. Get first 5 students
$sql = "SELECT SID, Fname, Lname FROM students LIMIT 5";
$res = $db->query($sql);

if ($res && $res->num_rows > 0) {
    while ($s = $res->fetch_assoc()) {
        $sid = $s['SID'];
        // Count exams for this student
        $e_res = $db->query("SELECT count(*) as c FROM exams WHERE Sid = '$sid'");
        $e_count = $e_res->fetch_assoc()['c'];
        
        echo "Student: $sid - " . $s['Fname'] . " " . $s['Lname'] . " | Exams: $e_count\n";
    }
} else {
    echo "No students found in the database.\n";
}
?>
