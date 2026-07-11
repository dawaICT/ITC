<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once 'db/connect.php';

// Function to check if a table exists
function tableExists($db, $tableName) {
    $result = $db->query("SHOW TABLES LIKE '$tableName'");
    return $result->num_rows > 0;
}

// Function to get table columns
function getTableColumns($db, $tableName) {
    $columns = array();
    $result = $db->query("SHOW COLUMNS FROM $tableName");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = $row['Type'];
        }
    }
    return $columns;
}

// Function to execute SQL with error handling
function executeSql($db, $sql) {
    if ($db->query($sql)) {
        echo "Success: " . substr($sql, 0, 50) . "...<br>";
        return true;
    } else {
        echo "Error: " . $db->error . "<br>";
        echo "SQL: " . $sql . "<br>";
        return false;
    }
}

// Expected table structures
$tables = [
    'students' => [
        'SID' => 'varchar(50)',
        'Fname' => 'varchar(100)',
        'Lname' => 'varchar(100)',
        'sex' => 'varchar(10)',
        'nrc_pass' => 'varchar(50)',
        'mobile' => 'varchar(20)',
        'email' => 'varchar(100)',
        'profile_image' => 'varchar(255)'
    ],
    'programs' => [
        'program_code' => 'varchar(50)',
        'program_name' => 'varchar(255)'
    ],
    'student_program' => [
        'id' => 'int(11)',
        'Sid' => 'varchar(50)',
        'program_code' => 'varchar(50)',
        'intake' => 'varchar(50)',
        'startYear' => 'int(11)',
        'endYear' => 'int(11)'
    ]
];

echo "<h2>Database Structure Check and Fix</h2>";

// Drop and recreate tables
echo "<h3>Recreating Tables</h3>";

// Drop tables in correct order
executeSql($db, "DROP TABLE IF EXISTS student_program");
executeSql($db, "DROP TABLE IF EXISTS students");
executeSql($db, "DROP TABLE IF EXISTS programs");

// Create tables
executeSql($db, "CREATE TABLE students (
    SID VARCHAR(50) PRIMARY KEY,
    Fname VARCHAR(100),
    Lname VARCHAR(100),
    sex VARCHAR(10),
    nrc_pass VARCHAR(50),
    mobile VARCHAR(20),
    email VARCHAR(100),
    profile_image VARCHAR(255) DEFAULT 'default.jpg'
)");

executeSql($db, "CREATE TABLE programs (
    program_code VARCHAR(50) PRIMARY KEY,
    program_name VARCHAR(255)
)");

executeSql($db, "CREATE TABLE student_program (
    id INT AUTO_INCREMENT PRIMARY KEY,
    Sid VARCHAR(50),
    program_code VARCHAR(50),
    intake VARCHAR(50),
    startYear INT,
    endYear INT,
    FOREIGN KEY (Sid) REFERENCES students(SID) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (program_code) REFERENCES programs(program_code) ON DELETE CASCADE ON UPDATE CASCADE
)");

// Insert sample data
echo "<h3>Inserting Sample Data</h3>";

executeSql($db, "INSERT INTO programs (program_code, program_name) VALUES 
('CS101', 'Computer Science'),
('BBA101', 'Business Administration'),
('ENG101', 'Engineering')");

executeSql($db, "INSERT INTO students (SID, Fname, Lname, sex, nrc_pass, mobile, email) VALUES 
('2023001', 'John', 'Doe', 'M', '123456', '1234567890', 'john@example.com'),
('2023002', 'Jane', 'Smith', 'F', '234567', '2345678901', 'jane@example.com')");

executeSql($db, "INSERT INTO student_program (Sid, program_code, intake, startYear, endYear) VALUES 
('2023001', 'CS101', '2023', 2023, 2026),
('2023002', 'BBA101', '2023', 2023, 2026)");

// Verify tables and data
echo "<h3>Verifying Tables and Data</h3>";
foreach ($tables as $tableName => $expectedColumns) {
    if (!tableExists($db, $tableName)) {
        echo "Error: Table '$tableName' was not created successfully<br>";
        continue;
    }

    $existingColumns = getTableColumns($db, $tableName);
    $missingColumns = array_diff_key($expectedColumns, $existingColumns);

    if (!empty($missingColumns)) {
        echo "Warning: Missing columns in '$tableName': " . implode(', ', array_keys($missingColumns)) . "<br>";
    } else {
        echo "Table '$tableName' structure is correct<br>";
    }

    $result = $db->query("SELECT COUNT(*) as count FROM $tableName");
    if ($result) {
        $count = $result->fetch_object()->count;
        echo "Records in $tableName: $count<br>";
    }
}

$db->close();
?> 