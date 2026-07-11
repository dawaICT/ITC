<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Database configuration
$db_host = "localhost";
$db_user = "root";
$db_password = "";
$db_name = "wucportal";

// Create connection
$db = new mysqli($db_host, $db_user, $db_password, $db_name);

// Check connection
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

// Function to check table structure
function checkTable($db, $tableName, $expectedColumns) {
    $result = $db->query("SHOW TABLES LIKE '$tableName'");
    if ($result->num_rows == 0) {
        echo "Table '$tableName' does not exist<br>";
        return false;
    }

    $result = $db->query("SHOW COLUMNS FROM $tableName");
    $existingColumns = [];
    while ($row = $result->fetch_assoc()) {
        $existingColumns[$row['Field']] = $row;
    }

    $missingColumns = array_diff(array_keys($expectedColumns), array_keys($existingColumns));
    $extraColumns = array_diff(array_keys($existingColumns), array_keys($expectedColumns));
    $mismatchedColumns = [];

    foreach ($expectedColumns as $column => $details) {
        if (isset($existingColumns[$column])) {
            if ($existingColumns[$column]['Type'] != $details['type']) {
                $mismatchedColumns[] = "$column (expected {$details['type']}, got {$existingColumns[$column]['Type']})";
            }
        }
    }

    if (empty($missingColumns) && empty($extraColumns) && empty($mismatchedColumns)) {
        echo "Table '$tableName' structure is correct<br>";
        return true;
    }

    if (!empty($missingColumns)) {
        echo "Missing columns in '$tableName': " . implode(', ', $missingColumns) . "<br>";
    }
    if (!empty($extraColumns)) {
        echo "Extra columns in '$tableName': " . implode(', ', $extraColumns) . "<br>";
    }
    if (!empty($mismatchedColumns)) {
        echo "Mismatched columns in '$tableName': " . implode(', ', $mismatchedColumns) . "<br>";
    }
    return false;
}

// Expected table structures
$tables = [
    'students' => [
        'SID' => ['type' => 'varchar(50)'],
        'Fname' => ['type' => 'varchar(100)'],
        'Lname' => ['type' => 'varchar(100)'],
        'sex' => ['type' => 'varchar(10)'],
        'nrc_pass' => ['type' => 'varchar(50)'],
        'mobile' => ['type' => 'varchar(20)'],
        'email' => ['type' => 'varchar(100)'],
        'profile_image' => ['type' => 'varchar(255)']
    ],
    'programs' => [
        'program_code' => ['type' => 'varchar(50)'],
        'program_name' => ['type' => 'varchar(255)']
    ],
    'student_program' => [
        'id' => ['type' => 'int(11)'],
        'Sid' => ['type' => 'varchar(50)'],
        'program_code' => ['type' => 'varchar(50)'],
        'intake' => ['type' => 'varchar(50)'],
        'startYear' => ['type' => 'int(11)'],
        'endYear' => ['type' => 'int(11)']
    ]
];

echo "<h2>Database Structure Verification</h2>";

$needsFixes = false;
foreach ($tables as $tableName => $expectedColumns) {
    echo "<h3>Checking $tableName</h3>";
    if (!checkTable($db, $tableName, $expectedColumns)) {
        $needsFixes = true;
    }
}

if ($needsFixes) {
    echo "<br><strong>Some tables need to be fixed. Please run execute_updates.php to fix the issues.</strong>";
} else {
    echo "<br><strong>All tables are correctly structured.</strong>";
}

// Check data in tables
echo "<h2>Data Verification</h2>";
foreach ($tables as $tableName => $columns) {
    $result = $db->query("SELECT COUNT(*) as count FROM $tableName");
    if ($result) {
        $count = $result->fetch_object()->count;
        echo "Records in $tableName: $count<br>";

        if ($count == 0) {
            echo "<strong>Warning: No data in $tableName table</strong><br>";
        }
    } else {
        echo "Error checking data in $tableName: " . $db->error . "<br>";
    }
}

$db->close();
?> 