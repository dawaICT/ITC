<?php
require 'db/connect.php';

echo "=== Checking Period Mode Setup ===\n\n";

// Check programs table for period_mode column
echo "1. Programs table structure (relevant columns):\n";
$result = $db->query("SHOW COLUMNS FROM programs LIKE 'period_mode'");
if ($result && $result->num_rows > 0) {
    $col = $result->fetch_assoc();
    echo "   - period_mode: " . $col['Type'] . " (Default: " . $col['Default'] . ")\n";
} else {
    echo "   - period_mode column NOT found!\n";
}

$result = $db->query("SHOW COLUMNS FROM programs LIKE 'term_based'");
if ($result && $result->num_rows > 0) {
    $col = $result->fetch_assoc();
    echo "   - term_based: " . $col['Type'] . " (Default: " . $col['Default'] . ")\n";
} else {
    echo "   - term_based column NOT found!\n";
}

echo "\n2. Sample programs with period_mode:\n";
$result = $db->query("SELECT program_code, program_name, period_mode, term_based FROM programs LIMIT 10");
while ($row = $result->fetch_assoc()) {
    echo "   - " . $row['program_code'] . ": period_mode=" . ($row['period_mode'] ?? 'NULL') 
         . ", term_based=" . ($row['term_based'] ?? 'NULL') . "\n";
}

echo "\n3. Semester registration table structure:\n";
$result = $db->query("DESCRIBE semester_registration");
while ($row = $result->fetch_assoc()) {
    echo "   - " . $row['Field'] . ": " . $row['Type'] . "\n";
}

echo "\n4. Sample semester registrations:\n";
$result = $db->query("SELECT sr.student_id, sr.year_of_study, sr.semester, sr.academic_year, p.period_mode
                      FROM semester_registration sr 
                      LEFT JOIN student_program sp ON sr.student_id = sp.Sid
                      LEFT JOIN programs p ON sp.program_code = p.program_code
                      LIMIT 5");
while ($row = $result->fetch_assoc()) {
    echo "   - Student: " . $row['student_id'] . ", Year: " . $row['year_of_study'] 
         . ", Semester/Term: " . $row['semester'] . ", Period Mode: " . ($row['period_mode'] ?? 'N/A') . "\n";
}

echo "\n5. Testing main query with period_mode:\n";
$query = "SELECT s.SID, s.Fname, s.Lname, sp.program_code, p.program_name, p.period_mode, p.term_based
          FROM students s
          INNER JOIN student_program sp ON s.SID = sp.Sid
          INNER JOIN programs p ON sp.program_code = p.program_code
          LIMIT 5";
$result = $db->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "   - " . $row['SID'] . ": " . $row['program_name'] . " (period_mode: " 
             . ($row['period_mode'] ?? 'NULL') . ", term_based: " . ($row['term_based'] ?? 'NULL') . ")\n";
    }
} else {
    echo "   Error: " . $db->error . "\n";
}

$db->close();
?>
