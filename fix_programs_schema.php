<?php
require_once 'db/connect.php';

echo "Starting programs table schema fix...\n";
echo str_repeat('=', 50) . "\n";

// Array of columns to add with their definitions
$columns_to_add = [
    'program_type' => "ENUM('degree','diploma','certificate') NOT NULL DEFAULT 'degree' AFTER program_name",
    'study_mode' => "ENUM('fulltime','parttime','distance') NOT NULL DEFAULT 'fulltime' AFTER program_type",
    'period_mode' => "ENUM('semester','term') NOT NULL DEFAULT 'semester' AFTER study_mode",
    'program_duration' => "DECIMAL(5,2) DEFAULT NULL AFTER period_mode",
    'program_description' => "TEXT AFTER program_duration",
    'department_id' => "INT(11) DEFAULT NULL AFTER program_description",
    'is_active' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER department_id"
];

// Check current columns
$current_columns = [];
$result = $db->query("DESCRIBE programs");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $current_columns[] = $row['Field'];
    }
    $result->free();
}

echo "Current columns: " . implode(', ', $current_columns) . "\n\n";

// Add missing columns
foreach ($columns_to_add as $column_name => $column_definition) {
    if (!in_array($column_name, $current_columns)) {
        $sql = "ALTER TABLE programs ADD COLUMN $column_name $column_definition";
        echo "Adding column: $column_name\n";

        if ($db->query($sql)) {
            echo "✓ Successfully added $column_name\n";
        } else {
            echo "✗ Failed to add $column_name: " . $db->error . "\n";
        }
    } else {
        echo "- Column $column_name already exists\n";
    }
}

// Check if departments table exists, if not create it
$result = $db->query("SHOW TABLES LIKE 'departments'");
if ($result->num_rows == 0) {
    echo "\nCreating departments table...\n";
    $create_dept_sql = "CREATE TABLE departments (
        id INT(11) NOT NULL AUTO_INCREMENT,
        department_name VARCHAR(255) NOT NULL,
        deptId VARCHAR(50) DEFAULT NULL,
        department_code VARCHAR(50) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY deptId (deptId),
        UNIQUE KEY department_code (department_code)
    )";

    if ($db->query($create_dept_sql)) {
        echo "✓ Successfully created departments table\n";

        // Insert some default departments
        $default_depts = [
            ['Computer Science', 'CS', 'COMP001'],
            ['Mathematics', 'MATH', 'MATH001'],
            ['Physics', 'PHYS', 'PHYS001'],
            ['Chemistry', 'CHEM', 'CHEM001'],
            ['Biology', 'BIO', 'BIO001']
        ];

        foreach ($default_depts as $dept) {
            $stmt = $db->prepare("INSERT INTO departments (department_name, deptId, department_code) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $dept[0], $dept[1], $dept[2]);
            if ($stmt->execute()) {
                echo "✓ Added department: {$dept[0]}\n";
            } else {
                echo "✗ Failed to add department {$dept[0]}: " . $stmt->error . "\n";
            }
            $stmt->close();
        }
    } else {
        echo "✗ Failed to create departments table: " . $db->error . "\n";
    }
} else {
    echo "\n✓ Departments table already exists\n";
}

// Check if student_program table exists
$result = $db->query("SHOW TABLES LIKE 'student_program'");
if ($result->num_rows == 0) {
    echo "\nCreating student_program table...\n";
    $create_sp_sql = "CREATE TABLE student_program (
        id INT(11) NOT NULL AUTO_INCREMENT,
        student_id VARCHAR(50) NOT NULL,
        program_code VARCHAR(50) NOT NULL,
        enrollment_date DATE DEFAULT CURDATE(),
        status ENUM('active','inactive','completed','suspended') DEFAULT 'active',
        PRIMARY KEY (id),
        KEY student_id (student_id),
        KEY program_code (program_code),
        FOREIGN KEY (program_code) REFERENCES programs(program_code) ON DELETE CASCADE
    )";

    if ($db->query($create_sp_sql)) {
        echo "✓ Successfully created student_program table\n";
    } else {
        echo "✗ Failed to create student_program table: " . $db->error . "\n";
    }
} else {
    echo "\n✓ student_program table already exists\n";
}

echo "\n" . str_repeat('=', 50) . "\n";
echo "Schema fix completed!\n";

// Show final structure
echo "\nFinal programs table structure:\n";
$result = $db->query("DESCRIBE programs");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo sprintf('%-20s %-15s %-10s %-10s %-20s',
            $row['Field'], $row['Type'], $row['Null'], $row['Key'], $row['Default'] ?? 'NULL') . "\n";
    }
    $result->free();
}

$db->close();
?>
