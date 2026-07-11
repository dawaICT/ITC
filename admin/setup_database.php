<?php
include "includes/admin.php";
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Function to execute SQL with error handling
function executeSQL($db, $sql, $description) {
    try {
        if (!$db->query($sql)) {
            throw new Exception($db->error);
        }
        echo "<div style='color: green;'>✓ {$description} successful</div>";
    } catch (Exception $e) {
        echo "<div style='color: red;'>✗ {$description} failed: " . $e->getMessage() . "</div>";
        return false;
    }
    return true;
}

// Styling
echo "
<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .container { max-width: 800px; margin: 0 auto; }
    .success { color: green; margin: 5px 0; }
    .error { color: red; margin: 5px 0; }
    .btn { 
        display: inline-block;
        padding: 10px 20px;
        background-color: #4CAF50;
        color: white;
        text-decoration: none;
        border-radius: 4px;
        margin-top: 20px;
    }
    .btn:hover { background-color: #45a049; }
</style>
<div class='container'>
<h2>Database Setup</h2>
";

// Drop existing tables
$drop_tables = "
DROP TABLE IF EXISTS program_courses;
DROP TABLE IF EXISTS programs;
";

// Create programs table
$programs_table = "
CREATE TABLE IF NOT EXISTS programs (
    program_code VARCHAR(20) NOT NULL,
    program_name VARCHAR(255) NOT NULL,
    program_type ENUM('degree', 'diploma', 'certificate') NOT NULL,
    study_mode ENUM('semester', 'term') NOT NULL,
    program_duration DECIMAL(4,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (program_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

// Create program_courses table
$program_courses_table = "
CREATE TABLE IF NOT EXISTS program_courses (
    id INT AUTO_INCREMENT,
    program_code VARCHAR(20) NOT NULL,
    course_code VARCHAR(20) NOT NULL,
    course_name VARCHAR(255) NOT NULL,
    semester INT NOT NULL,
    credits INT DEFAULT 3,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_course_per_program (program_code, course_code),
    CONSTRAINT fk_program_courses_program
        FOREIGN KEY (program_code) 
        REFERENCES programs(program_code)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

// Execute the SQL commands
try {
    // Drop existing tables
    executeSQL($db, $drop_tables, "Dropping existing tables");

    // Create tables
    executeSQL($db, $programs_table, "Creating programs table");
    executeSQL($db, $program_courses_table, "Creating program_courses table");

    // Sample programs data
    $sample_programs = [
        [
            'code' => 'DIP-IT',
            'name' => 'Diploma in Information Technology',
            'type' => 'diploma',
            'mode' => 'semester',
            'duration_years' => 3.00
        ],
        [
            'code' => 'CERT-WD',
            'name' => 'Certificate in Web Development',
            'type' => 'certificate',
            'mode' => 'term',
            'duration_years' => 0.50
        ]
    ];

    // Insert sample programs
    $insert_program = $db->prepare("
        INSERT INTO programs (program_code, program_name, program_type, study_mode, program_duration) 
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($sample_programs as $program) {
        $insert_program->bind_param("ssssd", 
            $program['code'], 
            $program['name'], 
            $program['type'], 
            $program['mode'], 
            $program['duration_years']
        );
        if ($insert_program->execute()) {
            echo "<div class='success'>✓ Added program: {$program['name']}</div>";
        } else {
            echo "<div class='error'>✗ Failed to add program: {$program['name']}</div>";
        }
    }

    // Sample courses
    $sample_courses = [
        // Diploma IT courses
        ['DIP-IT', 'IT101', 'IT Fundamentals', 1],
        ['DIP-IT', 'IT102', 'Network Basics', 1],
        ['DIP-IT', 'IT103', 'Web Technologies', 1],
        ['DIP-IT', 'IT201', 'System Administration', 2],

        // Certificate Web Development courses
        ['CERT-WD', 'WD101', 'HTML and CSS', 1],
        ['CERT-WD', 'WD102', 'JavaScript Fundamentals', 1]
    ];

    // Insert sample courses
    $insert_course = $db->prepare("
        INSERT INTO program_courses (program_code, course_code, course_name, semester) 
        VALUES (?, ?, ?, ?)
    ");

    foreach ($sample_courses as $course) {
        $insert_course->bind_param("sssi", 
            $course[0], // program_code
            $course[1], // course_code
            $course[2], // course_name
            $course[3]  // semester
        );
        if ($insert_course->execute()) {
            echo "<div class='success'>✓ Added course: {$course[2]} to {$course[0]}</div>";
        } else {
            echo "<div class='error'>✗ Failed to add course: {$course[2]}</div>";
        }
    }

    echo "<div style='margin-top: 20px; padding: 10px; background-color: #dff0d8; border: 1px solid #d6e9c6; color: #3c763d;'>
            <strong>Success!</strong> Database setup completed successfully!
          </div>";

} catch (Exception $e) {
    echo "<div style='margin-top: 20px; padding: 10px; background-color: #f2dede; border: 1px solid #ebccd1; color: #a94442;'>
            <strong>Error:</strong> " . $e->getMessage() . "
          </div>";
}

echo "<a href='../programs.php' class='btn'>Go to Programs Page</a>";
echo "</div>";
?> 
