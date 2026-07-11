<?php
// fix_db.php
require 'includes/admin.php';

$sql = "
CREATE TABLE IF NOT EXISTS course_configs (
    course_config_id INT PRIMARY KEY AUTO_INCREMENT,
    course_code VARCHAR(50) UNIQUE, 
    ca_weight INT DEFAULT 40,
    exam_weight INT DEFAULT 60,
    pass_mark INT DEFAULT 50,
    fee_threshold DECIMAL(5,2) DEFAULT 0.50
)";

if ($db->query($sql) === TRUE) {
    echo "Table course_configs created successfully\n";
} else {
    echo "Error creating table: " . $db->error . "\n";
}

$sql2 = "
CREATE TABLE IF NOT EXISTS grading_scales (
    scale_id INT PRIMARY KEY AUTO_INCREMENT,
    scale_name VARCHAR(50), 
    min_score INT,
    max_score INT,
    grade_letter VARCHAR(5),
    classification VARCHAR(50)
)";

if ($db->query($sql2) === TRUE) {
    echo "Table grading_scales created successfully\n";
} else {
    echo "Error creating table: " . $db->error . "\n";
}
?>
