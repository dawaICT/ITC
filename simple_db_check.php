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

// Check if table exists
$result = $db->query("SHOW TABLES LIKE 'student_program'");
if ($result->num_rows == 0) {
    echo "Table 'student_program' does not exist!\n";
    $db->close();
    exit(1);
}

echo "Table 'student_program' exists.\n";
echo "Checking student_program table structure:\n";

$query = 'DESCRIBE student_program';
echo "Running query: $query\n";

$result = $db->query($query);

if ($result) {
    echo "Query successful. Number of rows: " . $result->num_rows . "\n";
    echo "Columns in student_program table:\n";
    $columnCount = 0;
    while ($row = $result->fetch_assoc()) {
        $columnCount++;
        echo "- {$row['Field']}: {$row['Type']} {$row['Null']} {$row['Key']} {$row['Default']}\n";
    }
    echo "Total columns: $columnCount\n";
} else {
    echo 'Error: ' . $db->error . "\n";
    echo 'Error code: ' . $db->errno . "\n";
}

$db->close();
echo "Done!\n";
?>
