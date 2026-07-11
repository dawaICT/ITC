<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

echo "Testing header.php query...\n";

// Define IS_SCRIPT to bypass session requirements
define('IS_SCRIPT', true);

try {
    require_once "includes/admin.php";
    echo "✓ Admin file included successfully\n";
    
    if (isset($db) && !$db->connect_error) {
        echo "✓ Database connection successful\n";
        
        // Test the exact query from header.php
        $query = "SELECT * FROM staff INNER JOIN departments ON staff.deptId = departments.deptId WHERE staff.staff_id = 'LVTC23'";
        $result = $db->query($query);
        
        if ($result) {
            if ($result->num_rows > 0) {
                $user = $result->fetch_object();
                echo "✓ Header query works! Found user: " . $user->Fname . " " . $user->Lname . "\n";
                echo "  Department: " . $user->department_name . "\n";
            } else {
                echo "⚠ No matching staff record found for LVTC23\n";
                
                // Test with a different staff_id
                $query2 = "SELECT * FROM staff INNER JOIN departments ON staff.deptId = departments.deptId LIMIT 1";
                $result2 = $db->query($query2);
                if ($result2 && $result2->num_rows > 0) {
                    $user2 = $result2->fetch_object();
                    echo "✓ But join works with other staff: " . $user2->Fname . " " . $user2->Lname . "\n";
                    echo "  Department: " . $user2->department_name . "\n";
                }
            }
        } else {
            echo "✗ Error in header query: " . $db->error . "\n";
        }
        
        // Test the departments query from programs.php
        $dept_query = "SELECT id, department_name FROM departments ORDER BY department_name";
        $dept_result = $db->query($dept_query);
        if ($dept_result) {
            echo "✓ Departments query works: " . $dept_result->num_rows . " departments found\n";
        } else {
            echo "✗ Error in departments query: " . $db->error . "\n";
        }
        
        // Test the programs query from programs.php
        $prog_query = "SELECT p.*, d.department_name FROM programs p LEFT JOIN departments d ON p.department_id = d.id ORDER BY p.program_name";
        $prog_result = $db->query($prog_query);
        if ($prog_result) {
            echo "✓ Programs query works: " . $prog_result->num_rows . " programs found\n";
        } else {
            echo "✗ Error in programs query: " . $db->error . "\n";
        }
        
    } else {
        echo "✗ Database connection failed\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\nTest completed.\n";
?> 