<?php
require_once __DIR__ . '/../db/connect.php';

echo "=== DATABASE CONNECTION DEBUG ===\n";
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error . "\n");
}
echo "Connection successful to database: " . $db_name . "\n\n";

echo "=== TABLE VERIFICATIONS ===\n";
$tables = ['students', 'exams', 'courses', 'programs', 'student_program', 'publish_results'];

foreach ($tables as $table) {
    echo "Checking table: $table\n";
    $result = $db->query("SHOW TABLES LIKE '$table'");
    if ($result && $result->num_rows > 0) {
        echo "  [OK] Table exists.\n";
        
        // Get Columns
        echo "  Columns:\n";
        $cols = $db->query("DESCRIBE $table");
        if ($cols) {
            while ($row = $cols->fetch_assoc()) {
                echo "    - " . str_pad($row['Field'], 20) . " " . $row['Type'] . "\n";
            }
        } else {
            echo "    [ERROR] Could not describe table.\n";
        }
    } else {
        echo "  [MISSING] Table does not exist!\n";
    }
    echo "\n";
}

echo "=== DATA SAMPLE CHECK ===\n";
// Check Student
echo "Checking for Student ID '212003'...\n";
$res = $db->query("SELECT * FROM students WHERE SID = '212003'");
if ($res && $res->num_rows > 0) {
    $s = $res->fetch_assoc();
    echo "  [FOUND] Student: " . $s['Fname'] . " " . $s['Lname'] . "\n";
} else {
    echo "  [NOT FOUND] Student 212003 not in database.\n";
}

// Check Exams
echo "Checking Exam records for '212003'...\n";
$res = $db->query("SELECT count(*) as cot FROM exams WHERE Sid = '212003'");
if ($res) {
    $row = $res->fetch_assoc();
    echo "  [INFO] Found " . $row['cot'] . " exam records.\n";
}

// Check Publish Results
echo "Checking Published Results status...\n";
$res = $db->query("SELECT * FROM publish_results LIMIT 5");
if ($res && $res->num_rows > 0) {
    while($row = $res->fetch_assoc()) {
        echo "  Entry: Year=" . $row['year'] . " | Sem=" . $row['semester'] . " | Status=" . $row['status'] . "\n";
    }
} else {
    echo "  [INFO] No publish_results records found.\n";
}

$db->close();
?>
