<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Open a file for logging
$log = fopen("setup_log.txt", "w") or die("Unable to open log file!");

function writeLog($message) {
    global $log;
    fwrite($log, $message . "\n");
}

// Database configuration
$db_host = "localhost";
$db_user = "root";
$db_password = "";
$db_name = "wucportal";

// Create connection
$db = new mysqli($db_host, $db_user, $db_password, $db_name);

// Check connection
if ($db->connect_error) {
    writeLog("Connection failed: " . $db->connect_error);
    die();
}

// Create tables
$tables = [
    "CREATE TABLE IF NOT EXISTS students (
        SID VARCHAR(50) PRIMARY KEY,
        Fname VARCHAR(100),
        Lname VARCHAR(100),
        sex VARCHAR(10),
        nrc_pass VARCHAR(50),
        mobile VARCHAR(20),
        email VARCHAR(100),
        profile_image VARCHAR(255) DEFAULT 'default.jpg'
    )",
    "CREATE TABLE IF NOT EXISTS programs (
        program_code VARCHAR(50) PRIMARY KEY,
        program_name VARCHAR(255)
    )",
    "CREATE TABLE IF NOT EXISTS student_program (
        id INT AUTO_INCREMENT PRIMARY KEY,
        Sid VARCHAR(50),
        program_code VARCHAR(50),
        intake VARCHAR(50),
        startYear INT,
        endYear INT,
        FOREIGN KEY (Sid) REFERENCES students(SID) ON DELETE CASCADE ON UPDATE CASCADE,
        FOREIGN KEY (program_code) REFERENCES programs(program_code) ON DELETE CASCADE ON UPDATE CASCADE
    )"
];

// Execute table creation
foreach ($tables as $sql) {
    if ($db->query($sql) === TRUE) {
        writeLog("Table created successfully");
    } else {
        writeLog("Error creating table: " . $db->error);
    }
}

// Function to describe table structure
function describeTable($db, $tableName) {
    $result = $db->query("DESCRIBE $tableName");
    if (!$result) {
        writeLog("Error describing table $tableName: " . $db->error);
        return;
    }

    writeLog("\nTable: $tableName");
    writeLog("--------------------");
    while ($row = $result->fetch_assoc()) {
        writeLog("{$row['Field']} - {$row['Type']} - {$row['Null']} - {$row['Key']}");
    }
    writeLog("");
}

// Check table structures
$tableNames = ['students', 'programs', 'student_program'];
foreach ($tableNames as $table) {
    describeTable($db, $table);
}

// Insert sample data if tables are empty
$result = $db->query("SELECT COUNT(*) as count FROM students");
$count = $result->fetch_object()->count;

if ($count == 0) {
    // Insert sample program
    $db->query("INSERT INTO programs (program_code, program_name) VALUES ('CS101', 'Computer Science')");

    // Insert sample student
    $db->query("INSERT INTO students (SID, Fname, Lname, sex, nrc_pass, mobile, email) 
                VALUES ('2023001', 'John', 'Doe', 'M', '123456', '1234567890', 'john@example.com')");

    // Insert sample student program
    $db->query("INSERT INTO student_program (Sid, program_code, intake, startYear, endYear) 
                VALUES ('2023001', 'CS101', '2023', 2023, 2026)");

    writeLog("\nSample data inserted successfully");
}

// Close connection and log file
$db->close();
fclose($log);

// Output the log file contents
echo file_get_contents("setup_log.txt");
?> 