<?php
http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
echo "This destructive legacy database reset script has been disabled for security reasons.\n";
echo "Use reviewed migrations or schema tools instead.\n";
exit;

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once 'db/connect.php';

// Disable foreign key checks to allow dropping tables with dependencies
$db->query("SET FOREIGN_KEY_CHECKS = 0");

$sql_commands = [
    // Drop existing tables
    "DROP TABLE IF EXISTS student_program",
    "DROP TABLE IF EXISTS students",
    "DROP TABLE IF EXISTS programs",

    // Create students table
    "CREATE TABLE students (
        SID VARCHAR(50) PRIMARY KEY,
        title VARCHAR(10),
        Fname VARCHAR(100),
        Lname VARCHAR(100),
        sex VARCHAR(10),
        dob DATE,
        country VARCHAR(50),
        nrc_pass VARCHAR(50),
        mobile VARCHAR(20),
        email VARCHAR(100),
        status VARCHAR(20) DEFAULT 'Active',
        h_addre TEXT,
        p_addre TEXT,
        sponsor VARCHAR(50),
        next_kin VARCHAR(100),
        next_kin_mobile VARCHAR(20),
        relat VARCHAR(50),
        school VARCHAR(100),
        grade VARCHAR(20),
        dte1 YEAR,
        dte2 YEAR,
        english_grade VARCHAR(10),
        math_grade VARCHAR(10),
        bursary_percentage DECIMAL(5,2),
        results VARCHAR(255),
        nrc_file VARCHAR(255),
        profile_image VARCHAR(255) DEFAULT 'default.jpg',
        dte_adm TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",

    // Create programs table
    "CREATE TABLE programs (
        program_code VARCHAR(50) PRIMARY KEY,
        program_name VARCHAR(255)
    )",

    // Create student_program table
    "CREATE TABLE student_program (
        id INT AUTO_INCREMENT PRIMARY KEY,
        Sid VARCHAR(50),
        program_code VARCHAR(50),
        intake VARCHAR(50),
        mode VARCHAR(50),
        startYear INT,
        endYear INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        term VARCHAR(10),
        FOREIGN KEY (Sid) REFERENCES students(SID) ON DELETE CASCADE ON UPDATE CASCADE,
        FOREIGN KEY (program_code) REFERENCES programs(program_code) ON DELETE CASCADE ON UPDATE CASCADE
    )",

    // Create assessments table
    "CREATE TABLE IF NOT EXISTS assessments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        SID VARCHAR(50) NOT NULL,
        course_code VARCHAR(50) NOT NULL,
        semester VARCHAR(20) NOT NULL,
        assess_type VARCHAR(50) NOT NULL,
        assess_num VARCHAR(10) NOT NULL,
        marks VARCHAR(10) NOT NULL,
        year VARCHAR(10) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sid_course (SID, course_code),
        INDEX idx_semester_year (semester, year)
    )",

    // Insert sample data
    "INSERT INTO programs (program_code, program_name) VALUES 
    ('CS101', 'Computer Science'),
    ('BBA101', 'Business Administration'),
    ('ENG101', 'Engineering')",

    "INSERT INTO students (SID, Fname, Lname, sex, nrc_pass, mobile, email) VALUES 
    ('2023001', 'John', 'Doe', 'M', '123456', '1234567890', 'john@example.com'),
    ('2023002', 'Jane', 'Smith', 'F', '234567', '2345678901', 'jane@example.com')",

    "INSERT INTO student_program (Sid, program_code, intake, mode, startYear, endYear) VALUES 
    ('2023001', 'CS101', '2023', 'semester', 2023, 2026),
    ('2023002', 'BBA101', '2023', 'semester', 2023, 2026)",

    // Create processed_applicants_added table to track added applicants
    "CREATE TABLE IF NOT EXISTS processed_applicants_added (
        id INT AUTO_INCREMENT PRIMARY KEY,
        applicant_id INT NOT NULL,
        student_id VARCHAR(50) NOT NULL,
        added_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        added_by INT
    )"
];

foreach ($sql_commands as $sql) {
    if ($db->query($sql)) {
        echo "Success: " . substr($sql, 0, 50) . "...\n";
    } else {
        echo "Error: " . $db->error . "\n";
        echo "SQL: " . $sql . "\n";
    }
}

// Verify tables and data
$tables = ['students', 'programs', 'student_program', 'assessments'];
foreach ($tables as $table) {
    $result = $db->query("SELECT COUNT(*) as count FROM $table");
    if ($result) {
        $count = $result->fetch_object()->count;
        echo "\nRecords in $table: $count\n";
    } else {
        echo "\nError checking $table: " . $db->error . "\n";
    }
}

$db->query("SET FOREIGN_KEY_CHECKS = 1");

$db->close();
?> 