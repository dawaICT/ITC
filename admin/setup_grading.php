<?php
include "includes/admin.php";

// 1. Create grading_scales table
$sql1 = "CREATE TABLE IF NOT EXISTS grading_scales (
    scale_id INT PRIMARY KEY AUTO_INCREMENT,
    scale_name VARCHAR(50), -- e.g., 'Generic', 'Medical', 'Certificate'
    min_score INT,
    max_score INT,
    grade_letter VARCHAR(5),
    classification VARCHAR(50)
)";

if ($db->query($sql1)) {
    echo "Table 'grading_scales' created successfully.<br>";
} else {
    echo "Error creating 'grading_scales': " . $db->error . "<br>";
}

// 2. Create course_configs table
$sql2 = "CREATE TABLE IF NOT EXISTS course_configs (
    course_config_id INT PRIMARY KEY AUTO_INCREMENT,
    course_code VARCHAR(50) UNIQUE, 
    ca_weight INT DEFAULT 40,
    exam_weight INT DEFAULT 60,
    pass_mark INT DEFAULT 50,
    fee_threshold DECIMAL(5,2) DEFAULT 0.50
)";

if ($db->query($sql2)) {
    echo "Table 'course_configs' created successfully.<br>";
} else {
    echo "Error creating 'course_configs': " . $db->error . "<br>";
}

// 3. Clear existing data to avoid duplicates for this setup
$db->query("TRUNCATE TABLE grading_scales");
// Not truncating course_configs to keep it safe if run multiple times, but for now I will rely on INSERT IGNORE/UPDATE

// 4. Insert Standard Grading Scale (Based on previous exams.php logic)
$scales = [
    // Standard Degree/Diploma Scale (Generic)
    ['Generic', 90, 100, 'A+', 'Distinction'],
    ['Generic', 80, 89, 'A', 'Distinction'],
    ['Generic', 75, 79, 'B+', 'Merit'],
    ['Generic', 70, 74, 'B', 'Merit'],
    ['Generic', 65, 69, 'B-', 'Credit'],
    ['Generic', 60, 64, 'C+', 'Credit'],
    ['Generic', 50, 59, 'C', 'Pass'],
    ['Generic', 45, 49, 'D', 'Bare Pass'],
    ['Generic', 0, 44, 'E', 'Fail']
];

$stmt_scale = $db->prepare("INSERT INTO grading_scales (scale_name, min_score, max_score, grade_letter, classification) VALUES (?, ?, ?, ?, ?)");
foreach ($scales as $s) {
    $stmt_scale->bind_param("siiss", $s[0], $s[1], $s[2], $s[3], $s[4]);
    $stmt_scale->execute();
}
echo "Inserted default grading scales.<br>";

// 5. Populate course_configs for existing courses (Default 40/60)
$res_courses = $db->query("SELECT course_code FROM courses");
if ($res_courses) {
    $stmt_config = $db->prepare("INSERT IGNORE INTO course_configs (course_code, ca_weight, exam_weight, pass_mark) VALUES (?, 40, 60, 50)");
    while ($row = $res_courses->fetch_assoc()) {
        $stmt_config->bind_param("s", $row['course_code']);
        $stmt_config->execute();
    }
    echo "Populated default course configs for existing courses.<br>";
}

echo "Setup complete.";
?>
