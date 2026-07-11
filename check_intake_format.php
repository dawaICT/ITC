<?php
require 'db/connect.php';

echo "=== Checking Intake Format in student_program ===\n\n";

// Check distinct intake values
$result = $db->query("SELECT DISTINCT intake FROM student_program ORDER BY intake DESC LIMIT 10");
echo "Existing intake values:\n";
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "  - " . ($row['intake'] ?? 'NULL') . "\n";
    }
} else {
    echo "  No records found\n";
}

// Check if semester_registration table exists and its academic_year format
$tables = $db->query("SHOW TABLES LIKE 'semester_registration'");
if ($tables && $tables->num_rows > 0) {
    echo "\n=== Checking semester_registration academic_year format ===\n";
    $result = $db->query("SELECT DISTINCT academic_year FROM semester_registration ORDER BY academic_year DESC LIMIT 10");
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo "  - " . ($row['academic_year'] ?? 'NULL') . "\n";
        }
    } else {
        echo "  No records found\n";
    }
}

// Check invoices table
$result = $db->query("SELECT DISTINCT academic_year FROM invoices ORDER BY academic_year DESC LIMIT 10");
echo "\n=== Checking invoices academic_year format ===\n";
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "  - " . ($row['academic_year'] ?? 'NULL') . "\n";
    }
} else {
    echo "  No records found\n";
}

// Check student_courses table
$result = $db->query("SELECT DISTINCT academic_year FROM student_courses ORDER BY academic_year DESC LIMIT 10");
echo "\n=== Checking student_courses academic_year format ===\n";
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "  - " . ($row['academic_year'] ?? 'NULL') . "\n";
    }
} else {
    echo "  No records found\n";
}

// Get current month and show what the code would generate
$current_year = date('Y');
$current_month = (int)date('n');
$academic_year = ($current_month >= 9) ? $current_year : $current_year - 1;
$academic_year_display = $academic_year . '/' . ($academic_year + 1);

echo "\n=== Current Logic (regNewStud.php) ===\n";
echo "Current date: " . date('Y-m-d') . "\n";
echo "Current month: $current_month\n";
echo "Calculated academic_year: $academic_year\n";
echo "Generated format: $academic_year_display\n";

$db->close();
?>
