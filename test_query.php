<?php
// Simple database connection for CLI use
$db_host = "127.0.0.1";
$db_user = "root";
$db_password = "";  // Default XAMPP password is empty
$db_name = "wucportal";

// Create connection
$db = new mysqli($db_host, $db_user, $db_password, $db_name);

// Check connection
if ($db->connect_error) {
    echo "Connection failed: " . $db->connect_error . "\n";
    exit(1);
}

echo "Connected successfully to database: $db_name\n";

// Test the exact query from manage_admitted_students.php
$query = "SELECT
    s.SID,
    s.Fname,
    s.Lname,
    s.sex,
    s.email,
    s.mobile,
    sp.program_code,
    p.program_name,
    sp.intake,
    sp.mode,
    sp.startYear,
    sp.endYear,
    sp.created_at as admission_date
FROM students s
INNER JOIN student_program sp ON s.SID = sp.Sid
INNER JOIN programs p ON sp.program_code = p.program_code
ORDER BY sp.created_at DESC";

echo "Running the exact query from manage_admitted_students.php...\n";
$result = $db->query($query);

if ($result) {
    echo "Query successful! Number of rows: " . $result->num_rows . "\n";
    echo "First few results:\n";
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $count++;
        if ($count > 3) break;

        $fname = isset($row['Fname']) ? $row['Fname'] : 'N/A';
        $lname = isset($row['Lname']) ? $row['Lname'] : 'N/A';
        $program = isset($row['program_name']) ? $row['program_name'] : 'N/A';
        $mode = isset($row['mode']) ? $row['mode'] : 'N/A';

        echo "  Student: $fname $lname - Program: $program - Mode: $mode\n";
    }
} else {
    echo "Query failed with error: " . $db->error . "\n";
    echo "Error code: " . $db->errno . "\n";
}

$db->close();
echo "Done!\n";
?>
