<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

echo "Testing database connection...\n";

// Define IS_SCRIPT to bypass session requirements
define('IS_SCRIPT', true);

// Try to include the admin file
try {
    require_once "includes/admin.php";
    echo "✓ Admin file included successfully\n";
    
    if (isset($db)) {
        echo "✓ Database connection variable exists\n";
        
        if (!$db->connect_error) {
            echo "✓ Database connection successful\n";
            
            // Test basic queries
            $result = $db->query("SELECT COUNT(*) as count FROM departments");
            if ($result) {
                $count = $result->fetch_assoc()['count'];
                echo "✓ Departments table accessible: $count records\n";
            } else {
                echo "✗ Error accessing departments table: " . $db->error . "\n";
            }
            
            $result = $db->query("SELECT COUNT(*) as count FROM programs");
            if ($result) {
                $count = $result->fetch_assoc()['count'];
                echo "✓ Programs table accessible: $count records\n";
            } else {
                echo "✗ Error accessing programs table: " . $db->error . "\n";
            }
            
            $result = $db->query("SELECT COUNT(*) as count FROM student_program");
            if ($result) {
                $count = $result->fetch_assoc()['count'];
                echo "✓ Student_program table accessible: $count records\n";
            } else {
                echo "✗ Error accessing student_program table: " . $db->error . "\n";
            }
            
            // Test the specific query that programs.php uses
            $dept_query = "SELECT id, department_name FROM departments ORDER BY department_name";
            $dept_result = $db->query($dept_query);
            if ($dept_result) {
                $dept_count = $dept_result->num_rows;
                echo "✓ Departments query works: $dept_count departments found\n";
            } else {
                echo "✗ Error in departments query: " . $db->error . "\n";
            }
            
            // Test programs query
            $prog_query = "SELECT p.*, d.department_name FROM programs p LEFT JOIN departments d ON p.department_id = d.id ORDER BY p.program_name";
            $prog_result = $db->query($prog_query);
            if ($prog_result) {
                $prog_count = $prog_result->num_rows;
                echo "✓ Programs query works: $prog_count programs found\n";
            } else {
                echo "✗ Error in programs query: " . $db->error . "\n";
            }
            
        } else {
            echo "✗ Database connection failed: " . $db->connect_error . "\n";
        }
    } else {
        echo "✗ Database connection variable not set\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\nTest completed.\n";
?> 