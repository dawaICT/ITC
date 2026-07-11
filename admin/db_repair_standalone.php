<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'wucportal';

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$queries = [
    "CREATE TABLE IF NOT EXISTS grading_scales (
        scale_id INT PRIMARY KEY AUTO_INCREMENT,
        scale_name VARCHAR(50), 
        min_score INT,
        max_score INT,
        grade_letter VARCHAR(5),
        classification VARCHAR(50)
    )",
    "CREATE TABLE IF NOT EXISTS course_configs (
        course_config_id INT PRIMARY KEY AUTO_INCREMENT,
        course_code VARCHAR(50) UNIQUE, 
        ca_weight INT DEFAULT 40,
        exam_weight INT DEFAULT 60,
        pass_mark INT DEFAULT 50,
        fee_threshold DECIMAL(5,2) DEFAULT 0.50
    )",
    "TRUNCATE TABLE grading_scales",
    "INSERT INTO grading_scales (scale_name, min_score, max_score, grade_letter, classification) VALUES 
    ('Generic', 90, 100, 'A+', 'Distinction'),
    ('Generic', 80, 89, 'A', 'Distinction'),
    ('Generic', 75, 79, 'B+', 'Merit'),
    ('Generic', 70, 74, 'B', 'Merit'),
    ('Generic', 65, 69, 'B-', 'Credit'),
    ('Generic', 60, 64, 'C+', 'Credit'),
    ('Generic', 50, 59, 'C', 'Pass'),
    ('Generic', 45, 49, 'D', 'Bare Pass'),
    ('Generic', 0, 44, 'E', 'Fail')"
];

foreach ($queries as $q) {
    if ($conn->query($q) === TRUE) {
        echo "Query success\n";
    } else {
        echo "Query failed: " . $conn->error . "\n";
    }
}
$conn->close();
?>
