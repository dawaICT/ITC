<?php
require 'db/connect.php';

echo "Database connected successfully\n";

// Check program_courses table
$result = $db->query('SELECT COUNT(*) as count FROM program_courses');
if ($result) {
    $row = $result->fetch_assoc();
    echo "program_courses has " . $row['count'] . " rows\n";
} else {
    echo "Error checking program_courses: " . $db->error . "\n";
}

// Check if table exists
$result = $db->query("SHOW TABLES LIKE 'program_courses'");
if ($result && $result->num_rows > 0) {
    echo "program_courses table exists\n";

    // Get structure
    $result = $db->query('DESCRIBE program_courses');
    echo "Structure:\n";
    while($row = $result->fetch_assoc()) {
        echo "- " . $row['Field'] . "\n";
    }
} else {
    echo "program_courses table does not exist\n";
}
?>
