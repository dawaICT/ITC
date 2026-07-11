<?php
require "db/connect.php";

// Drop existing tables in correct order
$db->query("SET FOREIGN_KEY_CHECKS = 0");

$tables = [
    'student_program',
    'student_login',
    'students',
    'programs',
    'announcement'
];

foreach ($tables as $table) {
    $db->query("DROP TABLE IF EXISTS $table");
}

// Create tables in correct order with proper foreign key constraints
$sql = [
    // Create programs table first (referenced by student_program)
    "CREATE TABLE programs (
        program_code VARCHAR(50) PRIMARY KEY,
        program_name VARCHAR(255)
    )",

    // Create students table (referenced by student_program and student_login)
    "CREATE TABLE students (
        SID VARCHAR(50) PRIMARY KEY,
        Fname VARCHAR(100),
        Lname VARCHAR(100),
        sex VARCHAR(10),
        nrc_pass VARCHAR(50),
        mobile VARCHAR(20),
        email VARCHAR(100),
        profile_image VARCHAR(255) DEFAULT 'default.jpg'
    )",

    // Create student_login table with foreign key
    "CREATE TABLE IF NOT EXISTS student_login (
        Sid VARCHAR(50) PRIMARY KEY,
        Password VARCHAR(255) NOT NULL,
        FOREIGN KEY (Sid) REFERENCES students(SID) ON DELETE CASCADE ON UPDATE CASCADE
    )",

    // Create student_program table with foreign keys
    "CREATE TABLE student_program (
        id INT AUTO_INCREMENT PRIMARY KEY,
        Sid VARCHAR(50),
        program_code VARCHAR(50),
        intake VARCHAR(50),
        startYear INT,
        endYear INT,
        FOREIGN KEY (Sid) REFERENCES students(SID) ON DELETE CASCADE ON UPDATE CASCADE,
        FOREIGN KEY (program_code) REFERENCES programs(program_code) ON DELETE CASCADE ON UPDATE CASCADE
    )",

    // Create announcement table (no foreign keys)
    "CREATE TABLE announcement (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255),
        descript TEXT,
        created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
];

// Create tables
foreach ($sql as $query) {
    if ($db->query($query)) {
        echo "Table created successfully<br>";
    } else {
        echo "Error creating table: " . $db->error . "<br>";
    }
}

// Insert test data
try {
    // First insert program (referenced by student_program)
    $sql = "INSERT INTO programs (program_code, program_name) VALUES ('CS101', 'Bachelor of Computer Science')";
    $db->query($sql);
    echo "Test program created successfully<br>";

    // Then insert student (referenced by student_login and student_program)
    $sql = "INSERT INTO students (SID, Fname, Lname, sex, nrc_pass, mobile, email) 
            VALUES ('test123', 'John', 'Doe', 'Male', 'ID123456', '+260123456789', 'john.doe@example.com')";
    $db->query($sql);
    echo "Test student created successfully<br>";

    // Then insert student login (references student)
    $testPassword = md5("password123");
    $sql = "INSERT INTO student_login (Sid, Password) VALUES ('test123', '$testPassword') ON DUPLICATE KEY UPDATE Password = VALUES(Password)";
    $db->query($sql);
    echo "Test login created successfully<br>";

    // Then insert student program (references both student and program)
    $sql = "INSERT INTO student_program (Sid, program_code, intake, startYear, endYear) 
            VALUES ('test123', 'CS101', 'January 2023', 2023, 2026)";
    $db->query($sql);
    echo "Test student program created successfully<br>";

    // Finally insert announcement (no references)
    $sql = "INSERT INTO announcement (title, descript) 
            VALUES ('Welcome to the New Semester', 'We hope you have a great academic year ahead!')";
    $db->query($sql);
    echo "Test announcement created successfully<br>";

    echo "<br>You can login with:<br>";
    echo "Student ID: test123<br>";
    echo "Password: password123<br>";

} catch (Exception $e) {
    echo "Error inserting test data: " . $e->getMessage() . "<br>";
}

$db->query("SET FOREIGN_KEY_CHECKS = 1");
$db->close();
?> 