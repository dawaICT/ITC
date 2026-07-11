<?php
require_once 'db/connect.php';

echo "Adding sample data for testing...\n";
echo str_repeat('=', 50) . "\n";

// Check if departments table has data
$result = $db->query("SELECT COUNT(*) as count FROM departments");
$row = $result->fetch_assoc();
$dept_count = $row['count'];

if ($dept_count == 0) {
    echo "Adding sample departments...\n";
    $departments = [
        ['Computer Science', 'CS', 'COMP001'],
        ['Mathematics', 'MATH', 'MATH001'],
        ['Physics', 'PHYS', 'PHYS001'],
        ['Chemistry', 'CHEM', 'CHEM001'],
        ['Biology', 'BIO', 'BIO001'],
        ['Business Administration', 'BA', 'BUS001'],
        ['Economics', 'ECON', 'ECON001'],
        ['English Literature', 'ENG', 'ENG001']
    ];

    $stmt = $db->prepare("INSERT INTO departments (department_name, deptId, department_code) VALUES (?, ?, ?)");
    foreach ($departments as $dept) {
        $stmt->bind_param("sss", $dept[0], $dept[1], $dept[2]);
        if ($stmt->execute()) {
            echo "✓ Added: {$dept[0]}\n";
        } else {
            echo "✗ Failed: {$dept[0]} - " . $stmt->error . "\n";
        }
    }
    $stmt->close();
} else {
    echo "✓ Departments table already has $dept_count records\n";
}

// Check if programs table has data
$result = $db->query("SELECT COUNT(*) as count FROM programs");
$row = $result->fetch_assoc();
$prog_count = $row['count'];

if ($prog_count == 0) {
    echo "\nAdding sample programs...\n";

    // Get first department ID
    $dept_result = $db->query("SELECT id FROM departments LIMIT 1");
    $dept_row = $dept_result->fetch_assoc();
    $dept_id = $dept_row['id'];
    $dept_result->free();

    $programs = [
        ['MATH-BSC', 'Bachelor of Science in Mathematics', 'degree', 'semester', 36, 'Advanced mathematics program', $dept_id, 1],
        ['BA-DIP', 'Diploma in Business Administration', 'diploma', 'term', 24, 'Business management diploma', $dept_id, 1],
        ['ENG-CERT', 'Certificate in English Literature', 'certificate', 'term', 12, 'English literature certificate', $dept_id, 1]
    ];

    $stmt = $db->prepare("INSERT INTO programs (program_code, program_name, program_type, study_mode, program_duration, program_description, department_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($programs as $prog) {
        $stmt->bind_param("ssssdssii", $prog[0], $prog[1], $prog[2], $prog[3], $prog[4], $prog[5], $prog[6], $prog[7]);
        if ($stmt->execute()) {
            echo "✓ Added: {$prog[1]}\n";
        } else {
            echo "✗ Failed: {$prog[1]} - " . $stmt->error . "\n";
        }
    }
    $stmt->close();
} else {
    echo "\n✓ Programs table already has $prog_count records\n";
}

// Check if student_program table has data
$result = $db->query("SELECT COUNT(*) as count FROM student_program");
$row = $result->fetch_assoc();
$sp_count = $row['count'];

if ($sp_count == 0) {
    echo "\nAdding sample student enrollments...\n";

    // Get a program code
    $prog_result = $db->query("SELECT program_code FROM programs LIMIT 1");
    $prog_row = $prog_result->fetch_assoc();
    $program_code = $prog_row['program_code'];
    $prog_result->free();

    $enrollments = [
        ['STU001', $program_code, '2024-01-15', 'active'],
        ['STU002', $program_code, '2024-01-16', 'active'],
        ['STU003', $program_code, '2024-01-17', 'active'],
        ['STU004', $program_code, '2024-01-18', 'active'],
        ['STU005', $program_code, '2024-01-19', 'active']
    ];

    $stmt = $db->prepare("INSERT INTO student_program (student_id, program_code, enrollment_date, status) VALUES (?, ?, ?, ?)");
    foreach ($enrollments as $enrollment) {
        $stmt->bind_param("ssss", $enrollment[0], $enrollment[1], $enrollment[2], $enrollment[3]);
        if ($stmt->execute()) {
            echo "✓ Added enrollment: {$enrollment[0]}\n";
        } else {
            echo "✗ Failed enrollment: {$enrollment[0]} - " . $stmt->error . "\n";
        }
    }
    $stmt->close();
} else {
    echo "\n✓ student_program table already has $sp_count records\n";
}

echo "\n" . str_repeat('=', 50) . "\n";
echo "Sample data addition completed!\n";

$db->close();
?>
