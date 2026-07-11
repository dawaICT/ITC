<?php
// Test script to verify database schema
error_reporting(E_ALL);
ini_set('display_errors', '0');

echo "<h1>Database Schema Test</h1>";

// Include database connection
require_once "includes/admin.php";

if (!isset($db) || $db->connect_error) {
    die("Database connection failed: " . ($db->connect_error ?? "Unknown error"));
}

echo "<h2>Checking Tables</h2>";

// Check if required tables exist
$required_tables = ['departments', 'programs', 'student_program'];
$missing_tables = [];

foreach ($required_tables as $table) {
    $check_query = "SHOW TABLES LIKE '$table'";
    $result = $db->query($check_query);
    
    if ($result && $result->num_rows > 0) {
        echo "<p style='color: green;'>✓ $table table exists</p>";
    } else {
        echo "<p style='color: red;'>✗ $table table missing</p>";
        $missing_tables[] = $table;
    }
}

if (!empty($missing_tables)) {
    echo "<p style='color: red;'><strong>Missing tables: " . implode(', ', $missing_tables) . "</strong></p>";
    echo "<p><a href='fix_schema.php' class='btn btn-warning'>Fix Schema</a></p>";
    exit;
}

echo "<h2>Checking Table Structures</h2>";

// Check departments table structure
echo "<h3>Departments Table</h3>";
$dept_result = $db->query("DESCRIBE departments");
if ($dept_result) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $dept_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Check programs table structure
echo "<h3>Programs Table</h3>";
$prog_result = $db->query("DESCRIBE programs");
if ($prog_result) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $prog_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<h2>Checking Data</h2>";

// Check data counts
$dept_count = $db->query("SELECT COUNT(*) as count FROM departments")->fetch_assoc()['count'];
$prog_count = $db->query("SELECT COUNT(*) as count FROM programs")->fetch_assoc()['count'];
$student_prog_count = $db->query("SELECT COUNT(*) as count FROM student_program")->fetch_assoc()['count'];

echo "<ul>";
echo "<li>Departments: $dept_count</li>";
echo "<li>Programs: $prog_count</li>";
echo "<li>Student Programs: $student_prog_count</li>";
echo "</ul>";

// Show sample data
if ($dept_count > 0) {
    echo "<h3>Sample Departments</h3>";
    $dept_data = $db->query("SELECT * FROM departments LIMIT 5");
    if ($dept_data) {
        echo "<table border='1' style='border-collapse: collapse;'>";
        $first = true;
        while ($row = $dept_data->fetch_assoc()) {
            if ($first) {
                echo "<tr>";
                foreach ($row as $key => $value) {
                    echo "<th>$key</th>";
                }
                echo "</tr>";
                $first = false;
            }
            echo "<tr>";
            foreach ($row as $value) {
                echo "<td>" . htmlspecialchars($value) . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }
}

if ($prog_count > 0) {
    echo "<h3>Sample Programs</h3>";
    $prog_data = $db->query("SELECT * FROM programs LIMIT 5");
    if ($prog_data) {
        echo "<table border='1' style='border-collapse: collapse;'>";
        $first = true;
        while ($row = $prog_data->fetch_assoc()) {
            if ($first) {
                echo "<tr>";
                foreach ($row as $key => $value) {
                    echo "<th>$key</th>";
                }
                echo "</tr>";
                $first = false;
            }
            echo "<tr>";
            foreach ($row as $value) {
                echo "<td>" . htmlspecialchars($value) . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }
}

echo "<h2>Test Complete</h2>";
echo "<p style='color: green; font-weight: bold;'>✓ Schema test completed successfully!</p>";
echo "<p><a href='programs.php' class='btn btn-primary'>Go to Programs Management</a></p>";

$db->close();
?> 