<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Connect to database
$db = new mysqli('localhost', 'root', '', 'wucportal');
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

echo "Database connection successful\n";

// Check total number of students
$count_result = $db->query("SELECT COUNT(*) as total FROM students");
if ($count_result) {
    $count = $count_result->fetch_assoc();
    echo "\nTotal number of students in database: " . $count['total'] . "\n";
}

// Analyze SID patterns
$patterns = $db->query("SELECT 
    LEFT(SID, 2) as year,
    SUBSTRING(SID, 3, 2) as month,
    SUBSTRING(SID, 5, 2) as day,
    LENGTH(SID) as id_length,
    COUNT(*) as count
FROM students 
GROUP BY year, month, day, id_length
ORDER BY year DESC, month DESC, day DESC
LIMIT 10");

if ($patterns) {
    echo "\nSID patterns analysis:\n";
    while ($row = $patterns->fetch_assoc()) {
        echo "Pattern: Year: 20{$row['year']}, Month: {$row['month']}, Day: {$row['day']}, " .
             "Length: {$row['id_length']}, Count: {$row['count']}\n";
    }
}

// Get sample of actual SIDs
$samples = $db->query("SELECT SID, created_at FROM students ORDER BY created_at DESC LIMIT 5");
if ($samples) {
    echo "\nRecent SID examples:\n";
    while ($row = $samples->fetch_assoc()) {
        echo "SID: {$row['SID']} (Created: {$row['created_at']})\n";
    }
}

// Check for the specific student ID
$student_id = '230919254';
echo "\nAnalyzing student ID: $student_id\n";
echo "Length: " . strlen($student_id) . "\n";
echo "Year: " . substr($student_id, 0, 2) . "\n";
echo "Month: " . substr($student_id, 2, 2) . "\n";
echo "Day/Sequence: " . substr($student_id, 4, 2) . "\n";
echo "Number: " . substr($student_id, 6) . "\n";

// Search with wildcards
$searches = [
    "23%" => "All 2023 students",
    "2309%" => "All September 2023 students",
    "230919%" => "All September 19, 2023 students"
];

foreach ($searches as $pattern => $description) {
    echo "\nSearching $description:\n";
    $result = $db->query("SELECT SID, Fname, Lname, created_at 
                         FROM students 
                         WHERE SID LIKE '$pattern' 
                         ORDER BY SID");
    if ($result) {
        echo "Found " . $result->num_rows . " matches:\n";
        while ($row = $result->fetch_assoc()) {
            echo "{$row['SID']} - {$row['Fname']} {$row['Lname']} ({$row['created_at']})\n";
        }
    }
}

// Check for any students created around the same time
$date_search = $db->query("SELECT SID, Fname, Lname, created_at 
                          FROM students 
                          WHERE created_at >= '2023-09-19 00:00:00' 
                          AND created_at < '2023-09-20 00:00:00'
                          ORDER BY created_at");
if ($date_search) {
    echo "\nStudents created on September 19, 2023:\n";
    while ($row = $date_search->fetch_assoc()) {
        echo "{$row['SID']} - {$row['Fname']} {$row['Lname']} ({$row['created_at']})\n";
    }
}

// Close connection
$db->close(); 